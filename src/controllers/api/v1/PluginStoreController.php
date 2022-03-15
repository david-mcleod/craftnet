<?php

namespace craftnet\controllers\api\v1;

use Composer\Semver\Semver;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craftnet\controllers\api\BaseApiController;
use craftnet\db\Table;
use craftnet\helpers\Cache;
use craftnet\Module;
use craftnet\plugins\Plugin;
use craftnet\plugins\PluginQuery;
use yii\base\InvalidArgumentException;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Class PluginStoreController
 */
class PluginStoreController extends BaseApiController
{
    public function runAction($id, $params = []): Response
    {
        if (!in_array($id, [
            '',
            'core-data',
            'plugin',
        ])) {
            $this->checkCraftHeaders = false;
        }

        return parent::runAction($id, $params);
    }

    /**
     * Handles /v1/plugin-store requests.
     *
     * Used by Craft CMS < 3.3.16.
     *
     * @return Response
     * @throws \craftnet\errors\MissingTokenException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function actionIndex(): Response
    {
        $cacheKey = $this->_cacheKey(__METHOD__);
        $pluginStoreData = Cache::get($cacheKey);

        if (!$pluginStoreData) {
            $featuredPlugins = [];

            foreach ($this->_createFeaturedSectionQuery()->all() as $entry) {
                try {
                    $pluginIds = $this->_createFeaturedPluginQuery($entry)
                        ->withLatestReleaseInfo(true, $this->cmsVersionForPluginQueries())
                        ->ids();
                } catch (InvalidArgumentException $e) {
                    continue;
                }

                if (!empty($pluginIds)) {
                    $featuredPlugins[] = $this->_transformFeaturedSection($entry) +
                        ['plugins' => $pluginIds];
                }
            }

            $plugins = $this->_createPluginQuery()
                ->indexBy('id')
                ->all();

            $pluginStoreData = [
                'categories' => $this->_categories(),
                'featuredPlugins' => $featuredPlugins,
                'plugins' => $this->transformPlugins($plugins),
                'expiryDateOptions' => $this->_expiryDateOptions(),
            ];

            $this->_cache($cacheKey, $pluginStoreData);
        }

        return $this->asJson($pluginStoreData);
    }

    /**
     * Handles /v1/plugin-store/core-data requests.
     *
     * @return Response
     */
    public function actionCoreData(): Response
    {
        $pluginStoreData = [
            'categories' => $this->_categories(),
            'sortOptions' => [
                'popularity' => 'desc',
                'dateUpdated' => 'desc',
                'name' => 'asc',
            ],
            'expiryDateOptions' => $this->_expiryDateOptions(),
        ];

        return $this->asJson($pluginStoreData);
    }

    /**
     * Handles /v1/plugin-store/featured-section/<handle> requests.
     *
     * @param $handle
     * @return Response
     */
    public function actionFeaturedSection($handle): Response
    {
        $cacheKey = $this->_cacheKey(__METHOD__, $handle);
        $data = Cache::get($cacheKey);

        if (!$data) {
            $featuredSectionEntry = $this->_createFeaturedSectionQuery()
                ->slug($handle)
                ->one();

            $data = $this->_transformFeaturedSection($featuredSectionEntry);
            $this->_cache($cacheKey, $data);
        }

        return $this->asJson($data);
    }

    /**
     * Handles /v1/plugin-store/featured-sections requests.
     *
     * @return Response
     */
    public function actionFeaturedSections(): Response
    {
        $cacheKey = $this->_cacheKey(__METHOD__);
        $data = Cache::get($cacheKey);

        if (!$data) {
            $data = [];

            foreach ($this->_createFeaturedSectionQuery()->all() as $entry) {
                try {
                    $plugins = $this->_createFeaturedPluginQuery($entry)
                        ->limit(8)
                        ->all();
                } catch (InvalidArgumentException $e) {
                    continue;
                }

                if (!empty($plugins)) {
                    $data[] = $this->_transformFeaturedSection($entry) +
                        ['plugins' => $this->transformPlugins($plugins)];
                }
            }

            $this->_cache($cacheKey, $data);
        }

        return $this->asJson($data);
    }

