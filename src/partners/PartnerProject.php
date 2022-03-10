<?php

namespace craftnet\partners;


use craft\base\Element;
use craft\base\Model;
use craft\elements\Asset;
use craft\validators\ArrayValidator;

class PartnerProject extends Model
{
    public $id;
    public $partnerId;
    public $name;
    public $url;
    public $linkType;
    public $role;
    public $withCraftCommerce;
    public $dateCreated;
    public $dateUpdated;
    public $sortOrder;
    public $uid;

    /**
     * Not a column in the table, this is a property for
     * api and templates. During a POST request this will
     * be an array of Asset ids. When a GET request, this
     * will be an array of Asset instances.
     *
     * @var array
     */
    public $screenshots;

    /**
     * @inheritdoc
     * @return array
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'url'], 'trim'],
            [['name', 'url'], 'required', 'on' => [Element::SCENARIO_DEFAULT, Element::SCENARIO_LIVE, Partner::SCENARIO_PROJECTS]],
            [
                'screenshots',
                ArrayValidator::class,
                'skipOnEmpty' => false,
                'min' => 1,
                'max' => 5,
                'tooFew' => 'Please provide at least one screenshot',
                'tooMany' => 'Please limit to 5 screenshots',
                'on' => [
                    Element::SCENARIO_DEFAULT,
                    Element::SCENARIO_LIVE,
                    Partner::SCENARIO_PROJECTS,
                ],
            ],
            ['url', 'url', 'enableIDN' => true],
            ['role', 'string', 'max' => 55],
            ['withCraftCommerce', 'default', 'value' => false],
        ];
    }

    /**
     * Depending on the request method, `screenshots` will be either
     * an array of Asset ids or an array of Asset instances.
     *
     * @return array
     */
    public function getScreenshotIds()
    {
        if (!is_array($this->screenshots)) {
            return [];
        }

        $ids = array_map(function($val) {
            return ($val instanceof Asset) ? $val->id : (int)$val;
        }, $this->screenshots);

        return $ids;
    }
}
