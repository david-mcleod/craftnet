<?php

namespace craftnet\plugins;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craftnet\base\EditionInterface;
use craftnet\base\RenewalInterface;
use craftnet\db\Table;
use craftnet\errors\LicenseNotFoundException;
use craftnet\helpers\OrderHelper;
use craftnet\Module;
use yii\base\Exception;
use yii\validators\CompareValidator;

/**
 * @property-read Plugin $plugin
 * @property-read string $fullName
 */
class PluginEdition extends PluginPurchasable implements EditionInterface
{
    const SCENARIO_CP = 'cp';
    const SCENARIO_SITE = 'site';

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Plugin Edition';
    }

    /**
     * @inheritdoc
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     * @return PluginEditionQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new PluginEditionQuery(static::class);
    }

    /**
     * @param array $sourceElements
     * @param string $handle
     *
     * @return array|bool|false
     */
    public static function eagerLoadingMap(array $sourceElements, string $handle)
    {
        if ($handle === 'plugin') {
            $query = (new Query())
                ->select([
                    'source' => 'id',
                    'target' => 'pluginId',
                ])
                ->from([Table::PLUGINEDITIONS])
                ->where(['id' => ArrayHelper::getColumn($sourceElements, 'id')]);
            return ['elementType' => Plugin::class, 'map' => $query->all()];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => 'All editions',
            ],
            [
                'key' => 'commercial',
                'label' => 'Commercial editions',
                'criteria' => ['commercial' => true],
            ],
            [
                'key' => 'free',
                'label' => 'Free editions',
                'criteria' => ['commercial' => false],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'name' => ['label' => 'Name'],
            'developer' => ['label' => 'Developer'],
            'price' => ['label' => 'Price'],
            'renewalPrice' => ['label' => 'Renewal Price'],
        ];
    }

    /**
     * @var string The edition name
     */
    public $name;

    /**
     * @var string The edition handle ('standard', etc.)
     */
    public $handle;

    /**
     * @var float The edition price
     */
    public $price;

    /**
     * @var float The edition renewal price
     */
    public $renewalPrice;

    /**
     * @var array|null Edition feature list
     */
    public $features;

    /**
     * @return string
     */
    public function __toString()
    {
        try {
            return $this->getFullName();
        } catch (\Throwable $e) {
            return $this->name;
        }
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        if (is_string($this->features)) {
            $this->features = Json::decode($this->features);
        }
    }

    /**
     * @inheritdoc
     */
    public function getType(): string
    {
        return 'plugin-edition';
    }

    /**
     * @inheritdoc
     */
    public function getHandle(): string
    {
        return $this->handle;
    }

    /**
     * @inheritdoc
     */
    public function getRenewal(): RenewalInterface
    {
        return PluginRenewal::find()
            ->editionId($this->id)
            ->one();
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        return [
            self::SCENARIO_CP => ['name', 'handle', 'price', 'renewalPrice', 'features', 'enabled'],
            self::SCENARIO_SITE => ['price', 'renewalPrice', 'features'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributes()
    {
        $names = parent::attributes();
        $names[] = 'fullName';
        return $names;
    }

    /**
     * Returns the full plugin edition name (including the plugin name).
     *
     * @return string
     */
    public function getFullName(): string
    {
        return "{$this->getPlugin()->name} ({$this->name})";
    }

    /**
     * @inheritdoc
     */
    public function getThumbUrl(int $size)
    {
        return $this->getPlugin()->getThumbUrl($size);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSearchableAttributes(): array
    {
        return [
            'fullName',
        ];
    }

    /**
     * @param string $handle
     * @param array $elements
     */
    public function setEagerLoadedElements(string $handle, array $elements)
    {
        if ($handle === 'plugin') {
            $this->setPlugin($elements[0] ?? null);
        } else {
            parent::setEagerLoadedElements($handle, $elements);
        }
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['name', 'handle'], 'required'];
        $rules[] = [['price', 'renewalPrice'], 'normalizePrice', 'skipOnEmpty' => false];

        $rules[] = [
            [
                'price',
                'renewalPrice',
            ],
            'number',
            'min' => 5,
            'isEmpty' => [$this, 'isPriceEmpty'],
        ];

        $rules[] = [
            [
                'renewalPrice',
            ],
            'required',
            'when' => [$this, 'isRenewalPriceRequired'],
            'isEmpty' => [$this, 'isPriceEmpty'],
        ];

        $rules[] = [
            [
                'renewalPrice',
            ],
            'compare',
            'compareAttribute' => 'price',
            'type' => CompareValidator::TYPE_NUMBER,
            'operator' => '<=',
            'when' => [$this, 'isRenewalPriceRequired'],
        ];

        $rules[] = [
            [
                'renewalPrice',
            ],
            'number',
            'min' => 0,
            'max' => 0,
            'when' => [$this, 'isRenewalPriceForbidden'],
        ];

        return $rules;
    }

    /**
     * Normalizes a price.
     *
     * @var string $attribute
     */
    public function normalizePrice(string $attribute)
    {
        $this->$attribute = (float)$this->$attribute;
    }

    /**
     * Returns whether a given price attribute should be validated.
     *
     * @param mixed $value
     * @return bool
     */
    public function isPriceEmpty($value): bool
    {
        return $value === null || $value === [] || $value === '' || $value == 0;
    }

    /**
     * @return bool
     */
    public function isRenewalPriceRequired(): bool
    {
        return $this->price != 0;
    }

    /**
     * @return bool
     */
    public function isRenewalPriceForbidden(): bool
    {
        return $this->price == 0;
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew)
    {
        $data = [
            'id' => $this->id,
            'pluginId' => $this->pluginId,
            'name' => $this->name,
            'handle' => $this->handle,
            'price' => $this->price,
            'renewalPrice' => $this->renewalPrice,
            'features' => Json::encode(array_values($this->features ?: [])),
        ];

        if ($isNew) {
            Craft::$app->getDb()->createCommand()
                ->insert(Table::PLUGINEDITIONS, $data, false)
                ->execute();
        } else {
            Craft::$app->getDb()->createCommand()
                ->update(Table::PLUGINEDITIONS, $data, ['id' => $this->id], [], false)
                ->execute();
        }

        // Save the renewal
        $renewal = PluginRenewal::find()
            ->editionId($this->id)
            ->one();
        if (!$renewal) {
            $renewal = new PluginRenewal();
            $renewal->editionId = $this->id;
            $renewal->pluginId = $this->pluginId;
        }
        $renewal->price = $this->renewalPrice;
        Craft::$app->getElements()->saveElement($renewal, false);

        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public function getIsAvailable(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getDescription(): string
    {
        $plugin = $this->getPlugin();
        if ($plugin->getHasMultipleEditions()) {
            return "{$plugin->name} ({$this->name} edition)";
        }
        return $plugin->name;
    }

    /**
     * @inheritdoc
     */
    public function getPrice(): float
    {
        return (float)$this->price;
    }

    /**
     * @inheritdoc
     */
    public function getSku(): string
    {
        return strtoupper("{$this->plugin->handle}-{$this->handle}");
    }

    /**
     * @inheritdoc
     */
    public function populateLineItem(LineItem $lineItem)
    {
        OrderHelper::populateEditionLineItem($lineItem, $this);
    }

    /**
     * @inheritdoc
     */
    public function afterOrderComplete(Order $order, LineItem $lineItem)
    {
        $this->_upgradeOrderLicense($order, $lineItem);
        parent::afterOrderComplete($order, $lineItem);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        return [
            'name' => 'Name',
            'price' => 'price',
            'renewalPrice' => 'renewalPrice',
        ];
    }

    /**
     * @inheritdoc
     */
    protected function tableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'developer':
                return $this->getPlugin()->getDeveloperName();
            case 'price':
            case 'renewalPrice':
                return Craft::$app->getFormatter()->asCurrency($this->$attribute, 'USD', [], [], true);
            default:
                return parent::tableAttributeHtml($attribute);
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * @param Order $order
     * @param LineItem $lineItem
     */
    private function _upgradeOrderLicense(Order $order, LineItem $lineItem)
    {
        $manager = Module::getInstance()->getPluginLicenseManager();
        $options = $lineItem->getOptions();

        // is this for an existing plugin license?
        $isNew = (strncmp($options['licenseKey'], 'new:', 4) === 0);
        if (!$isNew) {
            try {
                $license = $manager->getLicenseByKey($options['licenseKey'], $this->getPlugin()->handle);
            } catch (LicenseNotFoundException $e) {
                Craft::error("Could not upgrade plugin license {$options['licenseKey']} for order {$order->number}: {$e->getMessage()}", __METHOD__);
                Craft::$app->getErrorHandler()->logException($e);
                return;
            }
        } else {
            // chop off "new:"
            $key = substr($options['licenseKey'], 4);

            // was a Craft license specified?
            if (!empty($options['cmsLicenseKey'])) {
                try {
                    $cmsLicense = Module::getInstance()->getCmsLicenseManager()->getLicenseByKey($options['cmsLicenseKey']);
                } catch (LicenseNotFoundException $e) {
                    Craft::error("Could not associate new plugin license with Craft license {$options['cmsLicenseKey']} for order {$order->number}: {$e->getMessage()}", __METHOD__);
                    Craft::$app->getErrorHandler()->logException($e);
                    $cmsLicense = null;
                }
            }

            // create the new license
            $license = new PluginLicense([
                'pluginId' => $this->pluginId,
                'cmsLicenseId' => $cmsLicense->id ?? null,
                'pluginHandle' => $this->getPlugin()->handle,
                'key' => $key,
            ]);
        }

        $oldEmail = $license->email;

        $license->editionId = $this->id;
        $license->edition = $this->handle;
        $license->trial = false;
        $license->expired = false;

        // If this was bought with a coupon created before April 4, set the license to non-expirable
        if ($order->couponCode) {
            $discount = Commerce::getInstance()->getDiscounts()->getDiscountByCode($order->couponCode);
            if ($discount && $discount->dateCreated->getTimestamp() < 1522857600) {
                $license->expirable = false;
            }
        }

        // If it's expirable, set the expiresOn date to a year from now
        if ($license->expirable) {
            $license->expiresOn = OrderHelper::expiryStr2Obj($options['expiryDate']);
        }

        if (isset($options['autoRenew'])) {
            $license->autoRenew = $options['autoRenew'];
        }

        // if the license doesn't have an owner yet, reassign it to the order's customer
        if (!$license->ownerId) {
            $license->email = $order->getEmail();
            $license->ownerId = $order->getCustomer()->userId;
        }

        try {
            // save the license
            if (!$manager->saveLicense($license)) {
                Craft::error("Could not save plugin license {$license->key} for order {$order->number}: " . implode(', ', $license->getErrorSummary(true)), __METHOD__);
                return;
            }

            // relate the license to the line item
            Craft::$app->getDb()->createCommand()
                ->insert(Table::PLUGINLICENSES_LINEITEMS, [
                    'licenseId' => $license->id,
                    'lineItemId' => $lineItem->id,
                ], false)
                ->execute();

            // update the license history
            if ($isNew) {
                $note = "created by {$license->email}";
            } else {
                $note = "upgraded to {$license->edition}";
                if ($license->email !== $oldEmail) {
                    $note .= " and reassigned to {$license->email}";
                }
            }
            $manager->addHistory($license->id, "{$note} per order {$order->number}");
        } catch (Exception $e) {
            Craft::error("Could not save plugin license {$license->key} for order {$order->number}: {$e->getMessage()}", __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);
        }
    }
}
