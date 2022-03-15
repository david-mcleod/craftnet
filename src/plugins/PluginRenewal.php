<?php

namespace craftnet\plugins;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\elements\db\ElementQueryInterface;
use craftnet\base\RenewalInterface;
use craftnet\db\Table;
use craftnet\errors\LicenseNotFoundException;
use craftnet\helpers\OrderHelper;
use craftnet\Module;
use yii\base\InvalidConfigException;

/**
 * @property-read Plugin $plugin
 * @property-read PluginEdition $edition
 */
class PluginRenewal extends PluginPurchasable implements RenewalInterface
{
    /**
     * @return string
     */
    public static function displayName(): string
    {
        return 'Plugin Renewal';
    }

    /**
     * @return PluginRenewalQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new PluginRenewalQuery(static::class);
    }

    /**
     * @var int The plugin edition ID
     */
    public $editionId;

    /**
     * @var float The renewal price
     */
    public $price;

    /**
     * @inheritdoc
     */
    public function getType(): string
    {
        return 'plugin-renewal';
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['editionId', 'price'], 'required'];
        $rules[] = [['editionId'], 'number', 'integerOnly' => true];
        $rules[] = [['price'], 'number'];
        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew)
    {
        $data = [
            'id' => $this->id,
            'pluginId' => $this->pluginId,
            'editionId' => $this->editionId,
            'price' => $this->price,
        ];

        if ($isNew) {
            Craft::$app->getDb()->createCommand()
                ->insert(Table::PLUGINRENEWALS, $data, false)
                ->execute();
        } else {
            Craft::$app->getDb()->createCommand()
                ->update(Table::PLUGINRENEWALS, $data, ['id' => $this->id], [], false)
                ->execute();
        }

        parent::afterSave($isNew);
    }

    /**
     * Returns the plugin edition associated with the renewal.
     *
     * @return PluginEdition
     * @throws InvalidConfigException if [[editionId]] is invalid
     */
    public function getEdition(): PluginEdition
    {
        if ($this->editionId === null) {
            throw new InvalidConfigException('Plugin renewal is missing its edition ID');
        }
        if (($edition = PluginEdition::find()->id($this->editionId)->anyStatus()->one()) === null) {
            throw new InvalidConfigException('Invalid edition ID: ' . $this->editionId);
        };
        return $edition;
    }

    /**
     * @inheritdoc
     */
    public function getIsAvailable(): bool
    {
        return $this->price;
    }

    /**
     * @inheritdoc
     */
    public function getDescription(): string
    {
        return $this->getEdition()->getDescription() . ' Renewal';
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
        return "{$this->edition->getSku()}-RENEWAL";
    }

    /**
     * @inheritdoc
     */
    public function populateLineItem(LineItem $lineItem)
    {
        OrderHelper::populateRenewalLineItem($lineItem, $this);
    }

    /**
     * @inheritdoc
     */
    public function afterOrderComplete(Order $order, LineItem $lineItem)
    {
        $this->_updateOrderLicense($order, $lineItem);
        parent::afterOrderComplete($order, $lineItem);
    }

    /**
     * @param Order $order
     * @param LineItem $lineItem
     */
    private function _updateOrderLicense(Order $order, LineItem $lineItem)
    {
        $manager = Module::getInstance()->getPluginLicenseManager();
        $options = $lineItem->getOptions();

        try {
            $license = $manager->getLicenseByKey($options['licenseKey']);
        } catch (LicenseNotFoundException $e) {
            Craft::error("Could not renew plugin license {$options['licenseKey']} for order {$order->number}: {$e->getMessage()}", __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);
            return;
        }

        $license->expired = false;
        $license->reminded = false;
        $license->expiresOn = OrderHelper::expiryStr2Obj($options['expiryDate']);
        $license->lastRenewedOn = new \DateTime();

        try {
            // save the license
            if (!$manager->saveLicense($license, false)) {
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
            $expiryStr = OrderHelper::expiryObj2Str($license->expiresOn);
            $manager->addHistory($license->id, "Renewed until {$expiryStr} per order {$order->number}");
        } catch (\Throwable $e) {
            Craft::error("Could not save plugin license {$license->key} for order {$order->number}: {$e->getMessage()}", __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);
        }
    }
}
