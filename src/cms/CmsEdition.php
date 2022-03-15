<?php

namespace craftnet\cms;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\elements\db\ElementQueryInterface;
use craftnet\base\EditionInterface;
use craftnet\base\RenewalInterface;
use craftnet\db\Table;
use craftnet\errors\LicenseNotFoundException;
use craftnet\helpers\OrderHelper;
use craftnet\Module;
use yii\base\Exception;


class CmsEdition extends CmsPurchasable implements EditionInterface
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'CMS Edition';
    }

    /**
     * @inheritdoc
     * @return CmsEditionQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new CmsEditionQuery(static::class);
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
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'name' => ['label' => 'Name'],
            'price' => ['label' => 'Price'],
            'renewalPrice' => ['label' => 'Renewal Price'],
        ];
    }

    // Properties
    // =========================================================================

    /**
     * @var string The edition name
     */
    public $name;

    /**
     * @var string The edition handle ('solo' or 'pro')
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
     * @return string
     */
    public function __toString()
    {
        return "Craft {$this->name}";
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getType(): string
    {
        return 'cms-edition';
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
        return CmsRenewal::find()
            ->editionId($this->id)
            ->one();
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['name', 'handle', 'price', 'renewalPrice'], 'required'];
        $rules[] = [['price', 'renewalPrice'], 'number'];
        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew)
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'handle' => $this->handle,
            'price' => $this->price,
            'renewalPrice' => $this->renewalPrice,
        ];

        if ($isNew) {
            Craft::$app->getDb()->createCommand()
                ->insert(Table::CMSEDITIONS, $data, false)
                ->execute();
        } else {
            Craft::$app->getDb()->createCommand()
                ->update(Table::CMSEDITIONS, $data, ['id' => $this->id], [], false)
                ->execute();
        }

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
        return "Craft {$this->name}";
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
        return 'CRAFT-' . strtoupper($this->handle);
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
        $this->_updateOrderLicense($order, $lineItem);
        parent::afterOrderComplete($order, $lineItem);
    }

    // Protected Methods
    // =========================================================================

    protected function tableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
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
    private function _updateOrderLicense(Order $order, LineItem $lineItem)
    {
        $manager = Module::getInstance()->getCmsLicenseManager();
        $options = $lineItem->getOptions();

        // is this for an existing Craft license?
        $isNew = (strncmp($options['licenseKey'], 'new:', 4) === 0);
        if (!$isNew) {
            try {
                $license = $manager->getLicenseByKey($options['licenseKey']);
            } catch (LicenseNotFoundException $e) {
                Craft::error("Could not upgrade Craft license {$options['licenseKey']} for order {$order->number}: {$e->getMessage()}", __METHOD__);
                Craft::$app->getErrorHandler()->logException($e);
                return;
            }
        } else {
            // chop off "new:"
            $key = substr($options['licenseKey'], 4);

            // create the new license
            $license = new CmsLicense([
                'key' => $key,
            ]);
        }

        $oldEmail = $license->email;

        $license->editionId = $this->id;
        $license->editionHandle = $this->handle;
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
            if (!$manager->saveLicense($license, false)) {
                Craft::error("Could not save Craft license {$license->key} for order {$order->number}: " . implode(', ', $license->getErrorSummary(true)), __METHOD__);
                return;
            }

            // relate the license to the line item
            Craft::$app->getDb()->createCommand()
                ->insert(Table::CMSLICENSES_LINEITEMS, [
                    'licenseId' => $license->id,
                    'lineItemId' => $lineItem->id,
                ], false)
                ->execute();

            // update the license history
            if ($isNew) {
                $note = "created by {$license->email}";
            } else {
                $note = "upgraded to {$license->editionHandle}";
                if ($license->email !== $oldEmail) {
                    $note .= " and reassigned to {$license->email}";
                }
            }
            $manager->addHistory($license->id, "{$note} per order {$order->number}");
        } catch (Exception $e) {
            Craft::error("Could not save Craft license {$license->key} for order {$order->number}: {$e->getMessage()}", __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);
        }
    }
}