    /**
     * Handles /v1/plugin-store/plugin/<handle> requests.
     *
     * @param string $handle
     * @return Response
     * @throws \craftnet\errors\MissingTokenException
     * @throws \yii\base\InvalidConfigException
     */
    public function actionPlugin(string $handle): Response
    {
        $cacheKey = $this->_cacheKey(__METHOD__, $handle);
        $data = Cache::get($cacheKey);

        if (!$data) {
            $plugin = $this->_plugin($handle);

            if (!$plugin) {
                return $this->asErrorJson("Couldn't find plugin");
            }

            $data = $this->transformPlugin($plugin, true);
            $this->_cache($cacheKey, $data);
        }

        // Add the latest compatible version
        if ($this->cmsVersion) {
            $packageManager = Module::getInstance()->getPackageManager();
            $cmsRelease = $packageManager->getRelease('craftcms/cms', $this->cmsVersion);
            if ($cmsRelease) {
                $data['latestCompatibleVersion'] = (new Query)
                    ->select(['v.version'])
                    ->from(['v' => Table::PACKAGEVERSIONS])
                    ->innerJoin(['vo' => Table::PLUGINVERSIONORDER], '[[vo.versionId]] = [[v.id]]')
                    ->where(['v.packageId' => $data['packageId']])
                    ->andWhere([
                        'vo.stableOrder' => (new Query())
                            ->select(['max([[s_vo.stableOrder]])'])
                            ->from(['s_vo' => Table::PLUGINVERSIONORDER])
                            ->innerJoin(['s_v' => Table::PACKAGEVERSIONS], '[[s_v.id]] = [[s_vo.versionId]]')
                            ->innerJoin(['s_vc' => Table::PLUGINVERSIONCOMPAT], '[[s_vc.pluginVersionId]] = [[s_v.id]]')
                            ->where(['s_v.packageId' => $data['packageId']])
                            ->andWhere(['s_vc.cmsVersionId' => $cmsRelease->id])
                            ->groupBy(['s_v.packageId']),
                    ])
                    ->scalar();
            } else {
                $data['latestCompatibleVersion'] = null;

                // Get the latest plugin release and manually check if it's compatible
                if (
                    (isset($plugin) || ($plugin = $this->_plugin($handle))) &&
                    ($latestRelease = $packageManager->getRelease($plugin->packageName, $plugin->latestVersion))
                ) {
                    $cmsConstraints = (new Query())
                        ->select(['constraints'])
                        ->from([Table::PACKAGEDEPS])
                        ->where([
                            'versionId' => $latestRelease->id,
                            'name' => 'craftcms/cms',
                        ])
                        ->scalar();
                    if ($cmsConstraints && Semver::satisfies($this->cmsVersion, $cmsConstraints)) {
                        $data['latestCompatibleVersion'] = $plugin->latestVersion;
                    }
                }
            }
        }

        return $this->asJson($data);
    }

