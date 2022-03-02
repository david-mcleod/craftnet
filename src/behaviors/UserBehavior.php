<?php

namespace craftnet\behaviors;

use Craft;
use craft\base\Element;
use craft\elements\User;
use craft\events\DefineRulesEvent;
use craft\events\ModelEvent;
use craftnet\db\Table;
use craftnet\developers\EmailVerifier;
use craftnet\developers\FundsManager;
use craftnet\helpers\KeyHelper;
use craftnet\partners\Partner;
use craftnet\plugins\Plugin;
use yii\base\Behavior;
use yii\base\Exception;

/**
 * The Developer behavior extends users with plugin developer-related features.
 *
 * @property EmailVerifier $emailVerifier
 * @property FundsManager $fundsManager
 * @property User $owner
 * @property Plugin[] $plugins
 */
class UserBehavior extends Behavior
{
    /**
     * @var string|null
     */
    public $country;

    /**
     * @var string|null
     */
    public $stripeAccessToken;

    /**
     * @var string|null
     */
    public $stripeAccount;

    /**
     * @var string|null
     */
    public $payPalEmail;

    /**
     * @var string|null
     */
    public $apiToken;

    /**
     * @var Plugin[]|null
     */
    private $_plugins;

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            Element::EVENT_BEFORE_VALIDATE => [$this, 'beforeValidate'],
            Element::EVENT_DEFINE_RULES => [$this, 'defineRules'],
            Element::EVENT_BEFORE_SAVE => [$this, 'beforeSave'],
            Element::EVENT_AFTER_SAVE => [$this, 'afterSave'],
        ];
    }

    /**
     * @return Partner
     * @throws Exception if the partner element couldn't be created
     */
    public function getPartner(): Partner
    {
        $partner = Partner::find()
            ->ownerId($this->owner->id)
            ->status(null)
            ->one();

        if (!$partner) {
            $partner = new Partner();
            $partner->ownerId = $this->owner->id;
            if (!Craft::$app->getElements()->saveElement($partner)) {
                throw new Exception('Couldn\'t save partner: ' . implode(', ', $partner->getErrorSummary(true)));
            }
        }

        return $partner;
    }

    /**
     * @return string
     */
    public function getDeveloperName(): string
    {
        return $this->owner->getFieldValue('developerName') ?: $this->owner->getName();
    }

    /**
     * @return Plugin[]
     */
    public function getPlugins(): array
    {
        if ($this->_plugins !== null) {
            return $this->_plugins;
        }

        return $this->_plugins = Plugin::find()
            ->developerId($this->owner->id)
            ->anyStatus()
            ->all();
    }

    /**
     * @return EmailVerifier
     */
    public function getEmailVerifier(): EmailVerifier
    {
        return new EmailVerifier($this->owner);
    }

    /**
     * @return FundsManager
     */
    public function getFundsManager(): FundsManager
    {
        return new FundsManager($this->owner);
    }

    /**
     * Generates a new API token for the developer.
     *
     * @return string the new API token
     */
    public function generateApiToken(): string
    {
        $token = KeyHelper::generateApiToken();
        $this->apiToken = Craft::$app->getSecurity()->generatePasswordHash($token, 4);
        $this->saveDeveloperInfo();
        return $token;
    }

    /**
     * Handles pre-validation stuff
     *
     * @return void
     */
    public function beforeValidate(): void
    {
        // Only set the PayPal email if we're saving the current user and they are a developer
        if (
            (Craft::$app->getRequest()->getIsCpRequest() || $this->owner->getIsCurrent()) &&
            $this->owner->isInGroup('developers') &&
            ($payPalEmail = Craft::$app->getRequest()->getBodyParam('payPalEmail')) !== null
        ) {
            $this->payPalEmail = $payPalEmail ?: null;
        }
    }

    /**
     * Defines validation rules for the user
     *
     * @param DefineRulesEvent $event
     * @return void
     */
    public function defineRules(DefineRulesEvent $event): void
    {
        $event->rules[] = ['payPalEmail', 'email'];
    }

    /**
     * Handles pre-user-save stuff
     */
    public function beforeSave(ModelEvent $event)
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        $isAdmin = $currentUser && ($currentUser->isInGroup('admins') || $currentUser->admin);
        $request = Craft::$app->getRequest();

        if (
            $currentUser &&
            !$isAdmin &&
            $request->getIsSiteRequest() &&
            $request->getIsPost()
        ) {
            $postedEnableShowcaseFeatures = $request->getBodyParam('fields.enableShowcaseFeatures');
            $postedEnablePartnerFeatures = $request->getBodyParam('fields.enablePartnerFeatures');

            if (
                ($postedEnableShowcaseFeatures !== null && $postedEnableShowcaseFeatures != $currentUser->enableShowcaseFeatures) ||
                ($postedEnablePartnerFeatures !== null && $postedEnablePartnerFeatures != $currentUser->enablePartnerFeatures)
            ) {
                Craft::warning("There was a validation error while saving the user's partner status", __METHOD__);
                $event->sender->addError('enablePartnerFeatures', "There was a problem saving your partner status.");
                $event->isValid = false;
            }

        }
    }

    /**
     * Handles post-user-save stuff
     */
    public function afterSave()
    {
        $isDeveloper = $this->owner->isInGroup('developers');
        $request = Craft::$app->getRequest();
        $currentUser = Craft::$app->getUser()->getIdentity();

        // If it's a front-end site POST request and they're not currently a developer, check to see if they've opted into developer features.
        if (
            $currentUser &&
            $currentUser->id == $this->owner->id &&
            $request->getIsSiteRequest() &&
            $request->getIsPost() &&
            $request->getBodyParam('fields.enablePluginDeveloperFeatures') &&
            !$isDeveloper
        ) {
            // Get any existing group IDs.
            $userGroupsService = Craft::$app->getUserGroups();
            $existingGroups = $userGroupsService->getGroupsByUserId($currentUser->id);
            $groupIds = [];

            foreach ($existingGroups as $existingGroup) {
                $groupIds[] = $existingGroup->id;
            }

            // Add the developer group.
            $groupIds[] = $userGroupsService->getGroupByHandle('developers')->id;

            Craft::$app->getUsers()->assignUserToGroups($currentUser->id, $groupIds);
            $isDeveloper = true;
        }

        if ($isDeveloper) {
            $this->saveDeveloperInfo();
        }
    }

    /**
     * Updates the developer data.
     */
    public function saveDeveloperInfo()
    {
        Craft::$app->getDb()->createCommand()
            ->upsert(Table::DEVELOPERS, [
                'id' => $this->owner->id,
            ], [
                'country' => $this->country,
                'stripeAccessToken' => $this->stripeAccessToken,
                'stripeAccount' => $this->stripeAccount,
                'payPalEmail' => $this->payPalEmail,
                'apiToken' => $this->apiToken,
            ], [], false)
            ->execute();
    }
}
