<?php

namespace craftnet\plugins;

use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use craftnet\db\Table;
use craft\db\Table as CraftTable;
use craftnet\Module;
use yii\db\Connection;

/**
 * @method Plugin[]|array all($db = null)
 * @method Plugin|array|null one($db = null)
 * @method Plugin|array|null nth(int $n, Connection $db = null)
 */
class PluginQuery extends ElementQuery
{
    /**
     * @var string|string[]|null The handle(s) that the resulting plugins must have.
     */
    public $handle;

    /**
     * @var string|string[]|null The license(s) that the resulting plugins must have.
     */
    public $license;

    /**
     * @var int|int[]|null The category ID(s) that the resulting plugins must have.
     */
    public $categoryId;

    /**
     * @var int|int[]|null The user ID(s) that the resulting plugins’ developers must have.
     */
    public $developerId;

    /**
     * @var int|int[]|null The Composer package ID(s) that the resulting plugins must be associated with.
     */
    public $packageId;

    /**
     * @var bool Whether info about the latest release should be included
     */
    public $withLatestReleaseInfo = false;

    /**
     * @var string|null Craft version the latest release must be compatible with
     */
    public $cmsVersion;

    /**
     * @var string|null Minimum stability the latest release must have
     */
    public $minStability;

    /**
     * @var bool Whether a stable release should be returned if possible
     */
    public $preferStable = true;

    /**
     * @var bool Whether info about the total purchases should be included
     */
    public $withTotalPurchases = false;

    /**
     * @var \DateTime How for back to look for total purchases
     */
    public $totalPurchasesSince;

    /**
     * @inheritdoc
     */
    public function __construct($elementType, array $config = [])
    {
        // Default orderBy
        if (!isset($config['orderBy'])) {
            $config['orderBy'] = Table::PLUGINS . '.name';
        }

        parent::__construct($elementType, $config);
    }

    /**
     * Sets the [[handle]] property.
     *
     * @param string|string[]|null $value The property value
     *
     * @return static self reference
     */
    public function handle($value)
    {
        $this->handle = $value;
        return $this;
    }

    /**
     * Sets the [[license]] property.
     *
     * @param string|string[]|null $value The property value
     *
     * @return static self reference
     */
    public function license($value)
    {
        $this->license = $value;
        return $this;
    }

    /**
     * Sets the [[categoryId]] property.
     *
     * @param int|int[]|null $value The property value
     *
     * @return static self reference
     */
    public function categoryId($value)
    {
        $this->categoryId = $value;
        return $this;
    }

    /**
     * Sets the [[developerId]] property.
     *
     * @param int|int[]|null $value The property value
     *
     * @return static self reference
     */
    public function developerId($value)
    {
        $this->developerId = $value;
        return $this;
    }

    /**
     * Sets the [[packageId]] property.
     *
     * @param int|int[]|null $value The property value
     *
     * @return static self reference
     */
    public function packageId($value)
    {
        $this->packageId = $value;
        return $this;
    }

    /**
     * Sets the [[withLatestReleaseInfo]], [[cmsVersion]], and [[minStability]] properties.
     *
     * @param bool $withLatestReleaseInfo
     * @param string|null $cmsVersion
     * @param string|null $minStability
     * @param bool $preferStable
     * @return static self reference
     */
    public function withLatestReleaseInfo(bool $withLatestReleaseInfo = true, string $cmsVersion = null, string $minStability = null, $preferStable = true)
    {
        $this->withLatestReleaseInfo = $withLatestReleaseInfo;
        $this->cmsVersion = $cmsVersion;
        $this->minStability = $minStability;
        $this->preferStable = $preferStable;
        return $this;
    }