    /**
     * Handles /v1/plugin-store/plugins requests.
     *
     * @param string|null $handle
     * @param int|null $categoryId
     * @param int|null $developerId
     * @param string|null $searchQuery
     * @param int $perPage
     * @param int $page
     * @param string $orderBy
     * @param string $direction
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionPlugins(
        string $handle = null,
        int $categoryId = null,
        int $developerId = null,
        string $searchQuery = null,
        int $perPage = 10,
        int $page = 1,
        string $orderBy = 'dateUpdated',
        string $direction = 'desc'
    ): Response
    {
        $perPage = max(min($perPage, 100), 1);
        $page = max($page, 1);
        $direction = $direction === 'asc' ? SORT_ASC : SORT_DESC;

        $cacheKey = $this->_cacheKey(__METHOD__, $handle, $categoryId, $developerId, $searchQuery, $perPage, $page, $orderBy, $direction);
        $data = Cache::get($cacheKey);

        if (!$data) {
            $query = $this->_createPluginQuery()
                ->orderBy([Table::PLUGINS . '.abandoned' => SORT_ASC])
                ->limit($perPage);

            if ($handle) {
                $query->handle(explode(',', $handle));
            }

            if ($categoryId) {
                $query->categoryId($categoryId);
            }

            if ($developerId) {
                $query->developerId($developerId);
            }

            if ($searchQuery) {
                $query->search($searchQuery);
            }

            // Make sure a valid page number was requested
            $totalResults = $query->count();
            if ($totalResults) {
                $totalPages = ceil($totalResults / $perPage);
                if ($page > $totalPages) {
                    $page = $totalPages;
                    // Start over in case the actual last page is already cached
                    return $this->runAction('plugins', compact(
                        'developerId',
                        'categoryId',
                        'searchQuery',
                        'perPage',
                        'page',
                        'orderBy',
                        'direction'
                    ));
                }

                switch ($orderBy) {
                    case 'dateUpdated':
                        $query->addOrderBy(['latestVersionTime' => $direction]);
                        break;
                    case 'name':
                        $query->addOrderBy(['lower([[name]])' => $direction]);
                        break;
                    case 'popularity':
                        $query->addOrderBy(['activeInstalls' => $direction]);
                        break;
                    default:
                        throw new BadRequestHttpException('Unsupported orderBy param: ' . $orderBy);
                }

                $query->offset(($page - 1) * $perPage);
                $plugins = $query->all();
            } else {
                $page = 1;
                $totalPages = 1;
                $plugins = [];
            }


            $data = [
                'plugins' => $this->transformPlugins($plugins),
                'totalResults' => $totalResults,
                'currentPage' => $page,
                'perPage' => $perPage,
                'total' => $totalPages,
            ];

            $this->_cache($cacheKey, $data);
        }

        return $this->asJson($data);
    }

    /**
     * Handles /v1/plugin-store/plugins-by-featured-section/<handle> requests.
     *
     * @param string $handle
     * @return Response
     * @throws NotFoundHttpException if $handle is invalid
     */
    public function actionPluginsByFeaturedSection(string $handle): Response
    {
        $cacheKey = $this->_cacheKey(__METHOD__, $handle);
        $data = Cache::get($cacheKey);

        if (!$data) {
            $entry = $this->_createFeaturedSectionQuery()
                ->slug($handle)
                ->one();

            if (!$entry) {
                throw new NotFoundHttpException("Invalid featured plugin list: {$handle}");
            }

            try {
                $plugins = $this->_createFeaturedPluginQuery($entry)
                    ->all();
            } catch (InvalidArgumentException $e) {
                $plugins = [];
            }

            $data = [
                'plugins' => $this->transformPlugins($plugins),
                'currentPage' => 1,
                'perPage' => 100,
                'total' => 1,
                'totalResults' => count($plugins),
            ];

            $this->_cache($cacheKey, $data);
        }

        return $this->asJson($data);
    }

    /**
     * Handles /v1/plugin-store/plugins-by-handles requests.
     *
     * @return Response
     * @throws \craftnet\errors\MissingTokenException
     * @throws \yii\base\InvalidConfigException
     */
    public function actionPluginsByHandles(): Response
    {
        $pluginHandles = $this->request->getParam('pluginHandles', '');

        $cacheKey = $this->_cacheKey(__METHOD__, $pluginHandles);
        $data = Cache::get($cacheKey);

        if (!$data) {
            $pluginHandles = explode(',', $pluginHandles);

            $plugins = $this->_createPluginQuery()
                ->andWhere([Table::PLUGINS . '.handle' => $pluginHandles])
                ->all();

            $data = $this->transformPlugins($plugins);
            $this->_cache($cacheKey, $data);
        }

        return $this->asJson($data);
    }

    /**
     * @return EntryQuery
     */
    private function _createFeaturedSectionQuery(): EntryQuery
    {
        return Entry::find()
            ->site('craftId')
            ->section('featuredPlugins');
    }

    /**
     * @param $featuredSectionEntry
     * @return array
     */
    private function _transformFeaturedSection($featuredSectionEntry): array
    {
        return [
            'id' => $featuredSectionEntry->id,
            'slug' => $featuredSectionEntry->slug,
            'title' => $featuredSectionEntry->title,
            'limit' => $featuredSectionEntry->limit,
        ];
    }

