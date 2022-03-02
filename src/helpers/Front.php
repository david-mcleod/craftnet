<?php

namespace craftnet\helpers;

use Craft;
use craft\commerce\elements\Subscription;
use craft\elements\User;
use craft\helpers\App;
use craftnet\controllers\id\DeveloperSupportController;
use GuzzleHttp\Client;
use yii\db\Expression;
use Zendesk\API\HttpClient;
use Zendesk\API\Utilities\Auth;

abstract class Front
{
    /**
     * Returns a new Front HTTP client.
     *
     * @return Client
     */
    public static function client(): Client
    {
        return Craft::createGuzzleClient([
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.App::env('FRONT_API_TOKEN'),
            ]
        ]);
    }

    /**
     * Returns the support plan tag that should be applied to a ticket, per the customer email.
     *
     * @param string $email
     * @return string 'basic', 'pro', or 'premium'
     */
    public static function plan(string $email): string
    {
        $user = User::find()
            ->andWhere(new Expression('lower([[email]]) = :email', [':email' => $email]))
            ->one();

        if ($user) {
            // Are we manually setting their support plan?
            $supportPlan = (string)$user->supportPlan;
            if (
                $supportPlan &&
                $supportPlan !== DeveloperSupportController::PLAN_BASIC &&
                (!$user->supportPlanExpiryDate || $user->supportPlanExpiryDate > new \DateTime())
            ) {
                return $user->supportPlan;
            }

            if (self::checkPlan($user->id, DeveloperSupportController::PLAN_PREMIUM)) {
                return DeveloperSupportController::PLAN_PREMIUM;
            }

            if (self::checkPlan($user->id, DeveloperSupportController::PLAN_PRO)) {
                return DeveloperSupportController::PLAN_PRO;
            }
        }

        return DeveloperSupportController::PLAN_BASIC;
    }

    /**
     * Returns whether a user ID belongs to a support plan.
     *
     * @param int $userId
     * @param string $plan
     * @return bool
     */
    private static function checkPlan(int $userId, string $plan): bool
    {
        return Subscription::find()
            ->plan($plan)
            ->userId($userId)
            ->isExpired(false)
            ->exists();
    }
}
