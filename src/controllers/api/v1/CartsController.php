<?php

namespace craftnet\controllers\api\v1;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\Address;
use craft\commerce\models\Customer;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\StringHelper;
use craftnet\cms\CmsEdition;
use craftnet\cms\CmsRenewal;
use craftnet\controllers\api\BaseApiController;
use craftnet\controllers\api\RateLimiterTrait;
use craftnet\errors\LicenseNotFoundException;
use craftnet\errors\ValidationException;
use craftnet\helpers\KeyHelper;
use craftnet\plugins\Plugin;
use craftnet\plugins\PluginRenewal;
use Ddeboer\Vatin\Validator;
use Moccalotto\Eu\CountryInfo;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\validators\EmailValidator;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Class CartsController
 */
class CartsController extends BaseApiController
{
    use RateLimiterTrait;

    // Properties
    // =========================================================================

    public $defaultAction = 'create';

    // Public Methods
    // =========================================================================

    /**
     * Creates a cart.
     *
     * @return Response
     * @throws ValidationException
     */
    public function actionCreate(): Response
    {
        $payload = $this->getPayload('update-cart-request');

        $cart = new Order([
            'number' => Commerce::getInstance()->getCarts()->generateCartNumber(),
            'currency' => 'USD',
            'paymentCurrency' => 'USD',
            'gatewayId' => App::env('STRIPE_GATEWAY_ID'),
            'orderLanguage' => Craft::$app->language,
        ]);

        $this->_updateCart($cart, $payload);

        return $this->asJson([
            'cart' => $this->cartArray($cart),
            'stripePublicKey' => App::env('STRIPE_PUBLIC_KEY'),
        ]);
    }

    /**
     * Returns cart info.
     *
     * @param string $orderNumber
     * @return Response
     */
    public function actionGet(string $orderNumber): Response
    {
        $cart = $this->getCart($orderNumber);

        return $this->asJson([
            'cart' => $this->cartArray($cart),
            'stripePublicKey' => App::env('STRIPE_PUBLIC_KEY'),
        ]);
    }

    /**
     * Updates a cart.
     *
     * @param string $orderNumber
     * @return Response
     */
    public function actionUpdate(string $orderNumber): Response
    {
        $cart = $this->getCart($orderNumber);
        $payload = $this->getPayload('update-cart-request');
        $this->_updateCart($cart, $payload);

        return $this->asJson([
            'updated' => true,
            'cart' => $this->cartArray($cart),
            'stripePublicKey' => App::env('STRIPE_PUBLIC_KEY'),
        ]);
    }

