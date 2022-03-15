<?php

namespace craftnet\partners;

use Craft;
use craft\base\Element;
use craft\elements\actions\SetStatus;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craftnet\db\Table;
use craftnet\partners\jobs\UpdatePartner;
use craftnet\partners\validators\ModelsValidator;
use craftnet\partners\validators\PartnerSlugValidator;
use yii\helpers\Inflector;

/**
 * Class Partner
 *
 * @property $slug string
 * @property $verificationStartTime \DateTime
 * @package craftnet\partners
 */
class Partner extends Element
{
    // Constants
    // =========================================================================

    const STATUS_DRAFTING = 'statusDrafting';
    const STATUS_PENDING_APPROVAL = 'statusPendingApproval';
    const STATUS_APPROVED = 'statusApproved';
    const STATUS_REJECTED = 'statusRejected';

    const SCENARIO_BASE_INFO = 'scenarioBaseInfo';
    const SCENARIO_LOCATIONS = 'scenarioLocations';
    const SCENARIO_PROJECTS = 'scenarioProjects';

    // Static
    // =========================================================================

    /**
     * @return string
     */
    public static function displayName(): string
    {
        return 'Partner';
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ENABLED => Craft::t('app', 'Enabled'),
            self::STATUS_DISABLED => Craft::t('app', 'Disabled'),
            self::STATUS_ARCHIVED => Craft::t('app', 'Archived'),
        ];
    }

    /**
     * @return PartnerQuery
     */
    public static function find(): ElementQueryInterface
    {
        $partnerQuery = new PartnerQuery(static::class);
        return $partnerQuery;
    }

    protected static function defineSources(string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => 'All Partners',
                'criteria' => ['status' => null],
            ],
        ];

        return $sources;
    }

    protected static function defineActions(string $source = null): array
    {
        return [
            SetStatus::class,
        ];
    }

    protected static function defineSearchableAttributes(): array
    {
        return [
            'businessName',
            'primaryContactName',
            'primaryContactEmail',
            'primaryContactPhone',
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'businessName' => 'Business Name',
            'ownerId' => 'Owner',
            'primaryContactName' => 'Primary Name',
            'primaryContactEmail' => 'Primary Email',
            'primaryContactPhone' => 'Primary Phone',
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return [
            'businessName',
            'ownerId',
        ];
    }

    // Properties
    // =========================================================================

    /**
     * @var bool Whether the element is enabled
     */
    public $enabled = false;

    /**
     * @var int The owner’s user ID
     */
    public $ownerId;

    /**
     * @var int|null
     */
    public $logoAssetId;

    /**
     * @var Asset|null
     */
    protected $_logo;

    /**
     * @var string|null The partner business name
     */
    public $businessName;

    /**
     * @var string|null The partner agency website url
     */
    public $website;

    /**
     * @var bool Partner profile is pending approval
     */
    public $pendingApproval;

    /**
     * @var string
     */
    public $primaryContactName;

    /**
     * @var string
     */
    public $primaryContactEmail;

    /**
     * @var string
     */
    public $primaryContactPhone;

    /**
     * @var string
     */
    public $fullBio;

    /**
     * @var string
     */
    public $shortBio;

    /**
     * @var string|string[]|null
     */
    public $agencySize;

    /**
     * @var bool
     */
    public $hasFullTimeDev;

    /**
     * @var bool
     */
    public $isCraftVerified;

    /**
     * @var bool
     */
    public $isCommerceVerified;

    /**
     * @var bool
     */
    public $isEnterpriseVerified;

    /**
     * @var bool
     */
    public $isRegisteredBusiness;

    /**
     * Line-separated list: areas of expertise.
     * e.g. "Full service", "Design", "Custom Development"
     *
     * @var string
     */
    public $expertise;

    /**
     * @var \DateTime
     */
    protected $_verificationStartDate;

    /**
     * Based on region category titles in craftcms.com:
     *
     * - "North America"
     * - "South America"
     * - "Europe"
     * - "Asia Pacific"
     *
     * @var string
     */
    public $region;

    /**
     * @var string
     */
    public $websiteSlug;

    /**
     * @var array
     */
    private $_capabilities = null;

    /**
     * @var PartnerLocation[]|null
     */
    private $_locations = null;

    /**
     * @var PartnerProject[]|null
     */
    private $_projects = null;

    // Public Methods
    // =========================================================================

    /**
     * Important: Set a scenario on every rule set. Craft ID needs to validate
     * in chunks: basic info, locations, and projects. Validation errors on an
     * unlreated chunk will cause problems.
     *
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = ['ownerId', 'required'];

        $rules[] = [
            'shortBio',
            'string',
            'max' => '130',
            'on' => [
                self::SCENARIO_BASE_INFO,
                self::SCENARIO_DEFAULT,
                self::SCENARIO_LIVE,
            ],
        ];

        $rules[] = [
            'website',
            'url',
            'enableIDN' => true,
            'on' => [
                self::SCENARIO_BASE_INFO,
                self::SCENARIO_DEFAULT,
                self::SCENARIO_LIVE,
            ],
        ];

        $rules[] = [
            'websiteSlug',
            PartnerSlugValidator::class,
            'on' => [
                self::SCENARIO_BASE_INFO,
                self::SCENARIO_LIVE,
            ],
        ];

        $rules[] = [
            [
                'logoAssetId',
                'businessName',
                'primaryContactName',
                'primaryContactEmail',
                'primaryContactPhone',
                'region',
                'capabilities',
                'agencySize',
                'fullBio',
                'shortBio',
                'websiteSlug',
                'website',
            ],
            'required',
            'on' => [
                self::SCENARIO_BASE_INFO,
                self::SCENARIO_LIVE,
            ],
        ];

        // When submitting from Craft ID, these requirements
        // must apply to the business
        $rules[] = [
            ['isRegisteredBusiness', 'hasFullTimeDev'],
            'required',
            'strict' => true,
            'requiredValue' => true,
            'message' => '{attribute} is required for consideration',
            'on' => [
                self::SCENARIO_BASE_INFO,
                self::SCENARIO_LIVE,
            ],
        ];

        // Always validate locations
        $rules[] = [
            'locations',
            ModelsValidator::class,
            'message' => 'Location errors found',
            'on' => [
                self::SCENARIO_DEFAULT,
                self::SCENARIO_LIVE,
                self::SCENARIO_LOCATIONS,
            ],
        ];

        // Always validate projects
        $rules[] = [
            'projects',
            ModelsValidator::class,
            'message' => 'Project errors found',
            'on' => [
                self::SCENARIO_DEFAULT,
                self::SCENARIO_LIVE,
                self::SCENARIO_PROJECTS,
            ],
        ];

        $rules[] = [
            'primaryContactEmail',
            'email',
            'enableIDN' => true,
            'on' => [
                self::SCENARIO_BASE_INFO,
                self::SCENARIO_DEFAULT,
                self::SCENARIO_LIVE,
            ],
        ];

        return $rules;
    }

    /**
     * @return bool
     * @throws \yii\db\Exception
     */
    public function afterDelete()
    {
        parent::afterDelete();

        Craft::$app->getDb()->createCommand()
            ->delete(Table::PARTNERS, ['id' => $this->id])
            ->execute();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew)
    {
        switch ($this->getScenario()) {
            // Only save basic Partner (Craft ID)
            case self::SCENARIO_BASE_INFO:
                $this->saveBaseInfo($isNew);
                break;

            // Only save locations (Craft ID)
            case self::SCENARIO_LOCATIONS:
                $this->saveLocations();
                break;

            // Only save projects (Craft ID)
            case self::SCENARIO_PROJECTS:
                $this->saveProjects();
                break;

            // Else save it all
            default:
                $this->saveBaseInfo($isNew);
                $this->saveLocations();
                $this->saveProjects();
                break;
        }

        // Send it to craftcom?
        if (App::env('CRAFTCOM_PARTNER_ENDPOINT')) {
            Queue::push(new UpdatePartner([
                'partnerId' => $this->id,
            ]));
        }
    }

    /**
     * Saves everything but locations and projects
     *
     * @param bool $isNew
     * @throws \yii\db\Exception
     */
    protected function saveBaseInfo(bool $isNew)
    {
        $partnerData = $this->getAttributes([
            'ownerId',
            'logoAssetId',
            'businessName',
            'region',
            'primaryContactName',
            'primaryContactEmail',
            'primaryContactPhone',
            'fullBio',
            'shortBio',
            'agencySize',
            'hasFullTimeDev',
            'isCraftVerified',
            'isCommerceVerified',
            'isEnterpriseVerified',
            'verificationStartDate',
            'isRegisteredBusiness',
            'expertise',
            'websiteSlug',
            'website',
        ]);

        if ($partnerData['verificationStartDate'] instanceof \DateTime) {
            $partnerData['verificationStartDate'] = $partnerData['verificationStartDate']->format('Y-m-d');
        }

        if ($isNew) {
            $partnerData['id'] = $this->id;
        }

        $db = Craft::$app->getDb();

        if ($isNew) {
            $db->createCommand()
                ->insert(Table::PARTNERS, $partnerData)
                ->execute();
        } else {
            $db->createCommand()
                ->update(Table::PARTNERS, $partnerData, ['id' => $this->id])
                ->execute();
        }

        // Capabilities

        $db->createCommand()
            ->delete(Table::PARTNERS_PARTNERCAPABILITIES, ['partnerId' => $this->id])
            ->execute();

        if (is_array($this->_capabilities) && count($this->_capabilities) > 0) {
            $partnerId = $this->id;
            $rows = [];

            foreach ($this->_capabilities as $id => $title) {
                $rows[] = [$partnerId, $id];
            }

            $db->createCommand()
                ->batchInsert(
                    Table::PARTNERS_PARTNERCAPABILITIES,
                    ['partnerId', 'partnercapabilitiesId'],
                    $rows,
                    false
                )
                ->execute();
        }
    }

    /**
     * Saves locations
     */
    protected function saveLocations()
    {
        $this->_saveOneToManyRelations($this->_locations ?? [], Table::PARTNERLOCATIONS);
    }

    /**
     * Saves projects
     *
     * @throws \yii\db\Exception
     */
    protected function saveProjects()
    {
        $projects = $this->_projects ?? [];

        $this->_saveOneToManyRelations($projects, Table::PARTNERPROJECTS, true, ['screenshots']);

        foreach ($projects as $project) {
            $this->_saveProjectScreenshots($project);
        }
    }

    /**
     * Validate locations.
     *
     * @inheritdoc
     */
    public function afterValidate()
    {
        if ($this->hasErrors('logoAssetId')) {
            // The only error is that it's required
            // "logo" is used in Craft ID
            $this->addError('logo', 'Logo is required.');
        }

        $scenario = $this->getScenario();

        if ($scenario === self::SCENARIO_LIVE) {
            if (count($this->_locations) === 0) {
                $this->addError('locations', 'Please provide a location');
            }

            if (count($this->_projects) < 5) {
                $this->addError('projects', 'Please provide 5 projects');
            }

            if (count($this->_projects) > 8) {
                $this->addError('projects', 'Please limit to 8 projects');
            }
        }

        return parent::afterValidate();
    }

    /**
     * @return Asset|null
     */
    public function getLogo()
    {
        if (!isset($this->_logo) && (bool)$this->logoAssetId) {
            $this->_logo = Craft::$app->getAssets()->getAssetById($this->logoAssetId);
        }

        return $this->_logo;
    }

    /**
     * @param Asset|null $logo
     */
    public function setLogo(Asset $logo = null)
    {
        if ($logo) {
            $this->_logo = $logo;
            $this->logoAssetId = $logo->id;
        } else {
            $this->_logo = null;
            $this->logoAssetId = null;
        }
    }

    /**
     * @return null|string
     */
    public function getCpEditUrl()
    {
        /** @noinspection PhpUndefinedClassInspection */
        $slug = Inflector::slug($this->businessName);

        return UrlHelper::cpUrl("partners/{$this->id}-{$slug}");
    }

    /**
     * Capabilities related to this Partner as {id: title}
     * ```
     * [
     *   1 => 'Commerce',
     *   4 => 'Contract Work',
     * ]
     * ```
     *
     * @return array
     */
    public function getCapabilities()
    {
        // New Partner instance
        if ($this->id === null) {
            $this->_capabilities = [];
        }

        // Existing Partner instance without capabilities set yet
        if ($this->_capabilities === null) {
            $this->_capabilities = (new PartnerCapabilitiesQuery())
                ->partner($this)
                ->asIndexedTitles()
                ->all();
        }

        return $this->_capabilities;
    }

    /**
     * @param array $capabilities An array of ids, or associative array of `id => title`
     */
    public function setCapabilities($capabilities)
    {
        $this->_capabilities = PartnerService::getInstance()->normalizeCapabilities($capabilities);
    }

    /**
     * @return array
     */
    public function getLocations()
    {
        // New Partner instance
        if ($this->id === null) {
            $this->_locations = [];
        }

        // Existing Partner instance without locations set yet
        if ($this->_locations === null) {
            $result = (new PartnerLocationsQuery())
                ->partner($this->id)
                ->all();

            $this->setLocations($result);
        }

        return $this->_locations;
    }

    /**
     * Sets the `locations` attribute to a list of PartnerLocation
     * instances given an array of models or data arrays suitable for
     * PartnerLocation instantiation.
     *
     * @param array $locations
     */
    public function setLocations(array $locations)
    {
        $this->_locations = PartnerService::getInstance()->normalizeLocations($locations, $this);
    }

    /**
     * @param array $locations
     */
    public function setLocationsFromPost($locations = [])
    {
        foreach ($locations as $id => &$location) {
            if (substr($id, 0, 3) !== 'new') {
                $location['id'] = $id;
            }
        }

        $this->setLocations($locations);
    }

    /**
     * @return array
     */
    public function getProjects(): array
    {
        // New Partner instance
        if ($this->id === null) {
            $this->_projects = [];
        }

        // Existing Partner instance without projects set yet
        if ($this->_projects === null) {
            $projects = (new PartnerProjectsQuery())
                ->partner($this->id)
                ->all();

            $this->setProjects($projects, true);
        }

        return $this->_projects;
    }

    /**
     * Sets the `projects` attribute to a list of PartnerProject
     * instances given an array of models or data arrays suitable for
     * PartnerProject instantiation.
     *
     * @param array $projects
     * @param bool $eagerLoad
     */
    public function setProjects(array $projects, $eagerLoad = false)
    {
        $this->_projects = PartnerService::getInstance()->normalizeProjects($projects, $this, $eagerLoad);
    }

    /**
     * @param array $projects
     */
    public function setProjectsFromPost($projects = [])
    {
        foreach ($projects as $id => &$project) {
            if (substr($id, 0, 3) !== 'new') {
                $project['id'] = $id;
            }
        }

        $projects = PartnerService::getInstance()->normalizeProjects($projects, $this);
        PartnerService::getInstance()->ensureProjectScreenshotAssets($projects);

        $this->_projects = $projects;
    }

    /**
     * @return \craft\elements\User|null
     */
    public function getOwner()
    {
        return Craft::$app->getUsers()->getUserById($this->ownerId);
    }

    /**
     * Generic method to save one-to-many relations like projects and locations.
     *
     * @param PartnerLocation[]|PartnerProject[] $models
     * @param string $table
     * @param bool $prune Prune rows not belonging to `$models`
     * @param array $without Attributes to exclude
     */
    private function _saveOneToManyRelations(array $models, string $table, bool $prune = true, array $without = [])
    {
        $db = Craft::$app->getDb();
        $savedIds = [];
        $without = array_unique(array_merge($without, ['dateCreated', 'dateUpdated', 'uid']));

        $key = 0;

        foreach ($models as $model) {
            $model->partnerId = $this->id;

            if (!$model->id) {
                $data = $model->getAttributes(null, array_merge($without, ['id']));

                if ($table === Table::PARTNERPROJECTS) {
                    $data['sortOrder'] = $key;
                }

                $db->createCommand()
                    ->insert($table, $data)
                    ->execute();

                $model->id = (int)$db->getLastInsertID();
                $savedIds[] = $model->id;
            } else {
                $data = $model->getAttributes(null, $without);

                if ($table === Table::PARTNERPROJECTS) {
                    $data['sortOrder'] = $key;
                }

                $db->createCommand()
                    ->update($table, $data, 'id=:id', [':id' => $data['id']], true)
                    ->execute();

                $savedIds[] = $model->id;
            }

            $key++;
        }

        if ($prune) {
            $condition = ['AND', ['partnerId' => $this->id]];

            if (count($savedIds) !== 0) {
                $condition[] = ['not in', 'id', $savedIds];
            }

            $db->createCommand()
                ->delete($table, $condition)
                ->execute();
        }
    }

    /**
     * @return \DateTime|null
     */
    public function getVerificationStartDate()
    {
        return $this->_verificationStartDate;
    }

    /**
     * @param string|int|array|null The value that should be converted to a DateTime object.
     * @throws \Exception
     */
    public function setVerificationStartDate($value)
    {
        $dateTime = DateTimeHelper::toDateTime($value, true);
        // Force possible `false` to `null`
        $this->_verificationStartDate = $dateTime ?: null;
    }

    /**
     * @param PartnerProject $project
     * @throws \yii\db\Exception
     */
    private function _saveProjectScreenshots($project)
    {
        $db = Craft::$app->getDb();

        $db->createCommand()
            ->delete(Table::PARTNERPROJECTSCREENSHOTS, ['projectId' => $project->id])
            ->execute();

        if (count($project->screenshots) === 0) {
            return;
        }

        $columns = ['projectId', 'assetId', 'sortOrder'];
        $rows = [];

        foreach ($project->getScreenshotIds() as $key => $assetId) {
            $rows[] = [$project->id, $assetId, $key];
        }

        $db->createCommand()
            ->batchInsert(Table::PARTNERPROJECTSCREENSHOTS, $columns, $rows)
            ->execute();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->businessName ?? $this->getOwner()->getName();
    }
}
