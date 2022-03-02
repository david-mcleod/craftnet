<?php
/**
 * Front module for Craft CMS 3.x
 *
 * Front integration
 *
 * @link      https://craftcms.com
 * @copyright Copyright (c) 2019 Luke Holder
 */

namespace craftnet\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\Json;
use craft\web\Controller;
use craftnet\behaviors\UserBehavior;
use craftnet\cms\CmsLicense;
use craftnet\db\Table;
use yii\db\Expression;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * @author    Luke Holder
 * @package   FrontModule
 * @since     1.0.0
 */
class FrontController extends Controller
{

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected $allowAnonymous = true;

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws UnauthorizedHttpException
     */
    public function beforeAction($action)
    {
        // Validate the request
        $secret = Craft::$app->request->getQueryParam('auth_secret');
        if (!$secret || !hash_equals($secret, App::env('FRONT_AUTH_SECRET'))) {
            throw new UnauthorizedHttpException();
        }

        // Only allow to be framed from Front
        Craft::$app->response->headers
            ->set('X-Frame-Options', 'allow-from https://app.frontapp.com/');

        return parent::beforeAction($action);
    }

    /**
     * This is the main sidebar in Front.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('front/index.twig', []);
    }

    /**
     * @return Response
     */
    public function actionLoadData(): Response
    {
        $body = Json::decode(Craft::$app->request->getRawBody());
        $email = $body['email'] ?? null;

        if (!$email) {
            return $this->asJson([]);
        }

        if (($pos = strpos($email, '@')) < 1) {
            return $this->asJson([]);
        }

        $domain = substr($email, $pos + 1);
        $data = [];

        /** @var User|UserBehavior|null $user */
        $user = User::find()->email($email)->one();

        if ($user) {
            /** @var Asset|null $photo */
            $photo = $user->getPhoto();

            $meta = [];

            if ($developerName = $user->getFieldValue('developerName')) {
                $meta[] = [
                    'label' => 'Developer Name',
                    'value' => $developerName,
                ];
            }

            if ($user->developerUrl) {
                $meta[] = [
                    'label' => 'Developer URL',
                    'value' => str_replace(['http://', 'https://'], '', trim($user->developerUrl, '/')),
                    'url' => $user->developerUrl,
                ];
            }

            if ($user->location) {
                $meta[] = [
                    'label' => 'Location',
                    'value' => $user->location,
                ];
            }

            $data['user'] = [
                'email' => mb_strtolower($user->email),
                'photoUrl' => $photo ? $photo->url : null,
                'name' => $user->getName(),
                'meta' => $meta ?: null,
            ];
        }

        // Are they using an email provider?
        if (in_array($domain, [
            // https://en.wikipedia.org/wiki/Comparison_of_webmail_providers
            'aol.com',
            'fastmail.com',
            'gmail.com',
            'hotmail.com',
            'icloud.com',
            'mac.com',
            'me.com',
            'outlook.com',
            'protonmail.com',
            'yahoo.com',
        ], true)) {
            $emailCondition = new Expression('[[email]] ilike :email', ['email' => $email]);
        } else {
            $emailCondition = new Expression('[[email]] ilike :domain', ['domain' => '%@' . $domain]);
        }

        $results = (new Query())
            ->from([Table::CMSLICENSES])
            ->where($emailCondition)
            ->andWhere(['editionHandle' => 'pro'])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        foreach ($results as $result) {
            $license = new CmsLicense($result);
            $data['licenses'][] = [
                'key' => $license->getShortKey(),
                'domain' => $license->domain,
                'email' => mb_strtolower($license->email),
                'expiresOn' => $license->expirable ? $license->expiresOn->format('n/d/y') : 'Forever',
                'expired' => $license->expired,
            ];
        }

        return $this->asJson($data);
    }

    /**
     * @return Response
     */
    public function actionGetLicenseInfo(): Response
    {
        $domain = $this->request->getParam('domain', '');
        $email = $this->request->getParam('email', '');
        $key = $this->request->getParam('key', '');
        $key = trim(preg_replace('/\s+/', '', $key));

        $licenses = (new Query())
            ->select(['*'])
            ->from(Table::CMSLICENSES)
            ->where([
                'or',
                ['key' => $key],
                ['email' => $email],
                ['domain' => $domain],
            ])
            ->all();

        if (!$licenses) {
            return $this->asErrorJson('No licenses found.');
        }

        $data = [];
        $data['licenses'] = $licenses;
        $data['count'] = count($licenses);
        $data['success'] = true;
        return $this->asJson($data);
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionScrubConversation(): Response
    {
        $conversationId = $this->request->getRequiredBodyParam('conversationId');
        $token = App::env('FRONT_TOKEN');

        // request conversation details
        $apiHost = 'https://api2.frontapp.com/';
        $config = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ];

        $client = Craft::createGuzzleClient($config);
        $response = $client->request('GET', $apiHost . 'conversations/' . $conversationId);
        $conversationData = Json::decodeIfJson($response->getBody()->getContents());

        $recipient = null;
        $licenseKey = null;
        $pattern = '/(?:\S{50}\s{0,2}){5}/';

        if (is_array($conversationData)) {
            $recipient = $conversationData['recipient']['handle'] ?? null;
        }

        $response = $response = $client->request('GET', $apiHost . 'conversations/' . $conversationId . '/messages');
        $messages = Json::decodeIfJson($response->getBody()->getContents());

        if (is_array($messages)) {
            $messages = $messages['_results'] ?? [];

            foreach ($messages as $message) {
                if (!empty($message['attachments'])) {
                    foreach ($message['attachments'] as $attachment) {
                        if (!empty($attachment['filename']) && $attachment['filename'] == 'license.key' && $attachment['size'] < 512) {
                            $licenseKey = $client->request('GET', $attachment['url'])->getBody()->getContents();
                            break 2;
                        }
                    }
                }

                if (preg_match($pattern, $message['text'] ?? (strip_tags($message['body']) ?? ''), $matches)) {
                    $licenseKey = $matches[0];
                    break;
                }
            }
        }

        $data['licenseKey'] = $licenseKey;
        $data['email'] = $recipient;

        return $this->asJson($data);
    }
}
