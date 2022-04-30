<?php

namespace craftnet\controllers\api\v1;

use Craft;
use craftnet\composer\PackageManager;
use craftnet\controllers\api\BaseApiController;
use craftnet\plugins\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Class UpgradeInfoController
 */
class UpgradeInfoController extends BaseApiController
{
    public function actionIndex(string $cmsConstraint): Response
    {
        if (!$this->cmsVersion) {
            throw new BadRequestHttpException('Missing installed Craft version.');
        }

        $packageManager = $this->module->getPackageManager();

        $cmsRelease = $packageManager->getLatestRelease('craftcms/cms', constraint: $cmsConstraint);
        if (!$cmsRelease) {
            $cmsRelease = $packageManager->getLatestRelease('craftcms/cms', 'dev', $cmsConstraint);
        }

        return $this->asJson([
            'cms' => [
                'latestVersion' => $cmsRelease?->version,
                'phpConstraint' => $cmsRelease ? $packageManager->getPhpConstraintByVersionId($cmsRelease->id) : null,
            ],
            'plugins' => $cmsRelease ? $this->_pluginInfo($packageManager, $cmsRelease->version) : [],
        ]);
    }

    private function _pluginInfo(PackageManager $packageManager, string $cmsVersion): array
    {
        if (!$this->plugins) {
            return [];
        }

        // Eager-load more stuff onto the list of installed plugins
        Craft::$app->getElements()->eagerLoadElements(Plugin::class, $this->plugins, ['icon', 'developer', 'replacement']);

        // Get the plugins which are compatible with the target Craft version
        $compatiblePlugins = Plugin::find()
            ->id(array_map(fn(Plugin $plugin) => $plugin->id, $this->plugins))
            ->withLatestReleaseInfo(cmsVersion: $cmsVersion)
            ->indexBy('id')
            ->all();

        // Get their PHP constraints
        if ($compatiblePlugins) {
            $versionIds = array_map(fn(Plugin $plugin) => $plugin->latestVersionId, $compatiblePlugins);
            $phpConstraints = $packageManager->getPhpConstraintByVersionId($versionIds);
        }

        $pluginInfo = [];

        foreach ($this->plugins as $plugin) {
            $developer = $plugin->getDeveloper();
            $compatiblePlugin = $compatiblePlugins[$plugin->id] ?? null;

            $info = [
                'name' => $plugin->name,
                'handle' => $plugin->handle,
                'icon' => $this->pluginIconContents($plugin),
                'developerName' => $developer->getDeveloperName(),
                'developerUrl' => $developer->developerUrl,
                'abandoned' => $plugin->abandoned,
                'latestVersion' => $compatiblePlugin?->latestVersion,
                'phpConstraint' => $compatiblePlugin ? ($phpConstraints[$compatiblePlugin->latestVersionId] ?? null) : null,
            ];

            $replacement = $plugin->getReplacement();
            if ($replacement) {
                $info['replacement'] = [
                    'name' => $replacement->name,
                    'handle' => $replacement->handle,
                ];
            }

            $pluginInfo[] = $info;
        }

        usort($pluginInfo, fn($a, $b) => $a['name'] <=> $b['name']);

        return $pluginInfo;
    }
}
