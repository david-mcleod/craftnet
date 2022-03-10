<?php

namespace craftnet\controllers;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Category;
use craft\errors\AssetDisallowedExtensionException;
use craft\errors\ImageException;
use craft\helpers\App;
use craft\helpers\ConfigHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use craftnet\errors\InvalidSvgException;
use craftnet\helpers\Cache;
use craftnet\Module;
use craftnet\plugins\Plugin;
use craftnet\plugins\PluginEdition;
use Github\Api\Repo;
use Github\Client;
use Github\Exception\RuntimeException;
use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * @property Module $module
 */
class PluginsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $repository
     *
     * @return Response
     */
    public function actionLoadDetails(string $repository): Response
    {
        $this->requireAcceptsJson();
        $parsed = parse_url($repository);
        $uriParts = isset($parsed['path']) ? explode('/', trim($parsed['path'], '/'), 4) : [];
        [$owner, $repo, $route, $ref] = array_pad($uriParts, 4, null);

        // Make sure this looks like a GitHub repo
        if (
            !isset($parsed['host']) ||
            $parsed['host'] !== 'github.com' ||
            $owner === null ||
            $repo === null ||
            ($route !== null && ($route !== 'tree' || $ref === null))
        ) {
            return $this->asErrorJson("{$repository} is not a valid GitHub repository URL");
        }

        $client = new Client();

        if ($token = Module::getInstance()->getPackageManager()->getRandomGitHubFallbackToken()) {
            $client->authenticate($token, null, Client::AUTH_ACCESS_TOKEN);
        }

        $api = $client->repos();

        // Get the composer.json contents
        try {
            $response = $api->contents()->show($owner, $repo, 'composer.json', $ref);
            $config = Json::decode(base64_decode($response['content']));
        } catch (\Throwable $e) {
            return $this->asErrorJson('There was an error loading composer.json: ' . $e->getMessage());
        }

        // Make sure it's a Craft plugin
        if (!isset($config['type']) || $config['type'] !== 'craft-plugin') {
            return $this->asErrorJson('The "type" property in composer.json must be set to "craft-plugin".');
        }

        // Make sure it has a handle
        if (!isset($config['extra']['handle'])) {
            return $this->asErrorJson('The "extra"."handle" property in composer.json must be set.');
        }

        // Get the title and handle
        $handle = $config['extra']['handle'];
        if (strtolower($handle) !== $handle) {
            /** @noinspection PhpUndefinedClassInspection */
            $handle = preg_replace('/\-{2,}/', '-', Inflector::camel2id($handle));
        }
        $name = $config['extra']['name'] ?? null;

        // Get the license
        if (isset($config['license']) && strtolower($config['license']) === 'mit') {
            $license = 'mit';
        } else {
            $license = 'craft';
        }

        // Get the icon, if we have one
        try {
            $icon = $this->_getIcon($api, $owner, $repo, $ref, $config, $handle, $name);
        } catch (InvalidSvgException $e) {
            $icon = null;
        }

        if ($icon) {
            if ($this->request->getIsCpRequest()) {
                $iconHtml = Craft::$app->getView()->renderTemplate('_elements/element', [
                    'element' => $icon,
                ]);
            } else {
                $iconHtml = null;
                $iconId = $icon->id;
                $iconUrl = $icon->getUrl();
            }
        } else {
            $iconHtml = null;
        }

        // Get the changelog path
        if (isset($config['extra']['changelogUrl'])) {
            $changelogPath = basename($config['extra']['changelogUrl']);
        } else {
            $changelogPath = null;
        }

        // Get the keywords
        if (isset($config['keywords']) && is_array($config['keywords'])) {
            $keywords = $config['keywords'];
        } else {
            $keywords = [];
        }

        return $this->asJson([
            'repository' => "https://github.com/{$owner}/{$repo}",
            'name' => $name,
            'packageName' => $config['name'] ?? null,
            'handle' => $handle,
            'license' => $license,
            'shortDescription' => $config['extra']['description'] ?? $config['description'] ?? null,
            'documentationUrl' => $config['extra']['documentationUrl'] ?? $config['support']['docs'] ?? null,
            'changelogPath' => $changelogPath,
            'icon' => $iconHtml,
            'iconId' => (isset($iconId) ? $iconId : null),
            'iconUrl' => (isset($iconUrl) ? $iconUrl : null),
            'keywords' => $keywords,
        ]);
    }

    /**
     * @param int|null $pluginId
     * @param Plugin|null $plugin
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    public function actionEdit(int $pluginId = null, Plugin $plugin = null): Response
    {
        if ($plugin === null) {
            if ($pluginId !== null) {
                $plugin = Plugin::find()->id($pluginId)->status(null)->one();
                if ($plugin === null) {
                    throw new NotFoundHttpException('Invalid plugin ID: ' . $pluginId);
                }

                if (!Craft::$app->getUser()->checkPermission('craftnet:managePlugins') && Craft::$app->getUser()->getId() !== $plugin->developerId) {
                    throw new ForbiddenHttpException('User is not permitted to perform this action');
                }
            } else {
                $plugin = new Plugin([
                    'enabled' => false,
                    'categories' => [],
                    'screenshots' => [],
                    'editions' => [
                        new PluginEdition([
                            'name' => 'Standard',
                            'handle' => 'standard',
                            'price' => 0,
                            'renewalPrice' => 0,
                        ]),
                    ],
                ]);
            }
        }

        $title = $plugin->id ? $plugin->name : 'Add a new plugin';

        $view = Craft::$app->getView();
        $view->startJsBuffer();
        $editionFieldHtml = Json::encode($view->renderTemplate('craftnet/plugins/_edition-fields', [
            'edition' => new PluginEdition(),
            'editionId' => '__EDITION_ID__',
        ]));
        $editionFieldJs = Json::encode($view->clearJsBuffer(false));

        $js = <<<JS
$('#add-edition-btn').on('click', function() {
    var editionId = 'new'+Math.floor(Math.random()*1000000);
    var html = {$editionFieldHtml}.replace(/__EDITION_ID__/g, editionId);
    var js = {$editionFieldJs}.replace(/__EDITION_ID__/g, editionId);
    var edition = $(html).insertBefore(this);
    eval(js);
    Craft.initUiElements(edition);
});
JS;
        $view->registerJs($js);

        return $this->renderTemplate('craftnet/plugins/_edit', compact('plugin', 'title'));
    }

    /**
     * @return Response
     * @throws Exception
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    public function actionSave()
    {
        $isCpRequest = $this->request->getIsCpRequest();
        $canManagePlugins = Craft::$app->getUser()->checkPermission('craftnet:managePlugins');
        $newPlugin = false;

        if ($pluginId = $this->request->getBodyParam('pluginId')) {
            $plugin = Plugin::find()->id($pluginId)->status(null)->one();
            if ($plugin === null) {
                throw new NotFoundHttpException('Invalid plugin ID: ' . $pluginId);
            }

            if (!$canManagePlugins && Craft::$app->getUser()->getId() !== $plugin->developerId) {
                throw new ForbiddenHttpException('User is not permitted to perform this action');
            }
        } else {
            $plugin = new Plugin();
            $newPlugin = true;
        }

        // Approve/reject
        // ---------------------------------------------------------------------

        if ($isCpRequest) {
            if ($this->request->getBodyParam('approve', false)) {
                $plugin->approve();
            } else if ($this->request->getBodyParam('requestChanges', false)) {
                $plugin->requestChanges();
            } else if ($this->request->getBodyParam('reject', false)) {
                $plugin->reject();
            } else if (($enabled = $this->request->getBodyParam('enabled')) !== null) {
                $plugin->enabled = (bool)$enabled;
            }
        }

        // Developer
        // ---------------------------------------------------------------------

        // Only plugin managers are able to change developer for a plugin
        if ($canManagePlugins && isset($this->request->getBodyParam('developerId')[0])) {
            $plugin->developerId = $this->request->getBodyParam('developerId')[0];
        } else if (!$plugin->developerId) {
            $plugin->developerId = Craft::$app->getUser()->getId();
        }

        $newName = false;
        $newHandle = false;
        $newIcon = false;

        $pluginName = $this->request->getBodyParam('name');

        if ($pluginName && $pluginName != $plugin->name) {
            $plugin->name = $pluginName;
            $newName = true;
        }

        if ($isCpRequest || $newPlugin) {
            $pluginHandle = $this->request->getBodyParam('handle');
            if ($pluginHandle && $pluginHandle != $plugin->handle) {
                $newHandle = true;
            }

            $packageName = $this->request->getBodyParam('packageName');
            if ($packageName && $packageName != $plugin->packageName) {
                $plugin->packageName = $packageName;
            }

            $repository = $this->request->getBodyParam('repository');
            if ($repository && $repository != $plugin->repository) {
                $plugin->repository = $repository;
            }
        }

        // Basic plugin info
        // ---------------------------------------------------------------------

        $plugin->license = $this->request->getBodyParam('license');
        $plugin->shortDescription = $this->request->getBodyParam('shortDescription');
        $plugin->longDescription = $this->request->getBodyParam('longDescription');
        $plugin->documentationUrl = $this->request->getBodyParam('documentationUrl');
        $plugin->changelogPath = $this->request->getBodyParam('changelogPath') ?: null;
        $plugin->devComments = $this->request->getBodyParam('devComments') ?: null;
        $plugin->keywords = $this->request->getBodyParam('keywords');

        if ($isCpRequest) {
            $iconId = $this->request->getBodyParam('iconId')[0] ?? null;
            if ($iconId && $iconId != $plugin->iconId) {
                $plugin->iconId = $iconId;
                $newIcon = true;
            }
        }

        // Categories
        // ---------------------------------------------------------------------

        if (!empty($categoryIds = $this->request->getBodyParam('categoryIds'))) {
            $categories = Category::find()->id($categoryIds)->fixedOrder()->all();
        } else {
            $categories = [];
        }
        $plugin->setCategories($categories);

        // Uploads
        // ---------------------------------------------------------------------

        $imageService = Craft::$app->getImages();
        $assetsService = Craft::$app->getAssets();
        $volumesService = Craft::$app->getVolumes();

        $iniMaxUpload = ConfigHelper::sizeInBytes(ini_get('upload_max_filesize'));
        $configMaxUpload = Craft::$app->getConfig()->getGeneral()->maxUploadFileSize;
        $maxUpload = min($iniMaxUpload, $configMaxUpload);
        $maxUploadM = round($maxUpload / 1000 / 1000);

        if (empty($screenshotIds = $this->request->getBodyParam('screenshotIds'))) {
            $screenshotIds = [];
        }

        if (!$isCpRequest) {

            // Icon
            // -----------------------------------------------------------------

            $iconFile = UploadedFile::getInstanceByName('icon');

            if ($iconFile) {
                if ($iconFile->error == UPLOAD_ERR_INI_SIZE || $iconFile->size > $maxUpload) {
                    $plugin->addError('icon', "Icon size must be less than {$maxUploadM}MB.");
                } else if ($iconFile->getHasError()) {
                    $plugin->addError('icon', "Icon wasn’t uploaded. (Error: {$iconFile->error})");
                } else if (strpos($iconFile->getMimeType(), 'image/svg') !== 0) {
                    $plugin->addError('icon', "Icon must be an SVG.");
                } else if ($this->_containsEmbeds(file_get_contents($iconFile->tempName))) {
                    $plugin->addError('icon', "Icon can’t contain embedded images or fonts.");
                } else {
                    $tempPath = $iconFile->saveAsTempFile();
                    $imageService->cleanImage($tempPath);

                    // Save as an asset
                    $volume = $volumesService->getVolumeByHandle('icons');
                    $folderId = $volumesService->ensureTopFolder($volume);
                    $targetFilename = "$plugin->handle.svg";

                    if (!$newPlugin) {
                        $assetToReplace = $plugin->getIcon();
                    } else {
                        $assetToReplace = null;
                    }

                    if ($assetToReplace) {
                        Craft::$app->getAssets()->replaceAssetFile($assetToReplace, $tempPath, $assetToReplace->filename);
                        $plugin->iconId = $assetToReplace->id;
                    } else {
                        // Make sure an asset doesn't already exist with the same filename
                        $exists = Asset::find()
                            ->folderId($folderId)
                            ->filename(Db::escapeParam($targetFilename))
                            ->exists();

                        if ($exists) {
                            $targetFilename = sprintf('%s-%s.svg', $plugin->handle, StringHelper::randomString(10));
                        }

                        $icon = new Asset([
                            'title' => $plugin->name,
                            'tempFilePath' => $tempPath,
                            'newLocation' => "{folder:$folderId}$targetFilename",
                        ]);

                        if (!Craft::$app->getElements()->saveElement($icon, false)) {
                            throw new Exception('Could not save icon asset: ' . implode(', ', $icon->getErrorSummary(true)));
                        }

                        $plugin->iconId = $icon->id;
                        $newIcon = true;
                    }
                }
            }

            // Screenshots
            // -----------------------------------------------------------------

            // Remove old screenshots
            $existingScreenshots = $plugin->getScreenshots();

            foreach ($existingScreenshots as $existingScreenshot) {
                $remove = true;
                foreach ($screenshotIds as $screenshotId) {
                    if ($existingScreenshot->id == $screenshotId) {
                        $remove = false;
                    }
                }

                if ($remove) {
                    Craft::$app->getElements()->deleteElementById($existingScreenshot->id, Asset::class);
                }
            }

            // Upload new screenshots
            $allowedFileExtensions = ['jp2', 'jpeg', 'jpg', 'jpx', 'png'];
            $screenshotFiles = UploadedFile::getInstancesByName('screenshots');

            if (count($screenshotFiles) > 0) {
                foreach ($screenshotFiles as $screenshotFile) {
                    if ($screenshotFile->error != UPLOAD_ERR_OK) {
                        if ($screenshotFile->error == UPLOAD_ERR_INI_SIZE) {
                            throw new Exception('Couldn’t upload screenshot because it exceeds the limit of ' . $maxUploadM . 'MB.');
                        }

                        throw new Exception('Couldn’t upload screenshot. (Error ' . $screenshotFile->error . ')');
                    }

                    if ($screenshotFile->size > $maxUpload) {
                        throw new Exception('Couldn’t upload screenshot because it exceeds the limit of ' . $maxUploadM . 'MB.');
                    }

                    $extension = $screenshotFile->getExtension();

                    if (!in_array(strtolower($extension), $allowedFileExtensions, true)) {
                        throw new AssetDisallowedExtensionException("Screenshot was not uploaded because extension “{$extension}” is not allowed.");
                    }

                    $tempPath = Craft::$app->getPath()->getTempPath() . "/screenshot-{$plugin->handle}-" . StringHelper::randomString() . '.' . $screenshotFile->getExtension();
                    move_uploaded_file($screenshotFile->tempName, $tempPath);

                    if (!$imageService->checkMemoryForImage($tempPath)) {
                        throw new ImageException(Craft::t('app',
                            'Not enough memory available to perform this image operation.'));
                    }

                    $imageService->cleanImage($tempPath);

                    // Save as an asset
                    $volumesService = Craft::$app->getVolumes();
                    $volume = $volumesService->getVolumeByHandle('screenshots');
                    $volumeId = $volumesService->ensureTopFolder($volume);

                    $subpath = '/' . $plugin->handle;

                    $folder = $assetsService->findFolder([
                        'volumeId' => $volumeId,
                        'path' => $subpath . '/',
                    ]);

                    if (!$folder) {
                        $folderId = $assetsService->ensureFolderByFullPathAndVolume($subpath, $volume);
                    } else {
                        $folderId = $folder->id;
                    }

                    $targetFilename = $screenshotFile->name;

                    $screenshot = new Asset([
                        'title' => $plugin->name,
                        'tempFilePath' => $tempPath,
                        'newLocation' => "{folder:{$folderId}}" . $targetFilename,
                        'avoidFilenameConflicts' => true,
                    ]);

                    $screenshot->validate(['newLocation']);

                    if ($screenshot->hasErrors() || !Craft::$app->getElements()->saveElement($screenshot, false)) {
                        throw new Exception('Could not save icon asset: ' . implode(', ', $screenshot->getErrorSummary(true)));
                    }

                    $screenshotIds[] = $screenshot->id;
                }
            }
        }

        $plugin->setScreenshots(Asset::find()->id($screenshotIds)->fixedOrder()->all());

        // Abandoned?
        // ---------------------------------------------------------------------

        $plugin->abandoned = (bool)($this->request->getBodyParam('abandoned') ?? $plugin->abandoned);
        if ($plugin->abandoned) {
            if (!$isCpRequest) {
                $replacementHandle = $this->request->getBodyParam('replacementHandle');

                if ($replacementHandle) {
                    $replacementPlugin = Plugin::find()->handle($replacementHandle)->one();

                    if ($replacementPlugin) {
                        $plugin->replacementId = $replacementPlugin->id;
                    } else {
                        $plugin->addError('replacementHandle', 'Invalid plugin handle.');
                    }
                } else {
                    $plugin->replacementId = null;
                }
            } else {
                $replacementId = $this->request->getBodyParam('replacement') ?? $plugin->replacementId;
                if (is_array($replacementId)) {
                    $replacementId = $replacementId[0] ?? null;
                }
                $plugin->replacementId = $replacementId ?: null;
            }
        } else {
            $plugin->replacementId = null;
        }

        // Editions
        // ---------------------------------------------------------------------

        $editions = [];

        /** @var PluginEdition[] $currentEditions */
        if ($newPlugin) {
            $currentEditions = [
                'new' => new PluginEdition([
                    'name' => 'Standard',
                    'handle' => 'standard',
                ]),
            ];
        } else {
            $currentEditions = ArrayHelper::index(PluginEdition::find()->pluginId($plugin->id)->anyStatus()->all(), 'id');

            // Include any disabled editions if this is a front-end request
            if (!$isCpRequest) {
                foreach ($currentEditions as $edition) {
                    if (!$edition->enabled) {
                        $editions[] = $edition;
                    }
                }
            }
        }

        foreach ($this->request->getBodyParam('editions', []) as $editionId => $editionInfo) {
            if ($isCpRequest) {
                $edition = $currentEditions[$editionId] ?? new PluginEdition();
                $edition->setScenario(PluginEdition::SCENARIO_CP);
            } else {
                if (!isset($currentEditions[$editionId])) {
                    throw new BadRequestHttpException('You’re not allowed to create new plugin editions.');
                }
                $edition = $currentEditions[$editionId];
                $edition->setScenario(PluginEdition::SCENARIO_SITE);
            }

            $edition->setAttributes($editionInfo);
            $editions[] = $edition;
        }

        $plugin->setEditions($editions);

        // Save plugin
        // ---------------------------------------------------------------------

        if ($plugin->enabled) {
            $plugin->setScenario(Element::SCENARIO_LIVE);
        }

        // Validate without clearing existing errors
        if (!$plugin->validate(null, false)) {
            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'errors' => $plugin->getErrors(),
                ]);
            }

            Craft::$app->getSession()->setError('Couldn’t save plugin.');
            Craft::$app->getUrlManager()->setRouteParams([
                'plugin' => $plugin,
            ]);
            return null;
        }

        Craft::$app->getElements()->saveElement($plugin, false);

        // Rename icon & screenshots with new name and filename
        // ---------------------------------------------------------------------

        if (!$newPlugin && ($newName || $newHandle)) {
            // Icon
            if ($plugin->icon) {
                $icon = $plugin->icon;

                if ($newName) {
                    $icon->title = $plugin->name;
                }

                if ($newHandle) {
                    $icon->newFilename = $plugin->handle . '.' . $icon->getExtension();
                }

                if (!Craft::$app->getElements()->saveElement($icon, false)) {
                    throw new Exception('Could not save icon asset: ' . implode(', ', $icon->getErrorSummary(true)));
                }
            }

            // Screenshots
            if ($newHandle) {
                $volume = $volumesService->getVolumeByHandle('screenshots');
                $volumeId = $volumesService->ensureTopFolder($volume);

                $subpath = '/' . $plugin->handle;

                $folder = $assetsService->findFolder([
                    'volumeId' => $volumeId,
                    'path' => $subpath . '/',
                ]);

                if (!$folder) {
                    $folderId = $assetsService->ensureFolderByFullPathAndVolume($subpath, $volume);
                    $folder = $assetsService->getFolderById($folderId);
                }

                foreach ($plugin->screenshots as $screenshot) {
                    if (!$assetsService->moveAsset($screenshot, $folder)) {
                        throw new Exception('Could not save screenshot asset: ' . implode(', ', $screenshot->getErrorSummary(true)));
                    }
                }
            }
        }

        // Now add our webhook if we don't have one yet
        $this->module->getPackageManager()->createWebhook($plugin->packageName, false);

        if ($this->request->getAcceptsJson()) {
            $return = [];

            $return['success'] = true;
            $return['id'] = $plugin->id;
            $return['iconId'] = $plugin->iconId;
            $return['iconUrl'] = $plugin->iconId ? $plugin->getIcon()->getUrl() : null;
            $return['name'] = $plugin->name;

            // Screenshots
            $return['screenshots'] = [];

            foreach ($plugin->getScreenshots() as $screenshot) {
                $return['screenshots'][] = [
                    'id' => $screenshot->id,
                    'url' => $screenshot->getUrl(),
                ];
            }

            // Editions
            $return['editions'] = [];

            foreach ($editions as $edition) {
                $return['editions'][] = [
                    'id' => $edition->id,
                    'name' => $edition->name,
                    'handle' => $edition->handle,
                    'price' => $edition->price,
                    'renewalPrice' => $edition->renewalPrice,
                    'features' => $edition->features ?? [],
                ];
            }

            return $this->asJson($return);
        }

        Cache::invalidate(Cache::TAG_PACKAGES);

        if ($newIcon) {
            Cache::invalidate(Cache::pluginIconTag($plugin));
        }

        Craft::$app->getSession()->setNotice('Plugin saved.');

        return $this->redirectToPostedUrl($plugin);
    }

    /**
     * @return null|Response
     * @throws NotFoundHttpException
     */
    public function actionDelete()
    {
        $pluginId = $this->request->getBodyParam('pluginId');
        $plugin = Plugin::find()->id($pluginId)->status(null)->one();

        if (!$plugin) {
            throw new NotFoundHttpException('Plugin not found');
        }


        // Delete icon

        if ($plugin->icon) {
            Craft::$app->getElements()->deleteElement($plugin->icon);
        }


        // Delete screenshots

        foreach ($plugin->screenshots as $screenshot) {
            Craft::$app->getElements()->deleteElement($screenshot);
        }


        // Delete plugin

        if (!Craft::$app->getElements()->deleteElement($plugin)) {
            Craft::$app->getSession()->setError('Couldn’t delete plugin.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Plugin deleted.');
        return $this->redirectToPostedUrl($plugin);
    }

    /**
     * Submits a plugin for approval.
     *
     * @return Response|null
     * @throws Exception
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionSubmit()
    {
        $pluginId = $this->request->getBodyParam('pluginId');
        $plugin = Plugin::find()->id($pluginId)->status(null)->one();

        if (!$plugin) {
            throw new NotFoundHttpException('Plugin not found');
        }

        if (!Craft::$app->getUser()->checkPermission('craftnet:managePlugins') && Craft::$app->getUser()->getId() !== $plugin->developerId) {
            throw new ForbiddenHttpException('User is not permitted to perform this action');
        }

        if ($plugin->enabled) {
            // Pretend we did
            if ($this->request->getAcceptsJson()) {
                return $this->asJson(['success' => true]);
            }
            return $this->redirectToPostedUrl($plugin);
        }

        $plugin->submitForApproval();


        // Save plugin

        if (!Craft::$app->getElements()->saveElement($plugin)) {
            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'errors' => $plugin->getErrors(),
                ]);
            }

            Craft::$app->getSession()->setError('Couldn’t submit plugin for approval.');
            Craft::$app->getUrlManager()->setRouteParams([
                'plugin' => $plugin,
            ]);
            return null;
        }

        try {
            Craft::$app->getMailer()->compose()
                ->setSubject('A plugin is waiting for approval: ' . $plugin->name)
                ->setTextBody('https://id.craftcms.com/' . App::env('CRAFT_CP_TRIGGER') . '/plugins/' . $plugin->id)
                ->setTo(explode(',', App::env('PLUGIN_APPROVAL_RECIPIENTS')))
                ->send();
        } catch (\Exception $e) {
            // Just log and move on.
            Craft::error('There was a problem sending the plugin approval email: ' . $e->getMessage(), __METHOD__);
        }

        // Return

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice('Plugin submitted for approval.');
        return $this->redirectToPostedUrl($plugin);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a plugin’s icon.
     *
     * @param Repo $api
     * @param string $owner
     * @param string $repo
     * @param string|null $ref
     * @param array $config
     * @param string|null $handle
     * @param string|null $name
     * @return Asset|null
     * @throws InvalidSvgException if the SVG contains image data
     */
    private function _getIcon(Repo $api, string $owner, string $repo, string $ref = null, array $config, string $handle = null, string $name = null)
    {
        // Make sure the plugin has a handle
        if (!$handle) {
            return null;
        }

        // Kebab-case it
        if (strtolower($handle) !== $handle) {
            /** @noinspection PhpUndefinedClassInspection */
            $handle = preg_replace('/\-{2,}/', '-', Inflector::camel2id($handle));
        }

        // See if we already happen to have an icon for this handle
        $filename = $handle . '.svg';
        if ($icon = Asset::find()->volume('icons')->filename($filename)->one()) {
            return $icon;
        }

        // Make sure there are some autoload paths
        if (!isset($config['autoload']['psr-4'])) {
            return null;
        }

        // Loop through the autoload paths and look for icons
        $basePath = isset($config['extra']['basePath']) && strpos($config['extra']['basePath'], '@') === 0 ? rtrim($config['extra']['basePath']) : null;
        $pluginClass = isset($config['extra']['class']) ? ltrim($config['extra']['class'], '\\') : null;

        foreach ($config['autoload']['psr-4'] as $namespace => $path) {
            $namespace = trim($namespace, '\\');
            $path = rtrim($path, '/');

            // If basePath is defined, we only care about namespace path(s) that include it
            if ($basePath !== null) {
                $alias = '@' . str_replace('\\', '/', $namespace);
                if (strpos($basePath . '/', $alias . '/') === 0) {
                    $testPath = $path . substr($config['extra']['basePath'], strlen($alias));
                    if ($icon = $this->_getIconInPath($api, $owner, $repo, $ref, $handle, $name, $testPath)) {
                        return $icon;
                    };
                }
            } // If the plugin class is defined, we only care about namespace path(s) that include its directory
            else if ($pluginClass !== null) {
                if (strpos($pluginClass, $namespace . '\\') === 0) {
                    $subPath = str_replace('\\', '/', substr($pluginClass, strlen($namespace) + 1));
                    $testPath = $path . (dirname($subPath) !== '.' ? '/' . dirname($subPath) : '');
                    if ($icon = $this->_getIconInPath($api, $owner, $repo, $ref, $handle, $name, $testPath)) {
                        return $icon;
                    };
                }
            } else {
                // Only include all autoload paths if basePath and pluginClass are not set
                if ($icon = $this->_getIconInPath($api, $owner, $repo, $ref, $handle, $name, $path)) {
                    return $icon;
                };
            }
        }

        return null;
    }

    /**
     * Looks for a plugin’s icon within a specific path
     *
     * @param Repo $api
     * @param string $owner
     * @param string $repo
     * @param string|null $ref
     * @param string $handle
     * @param string|null $name
     *
     * @param string $testPath
     *
     * @return Asset|null
     * @throws InvalidSvgException if the SVG embeds images or fonts
     * @throws Exception if the icon asset can't be saved
     */
    private function _getIconInPath(Repo $api, string $owner, string $repo, string $ref = null, string $handle, string $name = null, string $testPath)
    {
        try {
            $response = $api->contents()->show($owner, $repo, $testPath . '/icon.svg', $ref);
        } catch (RuntimeException $e) {
            return null;
        }

        // Decode and save it
        $contents = base64_decode($response['content']);

        // Make sure it doesn't embed images or fonts
        if ($this->_containsEmbeds($contents)) {
            throw new InvalidSvgException('SVG document embeds images or fonts.');
        }

        $tempPath = Craft::$app->getPath()->getTempPath() . "/icon-{$handle}-" . StringHelper::randomString() . '.svg';
        FileHelper::writeToFile($tempPath, $contents);

        // Save as an asset
        $volumesService = Craft::$app->getVolumes();
        $volume = $volumesService->getVolumeByHandle('icons');
        $folderId = $volumesService->ensureTopFolder($volume);

        $icon = new Asset([
            'title' => $name,
            'tempFilePath' => $tempPath,
            'newLocation' => "{folder:{$folderId}}{$handle}" . StringHelper::randomString() . ".svg",
        ]);

        if (!Craft::$app->getElements()->saveElement($icon, false)) {
            throw new Exception('Could not save icon asset: ' . implode(', ', $icon->getErrorSummary(true)));
        }

        return $icon;
    }

    /**
     * Returns whether an SVG embeds images or fonts.
     *
     * @param string $contents
     * @return bool
     */
    private function _containsEmbeds(string $contents): bool
    {
        return stripos($contents, 'data:image') !== false || stripos($contents, 'data:font') !== false;
    }
}
