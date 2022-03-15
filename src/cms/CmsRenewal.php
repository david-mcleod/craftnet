<?php

namespace craftnet\cms;

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
 * @property-read CmsEdition $edition
 */
class CmsRenewal extends CmsPurchasable implements RenewalInterface
{
    /**
     * @return string
     */
    public static function displayName(): string
    {
        return 'CMS Renewal';
    }

    /**
     * @return CmsRenewalQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new CmsRenewalQuery(static::class);
    }

    /**
     * @var int The CMS edition ID
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
        return 'cms-renewal';
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
            'editionId' => $this->editionId,
            'price' => $this->price,
        ];

        if ($isNew) {
            Craft::$app->getDb()->createCommand()
                ->insert(Table::CMSRENEWALS, $data, false)
                ->execute();
        } else {
            Craft::$app->getDb()->createCommand()
                ->update(Table::CMSRENEWALS, $data, ['id' => $this->id], [], false)
                ->execute();
        }

        parent::afterSave($isNew);
    }

    /**
     * Returns the CMS edition associated with the renewal.
     *
     * @return CmsEdition
     * @throws InvalidConfigException if [[editionId]] is invalid
     */
    public function getEdition(): CmsEdition
    {
        if ($this->editionId === null) {
            throw new InvalidConfigException('CMS renewal is missing its edition ID');
        }
        if (($edition = CmsEdition::findOne($this->editionId)) === null) {
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
        return $this->getEdition()->name . ' Renewal';
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
        $manager = Module::getInstance()->getCmsLicenseManager();
        $options = $lineItem->getOptions();

        try {
            $license = $manager->getLicenseByKey($options['licenseKey']);
        } catch (LicenseNotFoundException $e) {
            Craft::error("Could not renew Craft license {$options['licenseKey']} for order {$order->number}: {$e->getMessage()}", __METHOD__);
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
            $expiryStr = OrderHelper::expiryObj2Str($license->expiresOn);
            $manager->addHistory($license->id, "Renewed until {$expiryStr} per order {$order->number}");
        } catch (\Throwable $e) {
            Craft::error("Could not save Craft license {$license->key} for order {$order->number}: {$e->getMessage()}", __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);
        }
    }
}
