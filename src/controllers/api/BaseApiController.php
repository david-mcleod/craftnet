<?php

namespace craftnet\controllers\api;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\User;
use craft\errors\InvalidPluginException;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\HtmlPurifier;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\web\Controller;
use craftnet\behaviors\UserBehavior;
use craftnet\cms\CmsLicense;
use craftnet\cms\CmsLicenseManager;
use craftnet\db\Table;
use craftnet\errors\ExpiredTokenException;
use craftnet\errors\LicenseNotFoundException;
use craftnet\errors\ValidationException;
use craftnet\helpers\Cache;
use craftnet\helpers\KeyHelper;
use craftnet\Module;
use craftnet\oauthserver\Module as OauthServer;
use craftnet\plugins\Plugin;
use craftnet\plugins\PluginEdition;
use craftnet\plugins\PluginLicense;
use JsonSchema\Validator;
use stdClass;
use superbig\bugsnag\Bugsnag;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\Model;
use yii\base\UserException;
use yii\helpers\Markdown;
use yii\validators\EmailValidator;
use yii\web\BadRequestHttpException;
use yii\web\Controller as YiiController;
use yii\web\HttpException;
use yii\web\Response;

/**
 * Class BaseController
 *
 * @property Module $module
 */
abstract class BaseApiController extends Controller
{
    const ERROR_CODE_INVALID = 'invalid';
    const ERROR_CODE_MISSING = 'missing';
    const ERROR_CODE_MISSING_FIELD = 'missing_field';
    const ERROR_CODE_EXISTS = 'already_exists';

    const LICENSE_STATUS_VALID = 'valid';
    const LICENSE_STATUS_INVALID = 'invalid';
    const LICENSE_STATUS_MISMATCHED = 'mismatched';
    const LICENSE_STATUS_ASTRAY = 'astray';
    const LICENSE_STATUS_TRIAL = 'trial';

    /**
     * @inheritdoc
     */
    protected $allowAnonymous = true;

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    /**
     * Whether to check X-Craft headers on this request
     */
    protected $checkCraftHeaders = true;

    /**
     * @var int|null The API request ID, if there is one.
     */
    public $requestId;

    /**
     * @var string|null The user’s email
     */
    public $email;

    /**
     * @var string|null The platform versions.
     */
    public $platformVersions;

    /**
     * @var string|null The installed Craft version.
     */
    public $cmsVersion;

    /**
     * @var string|null The installed Craft edition.
     */
    public $cmsEdition;

    /**
     * @var Plugin[] The installed plugins.
     */
    public $plugins = [];

    /**
     * @var string[] The installed plugin versions.
     */
    public $pluginVersions = [];

    /**
     * @var string[] The installed plugin editions.
     */
    public $pluginEditions = [];

    /**
     * @var CmsLicense[] The Craft license associated with this request.
     */
    public $cmsLicenses = [];

    /**
     * @var PluginLicense[] The plugin licenses associated with this request.
     */
    public $pluginLicenses = [];

    /**
     * @var PluginEdition[] The plugin editions the plugin licenses are set to.
     */
    public $pluginLicenseEditions = [];

    /**
     * @var string[] The plugin license statuses.
     */
    public $pluginLicenseStatuses = [];

    /**
     * @var array
     */
    private $_logRequestKeys = [];

    /**
     * @var string|false
     * @see cmsVersionForPluginQueries()
     */
    private string|false $_cmsVersionForPluginQueries;

    /**
     * @return array
     */
    public function getLogRequestKeys(): array
    {
        return $this->_logRequestKeys;
    }

    /**
     * @param $key
     * @param null $pluginHandle
     */
    public function addLogRequestKey($key, $pluginHandle = null)
    {
        if (!$pluginHandle) {
            $pluginHandle = 'craft';
        }

        $this->_logRequestKeys[$pluginHandle] = $key;
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        // if the request is authenticated, set their identity
        if (($user = $this->getAuthUser()) !== null) {
            Craft::$app->getUser()->setIdentity($user);
        }

        return parent::beforeAction($action);
    }

