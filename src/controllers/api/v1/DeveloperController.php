<?php

namespace craftnet\controllers\api\v1;

use craft\elements\User;
use craftnet\behaviors\UserBehavior;
use craftnet\controllers\api\BaseApiController;
use craftnet\partners\Partner;
use yii\web\Response;

/**
 * Class DeveloperController
 */
class DeveloperController extends BaseApiController
{
    // Public Methods
    // =========================================================================

    /**
     * Handles /v1/developer/<userId> requests.
     *
     * @return Response
     */
    public function actionIndex($userId): Response
    {
        /** @var UserBehavior|User $user */
        $user = User::find()->id($userId)->status(null)->one();

        if (!$user) {
            return $this->asErrorJson("Couldn’t find developer");
        }

        $data = [
            'developerName' => strip_tags($user->getDeveloperName()),
            'developerUrl' => $user->developerUrl,
            'location' => $user->location,
            'username' => $user->username,
            'fullName' => strip_tags($user->getFullName()),
            'email' => $user->email,
            'photoUrl' => ($user->getPhoto() ? $user->getPhoto()->getUrl(['width' => 200, 'height' => 200, 'mode' => 'fit']) : null),
        ];

        // Are they a partner?
        $partner = Partner::find()
            ->ownerId($user->id)
            ->status(null)
            ->one();

        if ($partner) {
            $data['partnerInfo'] = [
                'profileUrl' => "https://craftcms.com/partners/$partner->websiteSlug",
                'isCraftVerified' => $partner->isCraftVerified,
                'isCommerceVerified' => $partner->isCommerceVerified,
                'isEnterpriseVerified' => $partner->isEnterpriseVerified,
            ];
        }

        return $this->asJson($data);
    }
}
