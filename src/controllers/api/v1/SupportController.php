<?php

namespace craftnet\controllers\api\v1;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\i18n\Locale;
use craft\web\UploadedFile;
use craftnet\cms\CmsLicense;
use craftnet\controllers\api\BaseApiController;
use craftnet\events\FrontEvent;
use craftnet\helpers\Front;
use GuzzleHttp\RequestOptions;
use yii\web\Response;

/**
 * Class SupportController
 */
class SupportController extends BaseApiController
{
    /**
     * @event ZendeskEvent
     */
    const EVENT_CREATE_TICKET = 'createTicket';

    /**
     * Creates a new support request
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionCreate(): Response
    {
        $client = Front::client();
        $request = Craft::$app->getRequest();

        Craft::error('Support - Made it to create.', __METHOD__);
        $requestHeaders = $this->request->getHeaders();
        $body = $this->request->getRequiredBodyParam('message');

        $info = [];
        /** @var CmsLicense $cmsLicense */
        $cmsLicense = reset($this->cmsLicenses) ?: null;
        $formatter = Craft::$app->getFormatter();
        if ($this->cmsEdition !== null || $this->cmsVersion !== null) {
            $craftInfo = 'Craft' .
                ($this->cmsEdition !== null ? ' ' . ucfirst($this->cmsEdition) : '') .
                ($this->cmsVersion !== null ? ' ' . $this->cmsVersion : '');
            if ($cmsLicense && $cmsLicense->editionHandle !== $this->cmsEdition) {
                $craftInfo .= ' (trial)';
            }
            $info[] = $craftInfo;
        }
        if ($cmsLicense) {
            $licenseInfo = [
                '`' . $cmsLicense->getShortKey() . '`',
                'created on ' . $formatter->asDate($cmsLicense->dateCreated, Locale::LENGTH_SHORT),
            ];
            if ($cmsLicense->expirable && $cmsLicense->expiresOn) {
                $licenseInfo[] .= ($cmsLicense->expired ? 'expired on' : 'expires on') .
                    ' ' . $formatter->asDate($cmsLicense->expiresOn, Locale::LENGTH_SHORT);
            }
            if ($cmsLicense->domain) {
                $licenseInfo[] = 'for ' . $cmsLicense->domain;
            }
            $info[] = 'License: ' . implode(', ', $licenseInfo);
        }
        if (!empty($this->pluginVersions)) {
            $pluginInfos = [];
            foreach ($this->pluginVersions as $pluginHandle => $pluginVersion) {
                if ($plugin = $this->plugins[$pluginHandle] ?? null) {
                    $pluginInfo = "[{$plugin->name}](https://plugins.craftcms.com/{$plugin->handle})";
                } else {
                    $pluginInfo = $pluginHandle;
                }
                if (($edition = $this->pluginEditions[$pluginHandle] ?? null) && $edition !== 'standard') {
                    $pluginInfo .= ' ' . ucfirst($edition);
                }
                $pluginInfo .= ' ' . $pluginVersion;
                $pluginInfos[] = $pluginInfo;
            }
            $info[] = 'Plugins: ' . implode(', ', $pluginInfos);
        }
        if (($host = $requestHeaders->get('X-Craft-Host')) !== null) {
            $info[] = 'Host: ' . $host;
        }
        if (!empty($info)) {
            $body .= "\n\n---\n\n" . implode("  \n", $info);
        }

        Craft::error('Support - $FILES = ' . var_dump($_FILES), __METHOD__);

        $parts = [
            [
                'name' => 'sender[handle]',
                'contents' => $request->getRequiredBodyParam('email'),
            ],
            [
                'name' => 'sender[name]',
                'contents' => $request->getRequiredBodyParam('name'),
            ],
            [
                'name' => 'to[]',
                'contents' => App::env('SUPPORT_TO_EMAIL'),
            ],
            [
                'name' => 'subject',
                'contents' => App::env('SUPPORT_SUBJECT'),
            ],
            [
                'name' => 'body',
                'contents' => $body,
            ],
            [
                'name' => 'body_format',
                'contents' => 'markdown',
            ],
            [
                'name' => 'external_id',
                'contents' => StringHelper::UUID(),
            ],
            [
                'name' => 'created_at',
                'contents' => time(),
            ],
            [
                'name' => 'type',
                'contents' => 'email',
            ],
            [
                'name' => 'tags[]',
                'contents' => App::env('SUPPORT_TAG'),
            ],
            [
                'name' => 'metadata[thread_ref]',
                'contents' => StringHelper::UUID(),
            ],
            [
                'name' => 'metadata[is_inbound]',
                'contents' => 'true',
            ],
            [
                'name' => 'metadata[is_archived]',
                'contents' => 'false',
            ],
            [
                'name' => 'metadata[should_skip_rules]',
                'contents' => App::env('SUPPORT_SKIP_RULES') ?: 'true',
            ],
        ];

        $attachments = UploadedFile::getInstancesByName('attachments');
        Craft::error('Support - count($attachments) 1 = ' . count($attachments), __METHOD__);
        if (empty($attachments) && $attachment = UploadedFile::getInstanceByName('attachment')) {
            $attachments = [$attachment];
            Craft::error('Support - count($attachments) 2 = ' . count($attachments), __METHOD__);
        }

        if (!empty($attachments)) {
            Craft::error('Support - Found ' . count($attachments) . ' attachments to send to ZenDesk.', __METHOD__);
            foreach ($attachments as $i => $attachment) {
                Craft::error('Support - Attachment Name: ' . $attachment->name, __METHOD__);
                if (!empty($attachment->tempName)) {
                    Craft::error('Support - Attachment Temp Name: ' . $attachment->tempName, __METHOD__);
                    $parts[] = [
                        'name' => "attachments[{$i}]",
                        'contents' => fopen($attachment->tempName, 'rb'),
                        'filename' => $attachment->name,
                    ];
                }
            }
        }

        $email = mb_strtolower($this->request->getRequiredBodyParam('email'));
        $plan = Front::plan($email);
        $tags = [App::env('SUPPORT_TAG'), $plan];

        $response = $client->post('/inboxes/' . App::env('SUPPORT_INBOX_ID') . '/imported_messages', [
            RequestOptions::MULTIPART => $parts,
        ]);

        $decodedResponse = Json::decodeIfJson($response->getBody()->getContents());
        if ($decodedResponse) {
            $conversationId = $decodedResponse['message_uid'];

            $this->trigger(self::EVENT_CREATE_TICKET, new FrontEvent([
                'ticketId' => $conversationId,
                'email' => $email,
                'plan' => $plan,
                'tags' => $tags,
            ]));

            return $this->asJson([
                'sent' => true,
            ]);
        }

        return $this->asJson([
            'sent' => false,
        ]);
    }
}