    /**
     * Deletes a cart.
     *
     * @param string $orderNumber
     * @return Response
     */
    public function actionDelete(string $orderNumber): Response
    {
        $cart = $this->getCart($orderNumber);
        Craft::$app->getElements()->deleteElementById($cart->id);

        return $this->asJson([
            'deleted' => true,
        ]);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @param string $orderNumber
     * @return Order
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     */
    protected function getCart(string $orderNumber): Order
    {
        $cart = Commerce::getInstance()->getOrders()->getOrderByNumber($orderNumber);

        if (!$cart) {
            throw new NotFoundHttpException('Cart Not Found');
        }

        if ($cart->isCompleted) {
            throw new BadRequestHttpException('Cart Already Completed');
        }

        return $cart;
    }

    /**
     * @param Order $cart
     * @return array
     */
    protected function cartArray(Order $cart): array
    {
        return $cart->toArray([], [
            'billingAddress',
            'lineItems.purchasable.plugin',
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param Order $cart
     * @param \stdClass $payload
     * @throws \Throwable
     * @throws \yii\db\Exception
     */
    private function _updateCart(Order $cart, \stdClass $payload)
    {
        $commerce = Commerce::getInstance();
        $db = Craft::$app->getDb();

        $errors = [];

        $transaction = $db->beginTransaction();
        try {
            // update the IP
            $cart->lastIp = $this->request->getUserIP();

            // update cancel/return URLs
            $cart->cancelUrl = App::parseEnv('$URL_ID') . 'payment';
            $cart->returnUrl = App::parseEnv('$URL_ID') . 'thank-you';

            // Remember the current customerId before determining the possible new one
            $customerId = $cart->customerId;

            // set the email/customer before saving the cart, so the cart doesn't create its own customer record
            if (($user = Craft::$app->getUser()->getIdentity(false)) !== null) {
                $this->_updateCartEmailAndCustomer($cart, $user, null, $errors);
            } else if (isset($payload->email)) {
                $this->_updateCartEmailAndCustomer($cart, null, $payload->email, $errors);
            }

            // If the customer has changed, they do not have permissions to the old address ID on the cart.
            if ($cart->billingAddressId && $cart->customerId != $customerId) {
                $address = $commerce->getAddresses()->getAddressById($cart->billingAddressId);
                // Don't lose the data from the address, just drop the ID
                if ($address) {
                    $address->id = null;
                    $cart->setBillingAddress($address);
                }
            }

            // save the cart if it's new so it gets an ID
            if (!$cart->id && !Craft::$app->getElements()->saveElement($cart)) {
                throw new Exception('Could not save the cart: ' . implode(', ', $cart->getErrorSummary(true)));
            }

            // billing address
            if (isset($payload->billingAddress)) {
                $this->_updateCartBillingAddress($cart, $payload->billingAddress, $errors);
            }

            // coupon code
            if (property_exists($payload, 'couponCode')) {
                $this->_updateCartCouponCode($cart, $payload->couponCode, $errors);
            }

            // line items
            if (isset($payload->items)) {
                if ($cart->id) {
                    // first clear the cart
                    $cart->setLineItems([]);
                }

                // keep track of the license keys we've already found items for
                $licenseKeys = [];

                foreach ($payload->items as $i => $item) {
                    $paramPrefix = "items[{$i}]";

                    // first make sure it validates
                    // todo: eventually we should be able to handle this from the root payload validation, if JSON schemas can do conditional validation
                    if (!$this->validatePayload($item, 'line-item-types/' . $item->type, $errors, $paramPrefix)) {
                        continue;
                    }

                    if (isset($item->licenseKey)) {
                        if (isset($licenseKeys[$item->licenseKey])) {
                            $errors[] = [
                                'param' => $paramPrefix . '.licenseKey',
                                'message' => 'Another item already handles this license key.',
                                'code' => self::ERROR_CODE_INVALID,
                            ];
                            continue;
                        }
                        $licenseKeys[$item->licenseKey] = true;
                    }

                    switch ($item->type) {
                        case 'cms-edition':
                            $lineItem = $this->_cmsEditionLineItem($cart, $item, $paramPrefix, $errors);
                            break;
                        case 'cms-renewal':
                            $lineItem = $this->_cmsRenewalLineItem($cart, $item, $paramPrefix, $errors);
                            break;
                        case 'plugin-edition':
                            $lineItem = $this->_pluginEditionLineItem($cart, $item, $paramPrefix, $errors);
                            break;
                        case 'plugin-renewal':
                            $lineItem = $this->_pluginRenewalLineItem($cart, $item, $paramPrefix, $errors);
                            break;
                        default:
                            $errors[] = [
                                'param' => $paramPrefix . '.type',
                                'message' => "Invalid item type: {$item->type}",
                                'code' => self::ERROR_CODE_INVALID,
                            ];
                            $lineItem = null;
                    }

                    if ($lineItem !== null) {
                        // add a note?
                        if (isset($item->note)) {
                            $lineItem->note = $item->note;
                        }

                        $lineItem->qty = 1;

                        $cart->addLineItem($lineItem);
                    }
                }
            }

            // were there any validation errors?
            if (!empty($errors)) {
                throw new ValidationException($errors);
            }

            // save the cart
            if (!Craft::$app->getElements()->saveElement($cart)) {
                throw new Exception('Could not save the cart: ' . implode(', ', $cart->getErrorSummary(true)));
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * @param Order $cart
     * @param User|null $user
     * @param string|null $email
     * @param array $errors
     * @throws Exception
     */
    private function _updateCartEmailAndCustomer(Order $cart, ?User $user, ?string $email, array &$errors)
    {
        // validate first
        if ($email !== null && !(new EmailValidator())->validate($email, $error)) {
            $errors[] = [
                'param' => 'email',
                'message' => $error,
                'code' => self::ERROR_CODE_INVALID,
            ];
            return;
        }

        $customersService = Commerce::getInstance()->getCustomers();

        // get the cart's current customer if it has one
        if ($cart->customerId) {
            $currentCustomer = $customersService->getCustomerById($cart->customerId);
        }

        // if we don't know the user yet, see if we can find one with the given email
        if ($user === null && $email !== null) {
            $user = User::find()
                ->where(['email' => $email])
                ->one();
        }

        if ($user) {
            // see if we have a customer record for them
            $customer = $customersService->getCustomerByUserId($user->id);
        }

        // if the cart is already set to the user's customer, then just leave it alone
        if (isset($customer) && isset($currentCustomer) && $customer->id == $currentCustomer->id) {
            return;
        }

        // is the cart currently set to an anonymous customer?
        if (isset($currentCustomer) && !$currentCustomer->userId) {
            // if we still don't have a user, keep using it
            if ($user === null) {
                $customer = $currentCustomer;
            } else {
                // safe to delete it
                $customersService->deleteCustomer($currentCustomer);
            }
        }

        // do we need to create a new customer?
        if (!isset($customer)) {
            $customer = new Customer([
                'userId' => $user->id ?? null,
            ]);
            if (!$customersService->saveCustomer($customer)) {
                throw new Exception('Could not save the customer: ' . implode(' ', $customer->getErrorSummary(true)));
            }
        }

        $cart->setCustomer($customer);

        if ($email !== null) {
            $cart->setEmail($email);
        }
    }

    /**
     * @param Order $cart
     * @param \stdClass $billingAddress
     * @param array $errors
     * @throws Exception
     * @throws \yii\base\InvalidConfigException
     */
    private function _updateCartBillingAddress(Order $cart, \stdClass $billingAddress, array &$errors)
    {
        $commerce = Commerce::getInstance();
        $addressErrors = [];

        // get the country
        if (!empty($billingAddress->country)) {
            if (($country = $commerce->getCountries()->getCountryByIso($billingAddress->country)) === null) {
                $addressErrors[] = [
                    'param' => 'billingAddress.country',
                    'message' => 'Invalid country',
                    'code' => self::ERROR_CODE_INVALID,
                ];
            } else if (!empty($billingAddress->businessTaxId) && (new CountryInfo())->isEuMember($country->iso)) {
                // Make sure it looks like a valid VAT ID
                $vatId = preg_replace('/[^A-Za-z0-9]/', '', $billingAddress->businessTaxId);

                // Greece is EL inside the EU and GR everywhere else.
                $iso = $country->iso === 'GR' ? 'EL' : $country->iso;

                // Make sure the VAT ID the user supplied starts with the correct country code.
                $vatId = StringHelper::ensureLeft(StringHelper::toUpperCase($vatId), StringHelper::toUpperCase($iso));
                if ($vatId && !(new Validator())->isValid($vatId)) {
                    $addressErrors[] = [
                        'param' => 'billingAddress.businessTaxId',
                        'message' => 'A valid VAT ID is required for European orders.',
                        'code' => self::ERROR_CODE_INVALID,
                    ];
                }
            }

            // get the state
            if ($country !== null && !empty($billingAddress->state)) {
                // see if it's a valid state abbreviation
                $state = $commerce->getStates()->getStateByAbbreviation($country->id, $billingAddress->state);
            } else {
                $state = null;
            }

            // if the country requires a state, make sure they submitted a valid state
            if ($country !== null && $country->isStateRequired && $state === null) {
                $addressErrors[] = [
                    'param' => 'billingAddress.state',
                    'message' => "{$country->name} addresses must specify a valid state.",
                    'code' => empty($billingAddress->state) ? self::ERROR_CODE_MISSING_FIELD : self::ERROR_CODE_INVALID,
                ];
            }
        }

        $address = new Address();

        // populate the address
        $addressConfig = [
            'firstName' => $billingAddress->firstName,
            'lastName' => $billingAddress->lastName,
            'attention' => $billingAddress->attention ?? null,
            'title' => $billingAddress->title ?? null,
            'address1' => $billingAddress->address1 ?? null,
            'address2' => $billingAddress->address2 ?? null,
            'city' => $billingAddress->city ?? null,
            'zipCode' => $billingAddress->zipCode ?? null,
            'phone' => $billingAddress->phone ?? null,
            'alternativePhone' => $billingAddress->alternativePhone ?? null,
            'businessName' => $billingAddress->businessName ?? null,
            'businessId' => $billingAddress->businessId ?? null,
            'businessTaxId' => $billingAddress->businessTaxId ?? null,
        ];

        Craft::configure($address, $addressConfig);

        if (!$address->validate(array_keys($addressConfig))) {
            array_push($addressErrors, ...$this->modelErrors($address, 'billingAddress'));
        }

        if (!empty($addressErrors)) {
            array_push($errors, ...$addressErrors);
            return;
        }

        $address->countryId = $country->id ?? null;
        $address->stateId = $state->id ?? null;
        $address->stateName = $state->abbreviation ?? $billingAddress->state ?? null;
        $address->setStateValue(null);

        // save the address
        if (!$commerce->getCustomers()->saveAddress($address, $cart->getCustomer(), false)) {
            throw new Exception('Could not save address: ' . implode(', ', $address->getErrorSummary(true)));
        }

        if (!empty($billingAddress->makePrimary) && $address->id) {
            $cart->makePrimaryBillingAddress = true;
        }

        // update the cart
        $cart->setBillingAddress($address);
        $cart->billingAddressId = $address->id;
    }

    /**
     * @param Order $cart
     * @param string|null $couponCode
     * @param array $errors
     */
    private function _updateCartCouponCode(Order $cart, ?string $couponCode, array &$errors)
    {
        $cart->couponCode = $couponCode;

        if ($couponCode !== null && !Commerce::getInstance()->getDiscounts()->orderCouponAvailable($cart, $explanation)) {
            $errors[] = [
                'param' => 'couponCode',
                'message' => $explanation,
                'code' => self::ERROR_CODE_INVALID,
            ];
            return;
        }
    }

    /**
     * @param Order $cart
     * @param \stdClass $item
     * @param string $paramPrefix
     * @param $errors
     * @return LineItem|null
     */
    private function _cmsEditionLineItem(Order $cart, \stdClass $item, string $paramPrefix, &$errors): ?LineItem
    {
        $edition = CmsEdition::find()
            ->handle($item->edition)
            ->one();

        if ($edition === null) {
            $errors[] = [
                'param' => "{$paramPrefix}.edition",
                'message' => "Invalid Craft edition handle: {$item->edition}",
                'code' => self::ERROR_CODE_MISSING,
            ];
            return null;
        }

        // get the license (if there is one)
        $licenseKey = $item->licenseKey ?? $item->cmsLicenseKey ?? null;
        if (!empty($licenseKey)) {
            try {
                $license = $this->module->getCmsLicenseManager()->getLicenseByKey($licenseKey);
            } catch (LicenseNotFoundException $e) {
                $errors[] = [
                    'param' => $paramPrefix . '.' . (isset($item->licenseKey) ? 'licenseKey' : 'cmsLicenseKey'),
                    'message' => $e->getMessage(),
                    'code' => self::ERROR_CODE_MISSING,
                ];
                return null;
            }

            // Make sure this is actually an upgrade
            if ($edition->getPrice() <= $license->getEdition()->getPrice()) {
                $errors[] = [
                    'param' => "{$paramPrefix}.edition",
                    'message' => "Invalid upgrade edition: {$item->edition}",
                    'code' => self::ERROR_CODE_INVALID,
                ];
                return null;
            }

            $options = [
                'licenseKey' => $license->key,
            ];
        } else {
            // generate a license key now to ensure that the line item options are unique
            $options = [
                'licenseKey' => 'new:' . KeyHelper::generateCmsKey(),
            ];
        }

        if (isset($item->expiryDate)) {
            $options['expiryDate'] = $item->expiryDate;
        }

        if (isset($item->autoRenew)) {
            $options['autoRenew'] = $item->autoRenew;
        }

        return Commerce::getInstance()->getLineItems()->resolveLineItem($cart->id, $edition->id, $options);
    }

    /**
     * @param Order $cart
     * @param \stdClass $item
     * @param string $paramPrefix
     * @param $errors
     * @return LineItem|null
     */
    private function _cmsRenewalLineItem(Order $cart, \stdClass $item, string $paramPrefix, &$errors): ?LineItem
    {
        try {
            $license = $this->module->getCmsLicenseManager()->getLicenseByKey($item->licenseKey);
        } catch (LicenseNotFoundException $e) {
            $errors[] = [
                'param' => "{$paramPrefix}.licenseKey",
                'message' => $e->getMessage(),
                'code' => self::ERROR_CODE_MISSING,
            ];
            return null;
        }

        $renewalId = CmsRenewal::find()
            ->select(['elements.id'])
            ->editionId($license->editionId)
            ->asArray()
            ->scalar();

        $options = [
            'licenseKey' => $item->licenseKey,
        ];

        if (isset($item->expiryDate)) {
            $options['expiryDate'] = $item->expiryDate;
        }

        return Commerce::getInstance()->getLineItems()->resolveLineItem($cart->id, $renewalId, $options);
    }

    /**
     * @param Order $cart
     * @param \stdClass $item
     * @param string $paramPrefix
     * @param $errors
     * @return LineItem|null
     */
    private function _pluginEditionLineItem(Order $cart, \stdClass $item, string $paramPrefix, &$errors): ?LineItem
    {
        // get the plugin
        $plugin = Plugin::find()
            ->handle($item->plugin)
            ->one();

        if (!$plugin) {
            $errors[] = [
                'param' => "{$paramPrefix}.plugin",
                'message' => "Invalid plugin handle: {$item->plugin}",
                'code' => self::ERROR_CODE_MISSING,
            ];
            return null;
        }

        if ($plugin->abandoned) {
            return null;
        }

        // get the edition
        try {
            $edition = $plugin->getEdition($item->edition);
        } catch (InvalidArgumentException $e) {
            $errors[] = [
                'param' => "{$paramPrefix}.edition",
                'message' => $e->getMessage(),
                'code' => self::ERROR_CODE_MISSING,
            ];
            return null;
        }

        // get the Craft license if specified
        if (!empty($item->cmsLicenseKey)) {
            try {
                $cmsLicense = $this->module->getCmsLicenseManager()->getLicenseByKey($item->cmsLicenseKey);
            } catch (LicenseNotFoundException $e) {
                $errors[] = [
                    'param' => "{$paramPrefix}.cmsLicenseKey",
                    'message' => $e->getMessage(),
                    'code' => self::ERROR_CODE_MISSING,
                ];
                return null;
            }
        } else {
            $cmsLicense = null;
        }

        // get the license (if there is one)
        if (!empty($item->licenseKey)) {
            try {
                $license = $this->module->getPluginLicenseManager()->getLicenseByKey($item->licenseKey, $item->plugin);
            } catch (LicenseNotFoundException $e) {
                $errors[] = [
                    'param' => "{$paramPrefix}.licenseKey",
                    'message' => $e->getMessage(),
                    'code' => self::ERROR_CODE_MISSING,
                ];
                return null;
            }

            // Make sure this is actually an upgrade
            $licenseEdition = $license->getEdition();
            if ($licenseEdition && $edition->getPrice() <= $licenseEdition->getPrice()) {
                $errors[] = [
                    'param' => "{$paramPrefix}.edition",
                    'message' => "Invalid upgrade edition: {$item->edition}",
                    'code' => self::ERROR_CODE_INVALID,
                ];
                return null;
            }

            $options = [
                'licenseKey' => $license->key,
            ];
        } else {
            // generate a license key now to ensure that the line item options are unique
            $options = [
                'licenseKey' => 'new:' . KeyHelper::generatePluginKey(),
            ];
        }

        if ($cmsLicense) {
            $options['cmsLicenseKey'] = $cmsLicense->key;
        }

        if (isset($item->expiryDate)) {
            $options['expiryDate'] = $item->expiryDate;
        }

        if (isset($item->autoRenew)) {
            $options['autoRenew'] = $item->autoRenew;
        }

        return Commerce::getInstance()->getLineItems()->resolveLineItem($cart->id, $edition->id, $options);
    }

    /**
     * @param Order $cart
     * @param \stdClass $item
     * @param string $paramPrefix
     * @param $errors
     * @return LineItem|null
     */
    private function _pluginRenewalLineItem(Order $cart, \stdClass $item, string $paramPrefix, &$errors): ?LineItem
    {
        try {
            $license = $this->module->getPluginLicenseManager()->getLicenseByKey($item->licenseKey);
        } catch (LicenseNotFoundException $e) {
            $errors[] = [
                'param' => "{$paramPrefix}.licenseKey",
                'message' => $e->getMessage(),
                'code' => self::ERROR_CODE_MISSING,
            ];
            return null;
        }

        if ($license->getPlugin()->abandoned) {
            return null;
        }

        $renewalId = PluginRenewal::find()
            ->select(['elements.id'])
            ->editionId($license->editionId)
            ->asArray()
            ->scalar();

        $options = [
            'licenseKey' => $item->licenseKey,
        ];

        if ($cmsLicense = $license->getCmsLicense()) {
            $options['cmsLicenseKey'] = $cmsLicense->key;
        }

        if (isset($item->expiryDate)) {
            $options['expiryDate'] = $item->expiryDate;
        }

        return Commerce::getInstance()->getLineItems()->resolveLineItem($cart->id, $renewalId, $options);
    }
}