    /**
     * @inheritdoc
     */
    public function runAction($id, $params = []): Response
    {
        $requestHeaders = $this->request->getHeaders();
        $response = $this->response;
        $responseHeaders = $response->getHeaders();
        $identity = $requestHeaders->get('X-Craft-User-Email') ?: 'anonymous';
        $db = Craft::$app->getDb();
        $checkCraftHeaders = !$this->request->getIsOptions() && ($this->checkCraftHeaders || $this->request->getParam('processCraftHeaders'));
        $allowTrials = !App::env('NO_TRIALS');

        // allow ajax requests to see the response headers
        $responseHeaders->set('access-control-expose-headers', implode(', ', [
            'x-craft-allow-trials',
            'x-craft-license',
            'x-craft-license-domain',
            'x-craft-license-edition',
            'x-craft-license-status',
            'x-craft-plugin-license-editions',
            'x-craft-plugin-license-statuses',
            'x-craft-plugin-licenses',
        ]));

        // was platform info provided?
        if ($checkCraftHeaders && $requestHeaders->has('X-Craft-Platform')) {
            foreach (explode(',', $requestHeaders->get('X-Craft-Platform')) as $info) {
                [$name, $installed] = array_pad(explode(':', $info, 2), 2, null);
                if ($installed !== null) {
                    $this->platformVersions[$name] = $installed;
                }
            }
        }

        // was system info provided?
        if ($requestHeaders->has('X-Craft-System')) {
            foreach (explode(',', $requestHeaders->get('X-Craft-System')) as $info) {
                [$name, $installed] = array_pad(explode(':', $info, 2), 2, null);
                if ($installed !== null) {
                    [$version, $edition] = array_pad(explode(';', $installed, 2), 2, null);
                } else {
                    $version = null;
                    $edition = null;
                }

                if ($name === 'craft') {
                    $this->cmsVersion = $version;
                    $this->cmsEdition = $edition;
                    if (!$checkCraftHeaders) {
                        break;
                    }
                } else if ($checkCraftHeaders && strncmp($name, 'plugin-', 7) === 0) {
                    $pluginHandle = substr($name, 7);
                    $this->pluginVersions[$pluginHandle] = $version;
                    $this->pluginEditions[$pluginHandle] = $edition;
                }
            }

            if (!empty($this->pluginVersions)) {
                $this->plugins = Plugin::find()
                    ->handle(array_keys($this->pluginVersions))
                    ->with(['editions'])
                    ->indexBy('handle')
                    ->all();
            }
        }

        $e = null;
        /** @var CmsLicense|null $cmsLicense */
        $cmsLicense = null;

        try {
            if ($checkCraftHeaders) {
                $this->email = $requestHeaders->get('X-Craft-User-Email');
                if ($this->email !== null) {
                    if ((new EmailValidator())->validate($this->email, $error) === false) {
                        throw new BadRequestHttpException($error);
                    }
                }
            }

            $cmsLicenseKey = $checkCraftHeaders ? $requestHeaders->get('X-Craft-License') : null;
            if ($cmsLicenseKey === '__REQUEST__' || $cmsLicenseKey === '🙏') {
                $cmsLicense = $this->cmsLicenses[] = $this->createCmsLicense();
                $responseHeaders
                    ->set('x-craft-license', $cmsLicense->key)
                    ->set('x-craft-license-status', self::LICENSE_STATUS_VALID)
                    ->set('x-craft-license-domain', $cmsLicense->domain)
                    ->set('x-craft-license-edition', $cmsLicense->editionHandle);

                // was a host provided with the request?
                if ($requestHeaders->has('X-Craft-Host')) {
                    $responseHeaders->set('x-craft-allow-trials', (string)($allowTrials && $cmsLicense->domain === null));
                }
            } else if ($cmsLicenseKey !== null) {
                try {
                    $cmsLicenseManager = $this->module->getCmsLicenseManager();
                    $cmsLicense = $this->cmsLicenses[] = $cmsLicenseManager->getLicenseByKey($cmsLicenseKey);
                    $cmsLicenseStatus = self::LICENSE_STATUS_VALID;
                    $cmsLicenseDomain = $oldCmsLicenseDomain = $cmsLicense->domain;

                    // was a host provided with the request?
                    if (($host = $requestHeaders->get('X-Craft-Host')) !== null) {
                        // is it a public domain?
                        if (($domain = $cmsLicenseManager->normalizeDomain($host)) !== null) {
                            if ($cmsLicenseDomain !== null) {
                                if ($domain !== $cmsLicenseDomain) {
                                    $cmsLicenseStatus = self::LICENSE_STATUS_MISMATCHED;
                                }
                            } else {
                                // tie the license to this domain
                                $cmsLicense->domain = $cmsLicenseDomain = $domain;
                            }
                        }

                        $responseHeaders->set('x-craft-allow-trials', (string)($allowTrials && $domain === null));
                    }

                    // has Craft gone past its current allowed version?
                    if (
                        $this->cmsVersion !== null &&
                        $cmsLicense->expirable &&
                        $cmsLicenseStatus === self::LICENSE_STATUS_VALID &&
                        (
                            !$cmsLicense->lastAllowedVersion ||
                            Comparator::greaterThan($this->cmsVersion, $cmsLicense->lastAllowedVersion)
                        )
                    ) {
                        // we only have a problem with that if the license is expired
                        if ($cmsLicense->expired) {
                            $cmsLicenseStatus = self::LICENSE_STATUS_ASTRAY;
                        } else {
                            $cmsLicense->lastAllowedVersion = $this->cmsVersion;
                        }
                    }

                    $responseHeaders->set('x-craft-license-status', $cmsLicenseStatus);
                    $responseHeaders->set('x-craft-license-domain', $cmsLicenseDomain);
                    $responseHeaders->set('x-craft-license-edition', $cmsLicense->editionHandle);

                    if ($cmsLicense->expirable) {
                        $responseHeaders->set('x-craft-license-expired', (string)(int)$cmsLicense->expired);

                        $expiryDate = $cmsLicense->getExpiryDate();

                        if ($expiryDate) {
                            $responseHeaders->set('x-craft-license-expires-on', $expiryDate->format(\DateTime::ATOM));
                        }
                    }

                    $cmsLicense->lastStatus = $cmsLicenseStatus;

                    // update the license
                    $cmsLicense->lastActivityOn = new \DateTime('now', new \DateTimeZone('UTC'));
                    if ($this->cmsVersion !== null) {
                        $cmsLicense->lastVersion = $this->cmsVersion;
                    }
                    if ($this->cmsEdition !== null) {
                        $cmsLicense->lastEdition = $this->cmsEdition;
                    }
                    $cmsLicenseManager->saveLicense($cmsLicense, false, [
                        'domain',
                        'lastAllowedVersion',
                        'lastStatus',
                        'lastActivityOn',
                        'lastVersion',
                        'lastEdition',
                    ]);

                    // update the history
                    if ($cmsLicenseDomain !== $oldCmsLicenseDomain) {
                        $cmsLicenseManager->addHistory($cmsLicense->id, "tied to domain {$cmsLicenseDomain} by {$identity}");
                    }
                } catch (LicenseNotFoundException $e) {
                    $responseHeaders->set('x-craft-license-status', self::LICENSE_STATUS_INVALID);
                    $e = null;
                }
            }

            // collect the plugin licenses & their editions
            $pluginLicenseManager = $this->module->getPluginLicenseManager();
            $pluginLicenseKeys = $checkCraftHeaders ? $requestHeaders->get('X-Craft-Plugin-Licenses') : null;

            if ($pluginLicenseKeys !== null) {
                $newPluginLicenses = [];

                foreach (explode(',', $pluginLicenseKeys) as $pluginLicenseInfo) {
                    [$pluginHandle, $pluginLicenseKey] = explode(':', $pluginLicenseInfo);

                    // Ignore if the plugin is abandoned
                    if (!isset($this->plugins[$pluginHandle]) || $this->plugins[$pluginHandle]->abandoned) {
                        continue;
                    }

                    $pluginLicense = null;
                    switch ($pluginLicenseKey) {
                        case '__REQUEST__':
                            if ($this->email) {
                                // Only oblige if they are running a commercial edition
                                $edition = $this->installedPluginEdition($pluginHandle);
                                if ($edition->price != 0) {
                                    $pluginLicense = $this->createPluginLicense($this->plugins[$pluginHandle]);
                                    $newPluginLicenses[] = "$pluginHandle:$pluginLicense->key";
                                }
                            }
                            break;
                        case '__INVALID__':
                            break;
                        default:
                            try {
                                $pluginLicense = $pluginLicenseManager->getLicenseByKey($pluginLicenseKey, $pluginHandle, true);
                            } catch (LicenseNotFoundException $e) {
                                $this->pluginLicenseStatuses[$pluginHandle] = self::LICENSE_STATUS_INVALID;
                                $e = null;
                            } catch (InvalidPluginException $e) {
                                // Just ignore it
                                $e = null;
                            }
                    }

                    if ($pluginLicense) {
                        $pluginEdition = $pluginLicense->getEdition();
                        if (
                            $pluginLicense->trial ||
                            ($pluginEdition && $pluginEdition->enabled)
                        ) {
                            $this->pluginLicenses[$pluginHandle] = $pluginLicense;
                            if ($pluginEdition && $pluginEdition->enabled) {
                                $this->pluginLicenseEditions[$pluginHandle] = $pluginEdition;
                            }
                        }
                    }
                }

                if (!empty($newPluginLicenses)) {
                    $responseHeaders->set('x-craft-plugin-licenses', implode(',', $newPluginLicenses));
                }
            }

            // set the plugin license statuses
            foreach ($this->plugins as $pluginHandle => $plugin) {
                // ignore if the plugin was abandoned or they're using an invalid license key
                if (
                    $plugin->abandoned ||
                    (isset($this->pluginLicenseStatuses[$pluginHandle]) && $this->pluginLicenseStatuses[$pluginHandle] === self::LICENSE_STATUS_INVALID)
                ) {
                    continue;
                }

                // no license key yet?
                if (!isset($this->pluginLicenses[$pluginHandle]) || $this->pluginLicenses[$pluginHandle]->trial) {
                    // should there be?
                    $edition = $this->installedPluginEdition($pluginHandle);
                    if ($edition->price != 0) {
                        // Craft 3.6 added the 'trial' status, which applies regardless of whether there is a license key
                        if (!$this->cmsVersion || version_compare($this->cmsVersion, '3.6.0', '>=')) {
                            $this->pluginLicenseStatuses[$pluginHandle] = self::LICENSE_STATUS_TRIAL;
                        } else {
                            $this->pluginLicenseStatuses[$pluginHandle] = self::LICENSE_STATUS_INVALID;
                        }
                    }
                    continue;
                }

                $pluginLicense = $this->pluginLicenses[$pluginHandle];
                $pluginVersion = $this->pluginVersions[$pluginHandle];
                $pluginLicenseStatus = self::LICENSE_STATUS_VALID;
                $oldCmsLicenseId = $pluginLicense->cmsLicenseId;

                if ($cmsLicense !== null) {
                    if ($pluginLicense->cmsLicenseId) {
                        if ($pluginLicense->cmsLicenseId != $cmsLicense->id) {
                            $pluginLicenseStatus = self::LICENSE_STATUS_MISMATCHED;
                        }
                    } else {
                        // tie the license to this Craft license
                        $pluginLicense->cmsLicenseId = $cmsLicense->id;
                    }
                }

                // has the plugin gone past its current allowed version?
                if (
                    $pluginVersion !== null &&
                    $pluginLicense->expirable &&
                    $pluginLicenseStatus === self::LICENSE_STATUS_VALID &&
                    (
                        !$pluginLicense->lastAllowedVersion ||
                        Comparator::greaterThan($pluginVersion, $pluginLicense->lastAllowedVersion)
                    )
                ) {
                    // we only have a problem with that if the license is expired
                    if ($pluginLicense->expired) {
                        $pluginLicenseStatus = self::LICENSE_STATUS_ASTRAY;
                    } else {
                        $pluginLicense->lastAllowedVersion = $pluginVersion;
                    }
                }

                $this->pluginLicenseStatuses[$pluginHandle] = $pluginLicenseStatus;

                $pluginLicense->lastActivityOn = new \DateTime('now', new \DateTimeZone('UTC'));
                $pluginLicense->lastStatus = $pluginLicenseStatus;

                if ($pluginVersion !== null) {
                    $pluginLicense->lastVersion = $pluginVersion;
                }

                // update the license
                $pluginLicenseManager->saveLicense($pluginLicense, false, [
                    'cmsLicenseId',
                    'lastAllowedVersion',
                    'lastActivityOn',
                    'lastStatus',
                    'lastVersion',
                ]);

                // update the history
                if ($pluginLicense->cmsLicenseId !== $oldCmsLicenseId) {
                    $pluginLicenseManager->addHistory($pluginLicense->id, "attached to Craft license {$cmsLicense->shortKey} by {$identity}");
                }
            }

            // set the X-Craft-Plugin-License-Statuses header
            if (!empty($this->pluginLicenseStatuses)) {
                $pluginLicenseStatuses = [];
                foreach ($this->pluginLicenseStatuses as $pluginHandle => $pluginLicenseStatus) {
                    $pluginLicenseStatuses[] = "{$pluginHandle}:{$pluginLicenseStatus}";
                }
                $responseHeaders->set('x-craft-plugin-license-statuses', implode(',', $pluginLicenseStatuses));
            }

            // set the X-Craft-Plugin-License-Editions header
            if (!empty($this->pluginLicenseEditions)) {
                $pluginLicenseEditions = [];
                foreach ($this->pluginLicenseEditions as $pluginHandle => $pluginEdition) {
                    // Treat all Freeform < v3 & Sprout Forms < v3.2 licenses as "standard" edition
                    if (
                        ($pluginHandle === 'freeform' && Comparator::lessThan($this->pluginVersions[$pluginHandle], 3)) ||
                        ($pluginHandle === 'sprout-email' && Comparator::lessThan($this->pluginVersions[$pluginHandle], '4.3')) ||
                        ($pluginHandle === 'sprout-forms' && Comparator::lessThan($this->pluginVersions[$pluginHandle], '3.2')) ||
                        ($pluginHandle === 'sprout-seo' && Comparator::lessThan($this->pluginVersions[$pluginHandle], '4.1')) ||
                        ($pluginHandle === 'guide' && Comparator::lessThan($this->pluginVersions[$pluginHandle], 2)) ||
                        ($pluginHandle === 'calendar' && Comparator::lessThan($this->pluginVersions[$pluginHandle], 3)) ||
                        ($pluginHandle === 'sherlock' && Comparator::lessThan($this->pluginVersions[$pluginHandle], 3))
                    ) {
                        $pluginLicenseEditions[] = "{$pluginHandle}:standard";
                    } else {
                        $pluginLicenseEditions[] = "{$pluginHandle}:{$pluginEdition->handle}";
                    }
                }
                $responseHeaders->set('x-craft-plugin-license-editions', implode(',', $pluginLicenseEditions));
            }

            if (($result = YiiController::runAction($id, $params)) instanceof Response) {
                $response = $result;
            }
        } catch (\Throwable $e) {
            // log it and keep going
            Craft::$app->getErrorHandler()->logException($e);
            $response->setStatusCode($e instanceof HttpException && $e->statusCode ? $e->statusCode : 500);
        }

        $timestamp = Db::prepareDateForDb(new \DateTime());

        // should we update our installed plugin records?
        if ($this->cmsVersion !== null && $cmsLicense !== null) {
            // delete any cmslicense_plugins rows for this license
            $db->createCommand()
                ->delete(Table::CMSLICENSE_PLUGINS, [
                    'licenseId' => $cmsLicense->id,
                ])
                ->execute();

            $licensePluginsData = [];
            foreach ($this->plugins as $plugin) {
                $licensePluginsData[] = [$cmsLicense->id, $plugin->id, $timestamp];
            }
            try {
                $db->createCommand()
                    ->batchInsert(Table::CMSLICENSE_PLUGINS, ['licenseId', 'pluginId', 'timestamp'], $licensePluginsData, false)
                    ->execute();
            } catch (\Throwable $exception) {
                // don't let that ruin our day
            }
        }

        // log the request
        $db->createCommand()
            ->insert('apilog.requests', [
                'method' => $this->request->getMethod(),
                'uri' => $this->request->getUrl(),
                'ip' => $this->request->getUserIP(),
                'action' => $this->getUniqueId() . '/' . $id,
                'body' => $this->request->getRawBody(),
                'system' => $requestHeaders->get('X-Craft-System'),
                'platform' => $requestHeaders->get('X-Craft-Platform'),
                'host' => $requestHeaders->get('X-Craft-Host'),
                'userEmail' => $requestHeaders->get('X-Craft-User-Email'),
                'userIp' => $requestHeaders->get('X-Craft-User-Ip'),
                'timestamp' => $timestamp,
                'responseCode' => $response->getStatusCode(),
            ], false)
            ->execute();

        // get the request ID
        $this->requestId = (int)$db->getLastInsertID('apilog.requests');

        // log any licenses associated with the request
        foreach ($this->cmsLicenses as $cmsLicense) {
            $db->createCommand()
                ->insert('apilog.request_cmslicenses', [
                    'requestId' => $this->requestId,
                    'licenseId' => $cmsLicense->id,
                ], false)
                ->execute();
        }
        foreach ($this->pluginLicenses as $pluginLicense) {
            $db->createCommand()
                ->insert('apilog.request_pluginlicenses', [
                    'requestId' => $this->requestId,
                    'licenseId' => $pluginLicense->id,
                ], false)
                ->execute();
        }

        // if there was an exception, log it and return the error response
        if ($e !== null) {
            /** @var \Throwable $logException */
            $logException = $e;

            $statusCode = $response->getStatusCode();

            // Don't ever send an error response with a status code of 200
            if ($statusCode === 200) {
                $response->setStatusCode($statusCode = 500);
            }

            $sendErrorEmail = $statusCode >= 500 && $statusCode < 600;

            if ($sendErrorEmail) {
                $body = <<<EOL
- Request ID: {$this->requestId}
- Method: {$this->request->getMethod()}
- URI: {$this->request->getUrl()}
- IP: {$this->request->getUserIP()}
- Action: {$this->getUniqueId()}
- System: {$requestHeaders->get('X-Craft-System')}
- Platform: {$requestHeaders->get('X-Craft-Platform')}
- Host: {$requestHeaders->get('X-Craft-Host')}
- User email: {$requestHeaders->get('X-Craft-User-Email')}
- User IP: {$requestHeaders->get('X-Craft-User-Ip')}
- Response code: {$response->getStatusCode()}

Body:

```
{$this->request->getRawBody()}
```
EOL;
            } else {
                $body = '';
            }

            do {
                $exceptionType = get_class($logException);
                $stackTrace = $logException->getTraceAsString();

                if ($logException instanceof ValidationException) {
                    $errorJson = Json::encode($logException->errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                } else {
                    $errorJson = null;
                }

                $db->createCommand()
                    ->insert('apilog.request_errors', [
                        'requestId' => $this->requestId,
                        'type' => $exceptionType,
                        'message' => $logException->getMessage() . ($errorJson ? "\n\n" . $errorJson : ''),
                        'stackTrace' => $stackTrace,
                    ], false)
                    ->execute();

                if ($sendErrorEmail) {
                    $body .= <<<EOL


---

- Type: {$exceptionType}
- Message: {$logException->getMessage()}

Stack trace:

```
{$stackTrace}
```
EOL;

                    if ($errorJson) {
                        $body .= <<<EOD


Validation errors:

```
{$errorJson}
```
EOD;
                    }
                }

                // Cue up the previous exception
                $logException = $logException->getPrevious();

                if ($logException && $sendErrorEmail) {
                    $body .= <<<EOL


---

**Previous exception:**


EOL;
                }
            } while ($logException !== null);

            if ($sendErrorEmail) {
                try {
                    Craft::$app->getMailer()->compose()
                        ->setSubject('Craftnet API Error')
                        ->setTextBody($body)
                        ->setHtmlBody(Markdown::process($body, 'gfm'))
                        ->setTo(explode(',', App::env('API_ERROR_RECIPIENTS')))
                        ->send();

                    // Log it to bugsnag
                    Bugsnag::$plugin->bugsnagService->handleException($logException);
                } catch (\Throwable $e) {
                    // Just log and move on.
                    Craft::error('There was a problem sending the API error email: ' . $e->getMessage(), __METHOD__);
                }
            }

            // assemble and return the response
            $data = [
                'message' => $e instanceof UserException && $e->getMessage() ? $e->getMessage() : 'Server Error',
            ];
            if ($e instanceof ValidationException) {
                $data['errors'] = $e->errors;
            }

            return $this->asJson($data);
        }

        return $response;
    }

    /**
     * Returns the JSON-decoded request body.
     *
     * @param string|null $schema JSON schema to validate the body with (optional)
     *
     * @return stdClass|array
     * @throws BadRequestHttpException if the request body isn't valid JSON
     * @throws ValidationException if the data doesn't validate
     */
    protected function getPayload(string $schema = null)
    {
        $body = $this->request->getRawBody();
        try {
            $payload = (object)Json::decode($body, false);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException('Request body is not valid JSON', 0, $e);
        }

        if ($schema !== null && !$this->validatePayload($payload, $schema, $errors)) {
            throw new ValidationException($errors);
        }

        return $payload;
    }

    /**
     * Validates a payload against a JSON schema.
     *
     * @param stdClass $payload
     * @param string $schema
     * @param array $errors
     * @param string|null $paramPrefix
     * @return bool
     */
    protected function validatePayload(stdClass $payload, string $schema, &$errors = [], string $paramPrefix = null)
    {
        $validator = new Validator();
        $path = Craft::getAlias("@root/json-schemas/{$schema}.json");
        $validator->validate($payload, (object)['$ref' => 'file://' . $path]);

        if (!$validator->isValid()) {
            foreach ($validator->getErrors() as $error) {
                $errors[] = [
                    'param' => ($paramPrefix ? $paramPrefix . '.' : '') . $error['property'],
                    'message' => $error['message'],
                    'code' => self::ERROR_CODE_INVALID,
                ];
            }

            return false;
        }

        return true;
    }

    /**
     * Returns an array of validation errors for a ValdiationException based on a model's validation errors
     *
     * @param Model $model
     * @param string|null $paramPrefix
     * @return array
     */
    protected function modelErrors(Model $model, string $paramPrefix = null): array
    {
        $errors = [];

        foreach ($model->getErrors() as $attr => $attrErrors) {
            foreach ($attrErrors as $error) {
                $errors[] = [
                    'param' => ($paramPrefix !== null ? $paramPrefix . '.' : '') . $attr,
                    'message' => $error,
                    'code' => self::ERROR_CODE_INVALID,
                ];
            }
        }

        return $errors;
    }

    /**
     * Returns whether plugin install history should be included in plugin details.
     *
     * @return bool
     */
    protected function withInstallHistory(): bool
    {
        return (bool)$this->request->getQueryParam('withInstallHistory');
    }

    /**
     * Returns whether plugin icon SVG contents should be included in plugin details.
     *
     * @return bool
     */
    protected function withPluginIcons(): bool
    {
        return (bool)$this->request->getQueryParam('withPluginIcons');
    }

    /**
     * Returns whether plugin issue stats should be included in full plugin details
     *
     * @return bool
     */
    protected function withIssueStats(): bool
    {
        return (bool)$this->request->getQueryParam('withIssueStats');
    }

    /**
     * @param Plugin $plugin
     * @param bool $fullDetails
     *
     * @return array
     * @throws \craftnet\errors\MissingTokenException
     * @throws \yii\base\InvalidConfigException
     */
    protected function transformPlugin(Plugin $plugin, bool $fullDetails = true): array
    {
        $icon = $plugin->getIcon();
        $developer = $plugin->getDeveloper();

        // Return data
        $data = [
            'id' => $plugin->id,
            'packageId' => $plugin->packageId,
            'iconUrl' => $icon ? $icon->getUrl() . '?' . $icon->dateModified->getTimestamp() : null,
            'handle' => $plugin->handle,
            'name' => strip_tags($plugin->name),
            'shortDescription' => $plugin->shortDescription,
            'currency' => 'USD',
            'developerId' => $developer->id,
            'developerName' => strip_tags($developer->getDeveloperName()),
            'categoryIds' => ArrayHelper::getColumn($plugin->getCategories(), 'id'),
            'keywords' => ($plugin->keywords ? array_map('trim', explode(',', $plugin->keywords)) : []),
            'version' => $plugin->latestVersion,
            'activeInstalls' => $plugin->activeInstalls,
            'packageName' => $plugin->packageName,
            'lastUpdate' => ($plugin->latestVersionTime ?? $plugin->dateUpdated)->format(\DateTime::ATOM),
            'abandoned' => $plugin->abandoned,
        ];

        foreach ($plugin->getEditions() as $edition) {
            $data['editions'][] = [
                'id' => $edition->id,
                'name' => $edition->name,
                'handle' => $edition->handle,
                'price' => (float)$edition->price ?: null,
                'renewalPrice' => (float)$edition->renewalPrice ?: null,
                'features' => $edition->features ?? [],
            ];
        }

        if ($fullDetails) {
            // Screenshots
            $screenshotUrls = [];
            $thumbnailUrls = [];
            $screenshotIds = [];

            foreach ($plugin->getScreenshots() as $screenshot) {
                $screenshotUrls[] = $screenshot->getUrl([
                        'width' => 2200,
                        'height' => 2200,
                        'mode' => 'fit',
                    ]) . '?' . $screenshot->dateModified->getTimestamp();

                $thumbnailUrls[] = $screenshot->getUrl([
                        'width' => 550,
                    ]) . '?' . $screenshot->dateModified->getTimestamp();

                $screenshotIds[] = $screenshot->getId();
            }

            $longDescription = Markdown::process($plugin->longDescription, 'gfm');
            $longDescription = HtmlPurifier::process($longDescription);

            // Figure out which versions of Craft the plugin is compatible with
            $cmsConstraints = [
                'Craft 3' => '^3.0',
                'Craft 4' => '^4.0',
            ];
            $compatibleCmsVersions = [];
            $packageManager = $this->module->getPackageManager();
            foreach ($cmsConstraints as $cmsVersionLabel => $cmsConstraint) {
                if ($plugin->compatibleCmsVersion && Semver::satisfies($plugin->compatibleCmsVersion, $cmsConstraint)) {
                    // The plugin was loaded with a similar Craft version in mind. no need to fetch the Craft version ourselves
                    $compatibleCmsVersions[] = $cmsVersionLabel;
                } else {
                    $latestCmsVersion = $packageManager->getLatestVersion('craftcms/cms', 'dev', $cmsConstraint);
                    if ($latestCmsVersion) {
                        $compatible = Plugin::find()
                            ->id($plugin->id)
                            ->withLatestReleaseInfo(cmsVersion: $latestCmsVersion)
                            ->exists();
                        if ($compatible) {
                            $compatibleCmsVersions[] = $cmsVersionLabel;
                        }
                    }
                }
            }

            $data['compatibility'] = implode(', ', $compatibleCmsVersions);
            $data['status'] = $plugin->status;
            $data['iconId'] = $plugin->iconId;
            $data['longDescription'] = $longDescription;
            $data['documentationUrl'] = $plugin->documentationUrl;
            $data['changelogUrl'] = $plugin->getPackage()->getVcs()->getChangelogUrl();
            $data['repository'] = $plugin->repository;
            $data['license'] = $plugin->license;
            $data['developerUrl'] = $developer->developerUrl;
            $data['screenshotUrls'] = $screenshotUrls;
            $data['thumbnailUrls'] = $thumbnailUrls;
            $data['screenshotIds'] = $screenshotIds;

            // Replacement
            $replacement = $plugin->getReplacement();
            $data['replacementName'] = $replacement->name ?? null;
            $data['replacementHandle'] = $replacement->handle ?? null;

            // PHP version
            if (!$data['phpVersionCompatible'] = $this->_checkPhpRequirement($plugin->latestVersionId, $phpConstraint, $incompatiblePhpVersion)) {
                $data['phpConstraint'] = $phpConstraint;
                $data['incompatiblePhpVersion'] = $incompatiblePhpVersion;
            }
        }

        if ($this->withInstallHistory()) {
            // Install count history
            $installHistory = (new Query())
                ->select(['activeInstalls', 'date'])
                ->from(['craftnet_plugin_installs'])
                ->where(['pluginId' => $plugin->id])
                ->orderBy(['date' => SORT_DESC])
                ->limit(30)
                ->all();
            $data['installHistory'] = array_reverse($installHistory);
        }

        if ($icon && $this->withPluginIcons()) {
            $iconContent = $this->pluginIconContents($plugin, $icon);
            if ($iconContent) {
                $data['icon'] = $iconContent;
            }
        }

        if ($this->withIssueStats()) {
            $data['issueStats'] = (new Query())
                ->select(['period', 'openIssues', 'closedIssues', 'openPulls', 'mergedPulls'])
                ->from(['craftnet_plugin_issue_stats'])
                ->where(['pluginId' => $plugin->id, 'period' => 30])
                ->all();
        }

        return $data;
    }

    protected function pluginIconContents(Plugin $plugin, ?Asset $icon = null): ?string
    {
        $icon = $icon ?? $plugin->getIcon();

        if (!$icon) {
            return null;
        }

        $cacheKey = "pluginIcon:$plugin->id";
        $iconContent = Cache::get($cacheKey);

        if ($iconContent) {
            return $iconContent;
        }

        try {
            $iconContent = $icon->getContents();
        } catch (\Throwable $e) {
            Craft::warning("Could not fetch the plugin icon for $plugin->handle: {$e->getMessage()}", __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);
        }

        if (!$iconContent) {
            return null;
        }

        // Sanitize
        $iconContent = Html::sanitizeSvg($iconContent);
        // Remove the XML declaration
        $iconContent = preg_replace('/<\?xml.*?\?>\s*/', '', $iconContent);
        // Namespace class names and IDs
        $iconContent = Html::namespaceAttributes($iconContent, StringHelper::randomString(10), true);

        Cache::set($cacheKey, $iconContent, [
            Cache::TAG_PLUGIN_ICONS,
            Cache::pluginIconTag($plugin),
        ]);

        return $iconContent;
    }

    /**
     * Returns whether the Craft install appears to be compatible with the given package version.
     *
     * @param int $versionId
     * @param string|null $phpConstraint
     * @param string|null $incompatiblePhpVersion
     * @return bool
     */
    private function _checkPhpRequirement(?int $versionId, ?string &$phpConstraint, ?string &$incompatiblePhpVersion = null): bool
    {
        if (!$versionId || !isset($this->platformVersions['php']) && !isset($this->platformVersions['composer-php'])) {
            return true;
        }

        $phpConstraint = Module::getInstance()->getPackageManager()->getPhpConstraintByVersionId($versionId);

        if (!$phpConstraint) {
            return true;
        }

        if (isset($this->platformVersions['php']) && !Semver::satisfies($this->platformVersions['php'], $phpConstraint)) {
            $incompatiblePhpVersion = 'php';
            return false;
        }

        if (isset($this->platformVersions['composer-php']) && !Semver::satisfies($this->platformVersions['composer-php'], $phpConstraint)) {
            $incompatiblePhpVersion = 'composer-php';
            return false;
        }

        return true;
    }

    /**
     * @param array $plugins
     * @return array
     * @throws \craftnet\errors\MissingTokenException
     * @throws \yii\base\InvalidConfigException
     */
    protected function transformPlugins(array $plugins): array
    {
        $ret = [];

        foreach ($plugins as $plugin) {
            $ret[] = $this->transformPlugin($plugin, false);
        }

        return $ret;
    }

    /**
     * Returns the authorized user, if any.
     *
     * @return User|UserBehavior|null
     * @throws BadRequestHttpException
     */
    protected function getAuthUser()
    {
        if ($user = Craft::$app->getUser()->getIdentity()) {
            return $user;
        }

        try {
            if (
                ($accessToken = OauthServer::getInstance()->getAccessTokens()->getAccessTokenFromRequest()) &&
                $accessToken->userId &&
                $user = User::findOne($accessToken->userId)
            ) {
                return $user;
            }
        } catch (ExpiredTokenException $e) {
            throw new BadRequestHttpException($e->getMessage());
        } catch (\InvalidArgumentException $e) {
        }

        [$username, $password] = $this->request->getAuthCredentials();

        if (!$username) {
            return null;
        }

        if (!$password) {
            throw new BadRequestHttpException('Invalid Credentials');
        }

        /** @var User|UserBehavior|null $user */
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($username);

        if (
            $user === null ||
            $user->apiToken === null ||
            $user->getStatus() !== User::STATUS_ACTIVE ||
            Craft::$app->getSecurity()->validatePassword($password, $user->apiToken) === false
        ) {
            throw new BadRequestHttpException('Invalid Credentials');
        }

        return $user;
    }

    /**
     * Returns the offset and limit that should be used for list requests.
     *
     * @param int $page
     * @param int $perPage
     * @return int[]
     */
    protected function page2offset(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);
        $offset = ($page - 1) * $perPage;
        return [$offset, $perPage];
    }

