<?php

namespace craftnet\controllers\api\v1;

use craft\helpers\DateTimeHelper;
use craftnet\ChangelogParser;
use craftnet\controllers\api\BaseApiController;
use craftnet\helpers\Cache;
use craftnet\Module;
use craftnet\plugins\Plugin;
use yii\web\Response;

/**
 * Class PluginController
 */
class PluginController extends BaseApiController
{
    // Public Methods
    // =========================================================================

    public function runAction($id, $params = []): Response
    {
        if ($id === 'changelog') {
            $this->checkCraftHeaders = false;
        }

        return parent::runAction($id, $params);
    }

    /**
     * Handles /v1/plugin/<pluginId> requests.
     *
     * @return Response
     */
    public function actionIndex($pluginId): Response
    {
        $plugin = Plugin::find()
            ->id($pluginId)
            ->withLatestReleaseInfo(true, $this->cmsVersion)
            ->one();

        if (!$plugin) {
            return $this->asErrorJson("Couldn't find plugin");
        }

        return $this->asJson($this->transformPlugin($plugin, true));
    }

    /**
     * Handles /v1/plugin/<pluginId>/changelog requests.
     *
     * @return Response
     */
    public function actionChangelog($pluginId): Response
    {
        $cacheKey = sprintf('%s-%s-v%s', __METHOD__, $pluginId, $this->cmsMajorVersion());
        $changelogData = Cache::get($cacheKey);

        if (!$changelogData) {
            $plugin = Plugin::find()
                ->id($pluginId)
                ->withLatestReleaseInfo(cmsVersion: $this->cmsVersionForPluginQueries())
                ->one();

            if (!$plugin) {
                return $this->asErrorJson("Couldn't find plugin");
            }

            $packageManager = Module::getInstance()->getPackageManager();
            $release = $packageManager->getRelease($plugin->packageName, $plugin->latestVersion);

            $releases = (new ChangelogParser())->parse($release->changelog ?? '');
            foreach ($releases as &$release) {
                $date = DateTimeHelper::toDateTime($release['date']);
                $release['date'] = $date ? $date->format('Y-m-d\TH:i:s') : null;
            }

            $changelogData = array_values($releases);

            Cache::set($cacheKey, $changelogData, [
                Cache::TAG_PACKAGES,
                Cache::TAG_PLUGIN_CHANGELOGS,
            ]);
        }

        return $this->asJson($changelogData);
    }
}