    /**
     * Sets the [[withTotalPurchases]] and [[totalPurchasesSince]] properties.
     *
     * @param bool $withTotalPurchases
     * @param \DateTime|null $since
     * @return static self reference
     */
    public function withTotalPurchases(bool $withTotalPurchases = true, \DateTime $since = null)
    {
        $this->withTotalPurchases = $withTotalPurchases;
        $this->totalPurchasesSince = $since;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if ($this->cmsVersion === '0.0') {
            return false;
        }

        $this->joinElementTable('craftnet_plugins');

        $this->query->select([
            Table::PLUGINS . '.developerId',
            Table::PLUGINS . '.packageId',
            Table::PLUGINS . '.iconId',
            Table::PLUGINS . '.packageName',
            Table::PLUGINS . '.repository',
            Table::PLUGINS . '.name',
            Table::PLUGINS . '.handle',
            Table::PLUGINS . '.license',
            Table::PLUGINS . '.shortDescription',
            Table::PLUGINS . '.longDescription',
            Table::PLUGINS . '.documentationUrl',
            Table::PLUGINS . '.changelogPath',
            Table::PLUGINS . '.activeInstalls',
            Table::PLUGINS . '.pendingApproval',
            Table::PLUGINS . '.keywords',
            Table::PLUGINS . '.dateApproved',
            Table::PLUGINS . '.published',
            Table::PLUGINS . '.abandoned',
            Table::PLUGINS . '.replacementId',
        ]);

        if ($this->handle) {
            $this->subQuery->andWhere(Db::parseParam(Table::PLUGINS . '.handle', $this->handle));
        }

        if ($this->license) {
            $this->subQuery->andWhere(Db::parseParam(Table::PLUGINS . '.license', $this->license));
        }

        if ($this->developerId) {
            $this->subQuery->andWhere(Db::parseParam(Table::PLUGINS . '.developerId', $this->developerId));
        }

        if ($this->packageId) {
            $this->subQuery->andWhere(Db::parseParam(Table::PLUGINS . '.packageId', $this->packageId));
        }

        if ($this->categoryId) {
            $this->subQuery
                ->innerJoin(['pc' => Table::PLUGINCATEGORIES], '[[pc.pluginId]] = [[elements.id]]')
                ->andWhere(Db::parseParam('pc.categoryId', $this->categoryId));
        }

        if ($this->withLatestReleaseInfo) {
            $maxCol = $this->preferStable ? 'stableOrder' : 'order';
            $latestReleaseQuery = (new Query())
                ->select(["max([[s_vo.{$maxCol}]])"])
                ->from(['s_vo' => Table::PLUGINVERSIONORDER])
                ->innerJoin(['s_v' => Table::PACKAGEVERSIONS], '[[s_v.id]] = [[s_vo.versionId]]')
                ->where('[[s_v.packageId]] = [[craftnet_plugins.packageId]]')
                ->groupBy(['s_v.packageId']);

            $packageManager = Module::getInstance()->getPackageManager();

            if ($this->cmsVersion) {
                $cmsRelease = $packageManager->getRelease('craftcms/cms', $this->cmsVersion);
                if (!$cmsRelease) {
                    return false;
                }
                $latestReleaseQuery
                    ->innerJoin(['s_vc' => Table::PLUGINVERSIONCOMPAT], '[[s_vc.pluginVersionId]] = [[s_v.id]]')
                    ->andWhere(['s_vc.cmsVersionId' => $cmsRelease->id]);
            }

            if ($this->minStability) {
                $latestReleaseQuery->andWhere([
                    's_v.stability' => $packageManager->getStabilities($this->minStability),
                ]);
            }

            $this->subQuery
                ->addSelect([
                    'latestVersionId' => 'v.id',
                    'latestVersion' => 'v.version',
                    'latestVersionTime' => 'v.time',
                ])
                ->innerJoin(['v' => Table::PACKAGEVERSIONS], '[[v.packageId]] = [[craftnet_plugins.packageId]]')
                ->innerJoin(['vo' => Table::PLUGINVERSIONORDER], '[[vo.versionId]] = [[v.id]]')
                ->andWhere(["vo.{$maxCol}" => $latestReleaseQuery]);
            $this->query
                ->addSelect(['latestVersionId', 'latestVersion', 'latestVersionTime']);
        }

        if ($this->withTotalPurchases) {
            $totalPurchasesSubquery = (new Query())
                ->select(['count(*)'])
                ->from(['p' => Table::PLUGINS])
                ->innerJoin(['pl' => Table::PLUGINLICENSES], '[[pl.pluginId]] = [[p.id]]')
                ->innerJoin(['pl_li' => Table::PLUGINLICENSES_LINEITEMS], '[[pl_li.licenseId]] = [[pl.id]]')
                ->where('[[p.id]] = [[craftnet_plugins.id]]');

            if ($this->totalPurchasesSince) {
                $totalPurchasesSubquery->andWhere(['>=', 'pl.dateCreated', Db::prepareDateForDb($this->totalPurchasesSince)]);
            }

            $this->subQuery
                ->addSelect([
                    'totalPurchases' => $totalPurchasesSubquery,
                ]);
            $this->query
                ->addSelect(['totalPurchases']);
        }

        return parent::beforePrepare();
    }

    /**
     * @inheritdoc
     */
    public function afterPopulate(array $elements): array
    {
        if ($this->cmsVersion) {
            foreach ($elements as $element) {
                if ($element instanceof Plugin) {
                    $element->compatibleCmsVersion = $this->cmsVersion;
                }
            }
        }

        return parent::afterPopulate($elements);
    }

    /**
     * @inheritdoc
     */
    protected function statusCondition(string $status)
    {
        switch ($status) {
            case Plugin::STATUS_PENDING:
                return [CraftTable::ELEMENTS . '.enabled' => false, Table::PLUGINS . '.pendingApproval' => true];
            case Plugin::STATUS_ABANDONED:
                return [CraftTable::ELEMENTS . '.enabled' => true, Table::PLUGINS . '.abandoned' => true];
        }

        return parent::statusCondition($status);
    }
}