    /**
     * Returns the installed edition for the given plugin.
     *
     * @param string $pluginHandle
     * @return PluginEdition
     */
    protected function installedPluginEdition(string $pluginHandle): PluginEdition
    {
        if (!isset($this->plugins[$pluginHandle])) {
            throw new InvalidArgumentException("Invalid installed plugin handle: $pluginHandle");
        }

        $plugin = $this->plugins[$pluginHandle];
        if (isset($this->pluginEditions[$pluginHandle])) {
            try {
                return $plugin->getEdition($this->pluginEditions[$pluginHandle]);
            } catch (InvalidArgumentException $e) {
            }
        }

        // just assume the first
        return $plugin->getEditions()[0];
    }

    /**
     * Creates a new CMS license.
     *
     * @return CmsLicense
     * @throws Exception
     */
    protected function createCmsLicense(): CmsLicense
    {
        if ($this->email === null) {
            throw new BadRequestHttpException('Missing X-Craft-User-Email Header');
        }

        $license = new CmsLicense([
            'expirable' => true,
            'expired' => false,
            'autoRenew' => false,
            'editionHandle' => CmsLicenseManager::EDITION_SOLO,
            'email' => $this->email,
            'domain' => $this->request->getHeaders()->get('X-Craft-Host'),
            'key' => KeyHelper::generateCmsKey(),
            'lastEdition' => $this->cmsEdition,
            'lastVersion' => $this->cmsVersion,
            'lastActivityOn' => new \DateTime('now', new \DateTimeZone('UTC')),
        ]);

        $manager = $this->module->getCmsLicenseManager();
        if (!$manager->saveLicense($license)) {
            throw new Exception('Could not create CMS license: ' . implode(', ', $license->getErrorSummary(true)));
        }

        $note = "created by {$this->email}";
        if ($license->domain !== null) {
            $note .= " for domain {$license->domain}";
        }
        $manager->addHistory($license->id, $note);

        return $license;
    }

