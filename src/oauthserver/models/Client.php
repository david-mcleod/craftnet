<?php

namespace craftnet\oauthserver\models;

use craft\base\Model;

/**
 * Class Client
 */
class Client extends Model
{
    // Properties
    // =========================================================================

    /**
     * @var
     */
    public $id;

    /**
     * @var
     */
    public $name;

    /**
     * @var
     */
    public $identifier;

    /**
     * @var
     */
    public $secret;

    /**
     * @var
     */
    public $redirectUri;

    /**
     * @var
     */
    public $redirectUriLocked;

    /**
     * @var
     */
    public $dateCreated;

    /**
     * @var
     */
    public $dateUpdated;

    /**
     * @var
     */
    public $uid;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'identifier'], 'required'],
        ];
    }
}