    /**
     * @return array
     */
    private function _categories(): array
    {
        $cacheKey = $this->_cacheKey(__METHOD__);
        $data = Cache::get($cacheKey);

        if (!$data) {
            $data = [];

            /** @var Category[] $categories */
            $categories = Category::find()
                ->group('pluginCategories')
                ->with('icon')
                ->all();

            foreach ($categories as $category) {
                /** @var Asset|null $icon */
                $icon = $category->icon[0] ?? null;
                $data[] = [
                    'id' => $category->id,
                    'title' => $category->title,
                    'description' => $category->description,
                    'slug' => $category->slug,
                    'iconUrl' => $icon ? $icon->getUrl() . '?' . $icon->dateModified->getTimestamp() : null,
                ];
            }

            $this->_cache($cacheKey, $data);
        }

        return $data;
    }

    /**
     * @param bool $withEagerLoading
     * @return PluginQuery
     */
    private function _createPluginQuery(bool $withEagerLoading = true): PluginQuery
    {
        $query = Plugin::find();
        $this->_preparePluginQuery($query, $withEagerLoading);
        return $query;
    }

    /**
     * @param string $handle
     * @return Plugin|null
     */
    private function _plugin(string $handle): ?Plugin
    {
        return $this->_createPluginQuery(false)
            ->handle($handle)
            ->one();
    }

    /**
     * @param PluginQuery $query
     * @param bool $withEagerLoading
     */
    private function _preparePluginQuery(PluginQuery $query, bool $withEagerLoading = true)
    {
        $query->withLatestReleaseInfo(true, $this->cmsVersionForPluginQueries());
        if ($withEagerLoading) {
            $query->with(['developer', 'categories', 'icon', 'editions']);
        }
    }

    /**
     * @param Entry $entry The Featured Plugins entry
     * @return PluginQuery
     * @throws InvalidArgumentException
     */
    private function _createFeaturedPluginQuery(Entry $entry): PluginQuery
    {
        $type = $entry->getType();

        switch ($type->handle) {
            case 'manual':
                $query = clone $entry->plugins;
                $this->_preparePluginQuery($query);
                return $query;
            case 'dynamic':
                $query = $this->_createPluginQuery()
                    ->andWhere(['not', [Table::PLUGINS . '.dateApproved' => null]])
                    ->limit(20);
                switch ($entry->slug) {
                    case 'recently-added':
                        $query->orderBy([Table::PLUGINS . '.dateApproved' => SORT_DESC]);
                        break;
                    case 'recently-updated':
                        $query->orderBy(['latestVersionTime' => SORT_DESC]);
                        break;
                    case 'top-paid':
                        $query
                            ->withTotalPurchases(true, (new \DateTime())->modify('-1 month'))
                            ->andWhere(['not', ['elements.id' => 983]])
                            ->orderBy(['totalPurchases' => SORT_DESC]);
                        break;
                    default:
                        throw new InvalidArgumentException("Invalid Featured Plugins entry slug: {$entry->slug}");
                }
                return $query;
            default:
                throw new InvalidArgumentException("Invalid Featured Plugins entry type: {$type->handle}");
        }
    }

    /**
     * @return array`
     */
    private function _expiryDateOptions(): array
    {
        $dates = [];

        for ($i = 1; $i <= 5; $i++) {
            $date = (new \DateTime('now', new \DateTimeZone('UTC')))
                ->modify("+{$i} years");
            $dates[] = ["{$i}y", $date->format('Y-m-d')];
        }

        return $dates;
    }

    /**
     * @param string|null ...$components
     * @return string
     */
    private function _cacheKey(?string ...$components): string
    {
        $components[] = sprintf('v%s', $this->cmsMajorVersion());

        if ($this->withPluginIcons()) {
            $components[] = 'withIcons';
        }

        return implode('-', $components);
    }

    /**
     * @param string $key
     * @param mixed $value
     */
    private function _cache(string $key, mixed $value): void
    {
        Cache::set($key, $value, $this->_cacheTags());
    }

    /**
     * @param array string|null ...$tags
     * @return array
     */
    private function _cacheTags(?string ...$tags): array
    {
        $tags[] = Cache::TAG_PACKAGES;

        if ($this->withPluginIcons()) {
            $tags[] = Cache::TAG_PLUGIN_ICONS;
        }

        return $tags;
    }
}