    /**
     * Creates a new plugin license.
     *
     * @param Plugin $plugin
     * @return PluginLicense
     * @throws Exception
     */
    protected function createPluginLicense(Plugin $plugin): PluginLicense
    {
        if ($this->email === null) {
            throw new BadRequestHttpException('Missing X-Craft-User-Email Header');
        }

        $license = new PluginLicense([
            'pluginId' => $plugin->id,
            'pluginHandle' => $plugin->handle,
            'trial' => true,
            'email' => $this->email,
            'key' => KeyHelper::generatePluginKey(),
            'lastVersion' => $this->pluginVersions[$plugin->handle],
            'lastActivityOn' => new \DateTime('now', new \DateTimeZone('UTC')),
        ]);

        $manager = $this->module->getPluginLicenseManager();
        if (!$manager->saveLicense($license)) {
            throw new Exception('Could not create plugin license: ' . implode(', ', $license->getErrorSummary(true)));
        }

        $manager->addHistory($license->id, "created by $this->email");

        return $license;
    }

    /**
     * Returns the Craft version that should be passed to plugin queries’ `withLatestReleaseInfo` params.
     *
     * @return string|null
     */
    protected function cmsVersionForPluginQueries(): ?string
    {
        if (!isset($this->_cmsVersionForPluginQueries)) {
            if ($cmsConstraint = $this->request->getQueryParam('cmsConstraint')) {
                $cmsVersion = $this->module->getPackageManager()
                        ->getLatestVersion('craftcms/cms', null, $cmsConstraint) ?? '0.0';
            } else if ($this->cmsVersion) {
                $cmsVersion = $this->cmsVersion;
            }
            $this->_cmsVersionForPluginQueries = $cmsVersion ?? false;
        }

        return $this->_cmsVersionForPluginQueries ?: null;
    }

    /**
     * Returns the major Craft version that this request is for.
     *
     * @return int
     */
    protected function cmsMajorVersion(): int
    {
        $cmsVersion = $this->cmsVersionForPluginQueries();
        return (int)($cmsVersion ?? 3);
    }
}
