<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\services;

use Craft;
use craft\app\enums\ColumnType;
use craft\app\enums\ElementType;
use craft\app\enums\EmailerType;
use craft\app\enums\LogLevel;
use craft\app\enums\SectionType;
use craft\app\errors\Exception;
use craft\app\helpers\DateTimeHelper;
use craft\app\helpers\DbHelper;
use craft\app\helpers\IOHelper;
use craft\app\models\Entry as EntryModel;
use craft\app\models\Field as FieldModel;
use craft\app\models\FieldGroup as FieldGroupModel;
use craft\app\models\Info as InfoModel;
use craft\app\models\Section as SectionModel;
use craft\app\models\SectionLocale as SectionLocaleModel;
use craft\app\models\TagGroup as TagGroupModel;
use craft\app\models\User as UserModel;
use craft\app\records\Migration as MigrationRecord;
use yii\base\Component;

/**
 * Class Install service.
 *
 * An instance of the Install service is globally accessible in Craft via [[Application::install `Craft::$app->install`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Install extends Component
{
	// Properties
	// =========================================================================

	/**
	 * @var
	 */
	private $_user;

	// Public Methods
	// =========================================================================

	/**
	 * Installs Craft!
	 *
	 * @param array $inputs
	 *
	 * @throws Exception|\Exception
	 * @return null
	 */
	public function run($inputs)
	{
		Craft::$app->config->maxPowerCaptain();

		if (Craft::$app->isInstalled())
		{
			throw new Exception(Craft::t('@@@appName@@@ is already installed.'));
		}

		// Set the language to the desired locale
		Craft::$app->setLanguage($inputs['locale']);

		$records = $this->findInstallableRecords();

		// Start the transaction
		$transaction = Craft::$app->db->getCurrentTransaction() === null ? Craft::$app->db->beginTransaction() : null;
		try
		{
			Craft::log('Installing Craft.');

			// Create the tables
			$this->_createTablesFromRecords($records);
			$this->_createForeignKeysFromRecords($records);

			$this->_createContentTable();
			$this->_createRelationsTable();
			$this->_createShunnedMessagesTable();
			$this->_createSearchIndexTable();
			$this->_createTemplateCacheTables();
			$this->_createAndPopulateInfoTable($inputs);

			$this->_createAssetTransformIndexTable();
			$this->_createRackspaceAccessTable();
			$this->_createDeprecationErrorsTable();

			$this->_populateMigrationTable();

			Craft::log('Committing the transaction.');
			if ($transaction !== null)
			{
				$transaction->commit();
			}
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}

		// Craft, you are installed now.
		Craft::$app->setIsInstalled();

		$this->_addLocale($inputs['locale']);
		$this->_addUser($inputs);

		if (!Craft::$app->getRequest()->getIsConsoleRequest())
		{
			$this->_logUserIn($inputs);
		}

		$this->_saveDefaultMailSettings($inputs['email'], $inputs['siteName']);
		$this->_createDefaultContent($inputs);

		Craft::log('Finished installing Craft.');
	}

	/**
	 * Finds installable records from the models folder.
	 *
	 * @return array
	 */
	public function findInstallableRecords()
	{
		$records = [];

		$recordsFolder = Craft::$app->path->getAppPath().'records/';
		$recordFiles = IOHelper::getFolderContents($recordsFolder, false, ".*Record\.php$");

		foreach ($recordFiles as $file)
		{
			if (IOHelper::fileExists($file))
			{
				$fileName = IOHelper::getFileName($file, false);
				$class = __NAMESPACE__.'\\'.$fileName;

				// Ignore abstract classes and interfaces
				$ref = new \ReflectionClass($class);
				if ($ref->isAbstract() || $ref->isInterface())
				{
					Craft::log("Skipping record {$file} because it’s abstract or an interface.", LogLevel::Warning);
					continue;
				}

				$obj = new $class('install');

				if (method_exists($obj, 'createTable'))
				{
					$records[] = $obj;
				}
				else
				{
					Craft::log("Skipping record {$file} because it doesn’t have a createTable() method.", LogLevel::Warning);
				}
			}
			else
			{
				Craft::log("Skipping record {$file} because it doesn’t exist.", LogLevel::Warning);
			}
		}

		return $records;
	}

	// Private Methods
	// =========================================================================

	/**
	 * Creates the tables as defined in the records.
	 *
	 * @param $records
	 *
	 * @return null
	 */
	private function _createTablesFromRecords($records)
	{
		foreach ($records as $record)
		{
			Craft::log('Creating table for record:'. get_class($record));
			$record->createTable();
		}
	}

	/**
	 * Creates the foreign keys as defined in the records.
	 *
	 * @param $records
	 *
	 * @return null
	 */
	private function _createForeignKeysFromRecords($records)
	{
		foreach ($records as $record)
		{
			Craft::log('Adding foreign keys for record:'. get_class($record));
			$record->addForeignKeys();
		}
	}

	/**
	 * Creates the content table.
	 *
	 * @return null
	 */
	private function _createContentTable()
	{
		Craft::log('Creating the content table.');

		Craft::$app->db->createCommand()->createTable('content', [
			'elementId' => ['column' => ColumnType::Int, 'null' => false],
			'locale'    => ['column' => ColumnType::Locale, 'null' => false],
			'title'     => ['column' => ColumnType::Varchar],
		]);

		Craft::$app->db->createCommand()->createIndex('content', 'elementId,locale', true);
		Craft::$app->db->createCommand()->createIndex('content', 'title');
		Craft::$app->db->createCommand()->addForeignKey('content', 'elementId', 'elements', 'id', 'CASCADE', null);
		Craft::$app->db->createCommand()->addForeignKey('content', 'locale', 'locales', 'locale', 'CASCADE', 'CASCADE');

		Craft::log('Finished creating the content table.');
	}

	/**
	 * Creates the relations table.
	 *
	 * @return null
	 */
	private function _createRelationsTable()
	{
		Craft::log('Creating the relations table.');

		Craft::$app->db->createCommand()->createTable('relations', [
			'fieldId'      => ['column' => ColumnType::Int, 'null' => false],
			'sourceId'     => ['column' => ColumnType::Int, 'null' => false],
			'sourceLocale' => ['column' => ColumnType::Locale],
			'targetId'     => ['column' => ColumnType::Int, 'null' => false],
			'sortOrder'    => ['column' => ColumnType::SmallInt],
		]);

		Craft::$app->db->createCommand()->createIndex('relations', 'fieldId,sourceId,sourceLocale,targetId', true);
		Craft::$app->db->createCommand()->addForeignKey('relations', 'fieldId', 'fields', 'id', 'CASCADE');
		Craft::$app->db->createCommand()->addForeignKey('relations', 'sourceId', 'elements', 'id', 'CASCADE');
		Craft::$app->db->createCommand()->addForeignKey('relations', 'sourceLocale', 'locales', 'locale', 'CASCADE', 'CASCADE');
		Craft::$app->db->createCommand()->addForeignKey('relations', 'targetId', 'elements', 'id', 'CASCADE');

		Craft::log('Finished creating the relations table.');
	}

	/**
	 * Creates the shunnedmessages table.
	 *
	 * @return null
	 */
	private function _createShunnedMessagesTable()
	{
		Craft::log('Creating the shunnedmessages table.');

		Craft::$app->db->createCommand()->createTable('shunnedmessages', [
			'userId'     => ['column' => ColumnType::Int, 'null' => false],
			'message'    => ['column' => ColumnType::Varchar, 'null' => false],
			'expiryDate' => ['column' => ColumnType::DateTime],
		]);
		Craft::$app->db->createCommand()->createIndex('shunnedmessages', 'userId,message', true);
		Craft::$app->db->createCommand()->addForeignKey('shunnedmessages', 'userId', 'users', 'id', 'CASCADE');

		Craft::log('Finished creating the shunnedmessages table.');
	}

	/**
	 * Creates the searchindex table.
	 *
	 * @return null
	 */
	private function _createSearchIndexTable()
	{
		Craft::log('Creating the searchindex table.');

		// Taking the scenic route here so we can get to MysqlSchema's $engine argument
		$table = Craft::$app->db->addTablePrefix('searchindex');

		$columns = [
			'elementId' => DbHelper::generateColumnDefinition(['column' => ColumnType::Int, 'null' => false]),
			'attribute' => DbHelper::generateColumnDefinition(['column' => ColumnType::Varchar, 'maxLength' => 25, 'null' => false]),
			'fieldId'   => DbHelper::generateColumnDefinition(['column' => ColumnType::Int, 'null' => false]),
			'locale'    => DbHelper::generateColumnDefinition(['column' => ColumnType::Locale, 'null' => false]),
			'keywords'  => DbHelper::generateColumnDefinition(['column' => ColumnType::Text, 'null' => false]),
		];

		Craft::$app->db->createCommand()->setText(Craft::$app->db->getSchema()->createTable($table, $columns, null, 'MyISAM'))->execute();

		// Give it a composite primary key
		Craft::$app->db->createCommand()->addPrimaryKey('searchindex', 'elementId,attribute,fieldId,locale');

		// Add the FULLTEXT index on `keywords`
		Craft::$app->db->createCommand()->setText('CREATE FULLTEXT INDEX ' .
			Craft::$app->db->quoteTableName(Craft::$app->db->getIndexName('searchindex', 'keywords')).' ON ' .
			Craft::$app->db->quoteTableName($table).' ' .
			'('.Craft::$app->db->quoteColumnName('keywords').')'
		)->execute();

		Craft::log('Finished creating the searchindex table.');
	}

	/**
	 * Creates the template cache tables.
	 *
	 * @return null
	 */
	private function _createTemplateCacheTables()
	{
		Craft::log('Creating the templatecaches table.');

		Craft::$app->db->createCommand()->createTable('templatecaches', [
			'cacheKey'   => ['column' => ColumnType::Varchar, 'null' => false],
			'locale'     => ['column' => ColumnType::Locale, 'null' => false],
			'path'       => ['column' => ColumnType::Varchar],
			'expiryDate' => ['column' => ColumnType::DateTime, 'null' => false],
			'body'       => ['column' => ColumnType::MediumText, 'null' => false],
		], null, true, false);

		Craft::$app->db->createCommand()->createIndex('templatecaches', 'expiryDate,cacheKey,locale,path');
		Craft::$app->db->createCommand()->addForeignKey('templatecaches', 'locale', 'locales', 'locale', 'CASCADE', 'CASCADE');

		Craft::log('Finished creating the templatecaches table.');
		Craft::log('Creating the templatecacheelements table.');

		Craft::$app->db->createCommand()->createTable('templatecacheelements', [
			'cacheId'   => ['column' => ColumnType::Int, 'null' => false],
			'elementId' => ['column' => ColumnType::Int, 'null' => false],
		], null, false, false);

		Craft::$app->db->createCommand()->addForeignKey('templatecacheelements', 'cacheId', 'templatecaches', 'id', 'CASCADE', null);
		Craft::$app->db->createCommand()->addForeignKey('templatecacheelements', 'elementId', 'elements', 'id', 'CASCADE', null);

		Craft::log('Finished creating the templatecacheelements table.');
		Craft::log('Creating the templatecachecriteria table.');

		Craft::$app->db->createCommand()->createTable('templatecachecriteria', [
			'cacheId'  => ['column' => ColumnType::Int, 'null' => false],
			'type'     => ['column' => ColumnType::Varchar, 'maxLength' => 150, 'null' => false],
			'criteria' => ['column' => ColumnType::Text, 'null' => false],
		], null, true, false);

		Craft::$app->db->createCommand()->addForeignKey('templatecachecriteria', 'cacheId', 'templatecaches', 'id', 'CASCADE', null);
		Craft::$app->db->createCommand()->createIndex('templatecachecriteria', 'type');

		Craft::log('Finished creating the templatecachecriteria table.');
	}

	/**
	 * Populates the info table with install and environment information.
	 *
	 * @param $inputs
	 *
	 * @throws Exception
	 *
	 * @return null
	 */
	private function _createAndPopulateInfoTable($inputs)
	{
		Craft::log('Creating the info table.');

		Craft::$app->db->createCommand()->createTable('info', [
			'version'       => ['column' => ColumnType::Varchar,  'length' => 15,    'null' => false],
			'build'         => ['column' => ColumnType::Int,      'length' => 11,    'unsigned' => true, 'null' => false],
			'schemaVersion' => ['column' => ColumnType::Varchar,  'length' => 15,    'null' => false],
			'releaseDate'   => ['column' => ColumnType::DateTime, 'null' => false],
			'edition'       => ['column' => ColumnType::TinyInt,  'length' => 1,     'unsigned' => true, 'default' => 0, 'null' => false],
			'siteName'      => ['column' => ColumnType::Varchar,  'length' => 100,   'null' => false],
			'siteUrl'       => ['column' => ColumnType::Varchar,  'length' => 255,   'null' => false],
			'timezone'      => ['column' => ColumnType::Varchar,  'length' => 30],
			'on'            => ['column' => ColumnType::TinyInt,  'length' => 1,     'unsigned' => true, 'default' => 0, 'null' => false],
			'maintenance'   => ['column' => ColumnType::TinyInt,  'length' => 1,     'unsigned' => true, 'default' => 0, 'null' => false],
			'track'         => ['column' => ColumnType::Varchar,  'maxLength' => 40, 'required' => true],
		]);

		Craft::log('Finished creating the info table.');

		Craft::log('Populating the info table.');

		$info = new InfoModel([
			'version'       => CRAFT_VERSION,
			'build'         => CRAFT_BUILD,
			'schemaVersion' => CRAFT_SCHEMA_VERSION,
			'releaseDate'   => CRAFT_RELEASE_DATE,
			'edition'       => 0,
			'siteName'      => $inputs['siteName'],
			'siteUrl'       => $inputs['siteUrl'],
			'on'            => 1,
			'maintenance'   => 0,
			'track'         => '@@@track@@@',
		]);

		if (Craft::$app->saveInfo($info))
		{
			Craft::log('Info table populated successfully.');
		}
		else
		{
			Craft::log('Could not populate the info table.', LogLevel::Error);
			throw new Exception(Craft::t('There was a problem saving to the info table:').$this->_getFlattenedErrors($info->getErrors()));
		}
	}

	/**
	 * Creates the Rackspace access table.
	 *
	 * @return null
	 */
	private function _createRackspaceAccessTable()
	{
		Craft::log('Creating the Rackspace access table.');

		Craft::$app->db->createCommand()->createTable('rackspaceaccess', [
			'connectionKey'  => ['column' => ColumnType::Varchar, 'required' => true],
			'token'          => ['column' => ColumnType::Varchar, 'required' => true],
			'storageUrl'     => ['column' => ColumnType::Varchar, 'required' => true],
			'cdnUrl'         => ['column' => ColumnType::Varchar, 'required' => true],
		]);

		Craft::$app->db->createCommand()->createIndex('rackspaceaccess', 'connectionKey', true);
		Craft::log('Finished creating the Rackspace access table.');
	}

	/**
	 * Creates the deprecationerrors table for The Deprecator (tm).
	 *
	 * @return null
	 */
	private function _createDeprecationErrorsTable()
	{
		Craft::log('Creating the deprecationerrors table.');

		Craft::$app->db->createCommand()->createTable('deprecationerrors', [
			'key'               => ['column' => ColumnType::Varchar, 'null' => false],
			'fingerprint'       => ['column' => ColumnType::Varchar, 'null' => false],
			'lastOccurrence'    => ['column' => ColumnType::DateTime, 'null' => false],
			'file'              => ['column' => ColumnType::Varchar, 'null' => false],
			'line'              => ['column' => ColumnType::SmallInt, 'unsigned' => true, 'null' => false],
			'class'             => ['column' => ColumnType::Varchar],
			'method'            => ['column' => ColumnType::Varchar],
			'template'          => ['column' => ColumnType::Varchar],
			'templateLine'      => ['column' => ColumnType::SmallInt, 'unsigned' => true],
			'message'           => ['column' => ColumnType::Varchar],
			'traces'            => ['column' => ColumnType::Text],
		]);

		Craft::$app->db->createCommand()->createIndex('deprecationerrors', 'key,fingerprint', true);
		Craft::log('Finished creating the deprecationerrors table.');
	}

	/**
	 * Create the Asset Transform Index table.
	 *
	 * @return null
	 */
	private function _createAssetTransformIndexTable()
	{
		Craft::log('Creating the Asset transform index table.');

		Craft::$app->db->createCommand()->createTable('assettransformindex', [
			'fileId'       => ['maxLength' => 11, 'column' => ColumnType::Int, 'required' => true],
			'filename'     => ['maxLength' => 255, 'column' => ColumnType::Varchar, 'required' => false],
			'format'       => ['maxLength' => 255, 'column' => ColumnType::Varchar, 'required' => false],
			'location'     => ['maxLength' => 255, 'column' => ColumnType::Varchar, 'required' => true],
			'sourceId'     => ['maxLength' => 11, 'column' => ColumnType::Int, 'required' => false],
			'fileExists'   => ['column' => ColumnType::Bool],
			'inProgress'   => ['column' => ColumnType::Bool],
			'dateIndexed'  => ['column' => ColumnType::DateTime],
		]);

		Craft::$app->db->createCommand()->createIndex('assettransformindex', 'sourceId, fileId, location');
		Craft::log('Finished creating the Asset transform index table.');
	}

	/**
	 * Populates the migrations table with the base migration plus any existing ones from app/migrations.
	 *
	 * @throws Exception
	 * @return null
	 */
	private function _populateMigrationTable()
	{
		$migrations = [];

		// Add the base one.
		$migration = new MigrationRecord();
		$migration->version = Craft::$app->migrations->getBaseMigration();
		$migration->applyTime = DateTimeHelper::currentUTCDateTime();
		$migrations[] = $migration;

		$migrationsFolder = Craft::$app->path->getAppPath().'migrations/';
		$migrationFiles = IOHelper::getFolderContents($migrationsFolder, false, "(m(\d{6}_\d{6})_.*?)\.php");

		if ($migrationFiles)
		{
			foreach ($migrationFiles as $file)
			{
				if (IOHelper::fileExists($file))
				{
					$migration = new MigrationRecord();
					$migration->version = IOHelper::getFileName($file, false);
					$migration->applyTime = DateTimeHelper::currentUTCDateTime();

					$migrations[] = $migration;
				}
			}

			foreach ($migrations as $migration)
			{
				if (!$migration->save())
				{
					Craft::log('Could not populate the migration table.', LogLevel::Error);
					throw new Exception(Craft::t('There was a problem saving to the migrations table: ').$this->_getFlattenedErrors($migration->getErrors()));
				}
			}
		}

		Craft::log('Migration table populated successfully.');
	}

	/**
	 * Adds the initial locale to the database.
	 *
	 * @param string $locale
	 *
	 * @return null
	 */
	private function _addLocale($locale)
	{
		Craft::log('Adding locale.');
		Craft::$app->db->createCommand()->insert('locales', ['locale' => $locale, 'sortOrder' => 1]);
		Craft::log('Locale added successfully.');
	}

	/**
	 * Adds the initial user to the database.
	 *
	 * @param $inputs
	 *
	 * @throws Exception
	 * @return UserModel
	 */
	private function _addUser($inputs)
	{
		Craft::log('Creating user.');

		$this->_user = new UserModel();

		$this->_user->username = $inputs['username'];
		$this->_user->newPassword = $inputs['password'];
		$this->_user->email = $inputs['email'];
		$this->_user->admin = true;

		if (Craft::$app->users->saveUser($this->_user))
		{
			Craft::log('User created successfully.');
		}
		else
		{
			Craft::log('Could not create the user.', LogLevel::Error);
			throw new Exception(Craft::t('There was a problem creating the user:').$this->_getFlattenedErrors($this->_user->getErrors()));
		}
	}

	/**
	 * Attempts to log in the given user.
	 *
	 * @param array $inputs
	 *
	 * @return null
	 */
	private function _logUserIn($inputs)
	{
		Craft::log('Logging in user.');

		if (Craft::$app->getUser()->login($inputs['username'], $inputs['password']))
		{
			Craft::log('User logged in successfully.');
		}
		else
		{
			Craft::log('Could not log the user in.', LogLevel::Warning);
		}
	}

	/**
	 * Saves some default mail settings for the site.
	 *
	 * @param string $email
	 * @param string $siteName
	 *
	 * @return null
	 */
	private function _saveDefaultMailSettings($email, $siteName)
	{
		Craft::log('Saving default mail settings.');

		$settings = [
			'protocol'     => EmailerType::Php,
			'emailAddress' => $email,
			'senderName'   => $siteName
		];

		if (Craft::$app->systemSettings->saveSettings('email', $settings))
		{
			Craft::log('Default mail settings saved successfully.');
		}
		else
		{
			Craft::log('Could not save default email settings.', LogLevel::Warning);
		}
	}

	/**
	 * Creates initial database content for the install.
	 *
	 * @param $inputs
	 *
	 * @return null
	 */
	private function _createDefaultContent($inputs)
	{
		// Default tag group

		Craft::log('Creating the Default tag group.');

		$tagGroup = new TagGroupModel();
		$tagGroup->name   = Craft::t('Default');
		$tagGroup->handle = 'default';

		// Save it
		if (Craft::$app->tags->saveTagGroup($tagGroup))
		{
			Craft::log('Default tag group created successfully.');
		}
		else
		{
			Craft::log('Could not save the Default tag group.', LogLevel::Warning);
		}

		// Default field group

		Craft::log('Creating the Default field group.');

		$group = new FieldGroupModel();
		$group->name = Craft::t('Default');

		if (Craft::$app->fields->saveGroup($group))
		{
			Craft::log('Default field group created successfully.');
		}
		else
		{
			Craft::log('Could not save the Default field group.', LogLevel::Warning);
		}

		// Body field

		Craft::log('Creating the Body field.');

		$bodyField = new FieldModel();
		$bodyField->groupId      = $group->id;
		$bodyField->name         = Craft::t('Body');
		$bodyField->handle       = 'body';
		$bodyField->translatable = true;
		$bodyField->type         = 'RichText';
		$bodyField->settings = [
			'configFile' => 'Standard.json',
			'columnType' => ColumnType::Text,
		];

		if (Craft::$app->fields->saveField($bodyField))
		{
			Craft::log('Body field created successfully.');
		}
		else
		{
			Craft::log('Could not save the Body field.', LogLevel::Warning);
		}

		// Tags field

		Craft::log('Creating the Tags field.');

		$tagsField = new FieldModel();
		$tagsField->groupId      = $group->id;
		$tagsField->name         = Craft::t('Tags');
		$tagsField->handle       = 'tags';
		$tagsField->type         = 'Tags';
		$tagsField->settings = [
			'source' => 'taggroup:'.$tagGroup->id
		];

		if (Craft::$app->fields->saveField($tagsField))
		{
			Craft::log('Tags field created successfully.');
		}
		else
		{
			Craft::log('Could not save the Tags field.', LogLevel::Warning);
		}

		// Homepage single section

		Craft::log('Creating the Homepage single section.');

		$homepageLayout = Craft::$app->fields->assembleLayout(
			[
				Craft::t('Content') => [$bodyField->id]
			],
			[$bodyField->id]
		);

		$homepageLayout->type = ElementType::Entry;

		$homepageSingleSection = new SectionModel();
		$homepageSingleSection->name = Craft::t('Homepage');
		$homepageSingleSection->handle = 'homepage';
		$homepageSingleSection->type = SectionType::Single;
		$homepageSingleSection->hasUrls = false;
		$homepageSingleSection->template = 'index';

		$primaryLocaleId = Craft::$app->i18n->getPrimarySiteLocaleId();
		$locales[$primaryLocaleId] = new SectionLocaleModel([
			'locale'          => $primaryLocaleId,
			'urlFormat'       => '__home__',
		]);

		$homepageSingleSection->setLocales($locales);

		// Save it
		if (Craft::$app->sections->saveSection($homepageSingleSection))
		{
			Craft::log('Homepage single section created successfully.');
		}
		else
		{
			Craft::log('Could not save the Homepage single section.', LogLevel::Warning);
		}

		$homepageEntryTypes = $homepageSingleSection->getEntryTypes();
		$homepageEntryType = $homepageEntryTypes[0];
		$homepageEntryType->hasTitleField = true;
		$homepageEntryType->titleLabel = Craft::t('Title');
		$homepageEntryType->setFieldLayout($homepageLayout);

		if (Craft::$app->sections->saveEntryType($homepageEntryType))
		{
			Craft::log('Homepage single section entry type saved successfully.');
		}
		else
		{
			Craft::log('Could not save the Homepage single section entry type.', LogLevel::Warning);
		}

		// Homepage content

		$vars = [
			'siteName' => ucfirst(Craft::$app->request->getServerName())
		];

		Craft::log('Setting the Homepage content.');

		$criteria = Craft::$app->elements->getCriteria(ElementType::Entry);
		$criteria->sectionId = $homepageSingleSection->id;
		$entryModel = $criteria->first();

		$entryModel->locale = $inputs['locale'];
		$entryModel->getContent()->title = Craft::t('Welcome to {siteName}!', $vars);
		$entryModel->setContentFromPost([
			'body' => '<p>'.Craft::t('It’s true, this site doesn’t have a whole lot of content yet, but don’t worry. Our web developers have just installed the CMS, and they’re setting things up for the content editors this very moment. Soon {siteName} will be an oasis of fresh perspectives, sharp analyses, and astute opinions that will keep you coming back again and again.', $vars).'</p>',
		]);

		// Save the content
		if (Craft::$app->entries->saveEntry($entryModel))
		{
			Craft::log('Homepage an entry to the Homepage single section.');
		}
		else
		{
			Craft::log('Could not save an entry to the Homepage single section.', LogLevel::Warning);
		}

		// News section

		Craft::log('Creating the News section.');

		$newsSection = new SectionModel();
		$newsSection->type     = SectionType::Channel;
		$newsSection->name     = Craft::t('News');
		$newsSection->handle   = 'news';
		$newsSection->hasUrls  = true;
		$newsSection->template = 'news/_entry';

		$newsSection->setLocales([
			$inputs['locale'] => SectionLocaleModel::populateModel([
				'locale'    => $inputs['locale'],
				'urlFormat' => 'news/{postDate.year}/{slug}',
			])
		]);

		if (Craft::$app->sections->saveSection($newsSection))
		{
			Craft::log('News section created successfully.');
		}
		else
		{
			Craft::log('Could not save the News section.', LogLevel::Warning);
		}

		Craft::log('Saving the News entry type.');

		$newsLayout = Craft::$app->fields->assembleLayout(
			[
				Craft::t('Content') => [$bodyField->id, $tagsField->id],
			],
			[$bodyField->id]
		);

		$newsLayout->type = ElementType::Entry;

		$newsEntryTypes = $newsSection->getEntryTypes();
		$newsEntryType = $newsEntryTypes[0];
		$newsEntryType->setFieldLayout($newsLayout);

		if (Craft::$app->sections->saveEntryType($newsEntryType))
		{
			Craft::log('News entry type saved successfully.');
		}
		else
		{
			Craft::log('Could not save the News entry type.', LogLevel::Warning);
		}

		// News entry

		Craft::log('Creating a News entry.');

		$newsEntry = new EntryModel();
		$newsEntry->sectionId  = $newsSection->id;
		$newsEntry->typeId     = $newsEntryType->id;
		$newsEntry->locale     = $inputs['locale'];
		$newsEntry->authorId   = $this->_user->id;
		$newsEntry->enabled    = true;
		$newsEntry->getContent()->title = Craft::t('We just installed Craft!');
		$newsEntry->getContent()->setAttributes([
			'body' => '<p>'
					. Craft::t('Craft is the CMS that’s powering {siteName}. It’s beautiful, powerful, flexible, and easy-to-use, and it’s made by Pixel &amp; Tonic. We can’t wait to dive in and see what it’s capable of!', $vars)
					. '</p><!--pagebreak--><p>'
					. Craft::t('This is even more captivating content, which you couldn’t see on the News index page because it was entered after a Page Break, and the News index template only likes to show the content on the first page.')
					. '</p><p>'
					. Craft::t('Craft: a nice alternative to Word, if you’re making a website.')
					. '</p>',
		]);

		if (Craft::$app->entries->saveEntry($newsEntry))
		{
			Craft::log('News entry created successfully.');
		}
		else
		{
			Craft::log('Could not save the News entry.', LogLevel::Warning);
		}
	}

	/**
	 * Get a flattened list of model errors
	 *
	 * @param array $errors
	 *
	 * @return string
	 */
	private function _getFlattenedErrors($errors)
	{
		$return = '';

		foreach ($errors as $attribute => $attributeErrors)
		{
			$return .= "\n - ".implode("\n - ", $attributeErrors);
		}

		return $return;
	}
}
