<?php

namespace craftnet;

use Craft;
use craft\awss3\Volume as S3Volume;
use craft\commerce\elements\Order;
use craft\commerce\events\MatchLineItemEvent;
use craft\commerce\events\PdfEvent;
use craft\commerce\models\Discount;
use craft\commerce\services\Discounts;
use craft\commerce\services\OrderAdjustments;
use craft\commerce\services\Pdfs;
use craft\commerce\services\Purchasables;
use craft\console\Controller as ConsoleController;
use craft\console\controllers\ResaveController;
use craft\controllers\UsersController;
use craft\elements\Asset;
use craft\elements\db\UserQuery;
use craft\elements\User;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineConsoleActionsEvent;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\DeleteElementEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\UserEvent;
use craft\fieldlayoutelements\StandardTextField;
use craft\helpers\App;
use craft\models\FieldLayout;
use craft\models\SystemMessage;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\services\Users;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\volumes\Local as LocalVolume;
use craft\web\Request;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\View;
use craftnet\behaviors\AssetBehavior;
use craftnet\behaviors\DiscountBehavior;
use craftnet\behaviors\OrderBehavior;
use craftnet\behaviors\UserBehavior;
use craftnet\behaviors\UserQueryBehavior;
use craftnet\cms\CmsEdition;
use craftnet\cms\CmsLicenseManager;
use craftnet\composer\JsonDumper;
use craftnet\composer\PackageManager;
use craftnet\db\Table;
use craftnet\fields\Plugins;
use craftnet\helpers\Cache;
use craftnet\invoices\InvoiceManager;
use craftnet\orders\PdfRenderer;
use craftnet\payouts\PayoutManager;
use craftnet\plugins\Plugin;
use craftnet\plugins\PluginEdition;
use craftnet\plugins\PluginLicenseManager;
use craftnet\sales\SaleManager;
use craftnet\services\Oauth;
use craftnet\utilities\PullProduction;
use craftnet\utilities\SalesReport;
use craftnet\utilities\UnavailablePlugins;
use yii\base\ActionEvent;
use yii\base\Event;
use yii\db\Query;
use yii\web\ForbiddenHttpException;

/**
 * @property-read CmsLicenseManager $cmsLicenseManager
 * @property-read InvoiceManager $invoiceManager
 * @property-read JsonDumper $jsonDumper
 * @property-read Oauth $oauth
 * @property-read PackageManager $packageManager
 * @property-read PayoutManager $payoutManager
 * @property-read PluginLicenseManager $pluginLicenseManager
 * @property-read SaleManager $saleManager
 */
class Module extends \yii\base\Module
{
    const MESSAGE_KEY_RECEIPT = 'craftnet_receipt';
    const MESSAGE_KEY_VERIFY = 'verify_email';
    const MESSAGE_KEY_DEVELOPER_SALE = 'developer_sale';
    const MESSAGE_KEY_LICENSE_REMINDER = 'license_reminder';
    const MESSAGE_KEY_LICENSE_NOTIFICATION = 'license_notification';
    const MESSAGE_KEY_LICENSE_TRANSFER = 'license_transfer';
    const MESSAGE_KEY_SECURITY_ALERT = 'security_alert';

    /**
     * @inheritdoc
     */
    public function init()
    {
        Craft::setAlias('@craftnet', __DIR__);

        // define custom behaviors
        Event::on(Asset::class, Asset::EVENT_DEFINE_BEHAVIORS, function(DefineBehaviorsEvent $e) {
            $e->behaviors['cn.asset'] = AssetBehavior::class;
        });
        Event::on(UserQuery::class, UserQuery::EVENT_DEFINE_BEHAVIORS, function(DefineBehaviorsEvent $e) {
            $e->behaviors['cn.userQuery'] = UserQueryBehavior::class;
        });
        Event::on(User::class, User::EVENT_DEFINE_BEHAVIORS, function(DefineBehaviorsEvent $e) {
            $e->behaviors['cn.user'] = UserBehavior::class;
        });
        Event::on(Order::class, Order::EVENT_DEFINE_BEHAVIORS, function(DefineBehaviorsEvent $e) {
            $e->behaviors['cn.order'] = OrderBehavior::class;
        });
        Event::on(Discount::class, Discount::EVENT_DEFINE_BEHAVIORS, function(DefineBehaviorsEvent $e) {
            $e->behaviors['cn.discount'] = DiscountBehavior::class;
        });

        // register custom component types
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $e) {
            $e->types[] = Plugins::class;
        });
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITY_TYPES, function(RegisterComponentTypesEvent $e) {
            $e->types[] = UnavailablePlugins::class;
            $e->types[] = SalesReport::class;
            $e->types[] = PullProduction::class;
        });
        Event::on(Purchasables::class, Purchasables::EVENT_REGISTER_PURCHASABLE_ELEMENT_TYPES, function(RegisterComponentTypesEvent $e) {
            $e->types[] = CmsEdition::class;
            $e->types[] = PluginEdition::class;
        });
        Event::on(OrderAdjustments::class, OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS, function(RegisterComponentTypesEvent $e) {
            $e->types[] = OrderAdjuster::class;
        });

        // register our custom receipt system message
        Event::on(SystemMessages::class, SystemMessages::EVENT_REGISTER_MESSAGES, function(RegisterEmailMessagesEvent $e) {
            $e->messages[] = new SystemMessage([
                'key' => self::MESSAGE_KEY_RECEIPT,
                'heading' => 'When someone places an order:',
                'subject' => 'Your receipt from {{ fromName }}',
                'body' => file_get_contents(__DIR__ . '/emails/receipt.md'),
            ]);
            $e->messages[] = new SystemMessage([
                'key' => self::MESSAGE_KEY_VERIFY,
                'heading' => 'When someone wants to claim licenses by an email address:',
                'subject' => 'Verify your email',
                'body' => file_get_contents(__DIR__ . '/emails/verify.md'),
            ]);
            $e->messages[] = new SystemMessage([
                'key' => self::MESSAGE_KEY_DEVELOPER_SALE,
                'heading' => 'When a plugin developer makes a sale:',
                'subject' => 'Craft Plugin Store Sale',
                'body' => file_get_contents(__DIR__ . '/emails/developer_sale.md'),
            ]);
            $e->messages[] = new SystemMessage([
                'key' => self::MESSAGE_KEY_LICENSE_REMINDER,
                'heading' => 'When licenses will be expiring/auto-renewing soon:',
                'subject' => 'Important license info',
                'body' => file_get_contents(__DIR__ . '/emails/license_reminder.md'),
            ]);
            $e->messages[] = new SystemMessage([
                'key' => self::MESSAGE_KEY_LICENSE_NOTIFICATION,
                'heading' => 'When licenses have expired/auto-renewed:',
                'subject' => 'Important license info',
                'body' => file_get_contents(__DIR__ . '/emails/license_notification.md'),
            ]);
            $e->messages[] = new SystemMessage([
                'key' => self::MESSAGE_KEY_LICENSE_TRANSFER,
                'heading' => 'When a license has been transferred to a new plugin/edition:',
                'subject' => 'Important license info',
                'body' => file_get_contents(__DIR__ . '/emails/license_transfer.md'),
            ]);
            $e->messages[] = new SystemMessage([
                'key' => self::MESSAGE_KEY_SECURITY_ALERT,
                'heading' => 'When a critical update is available:',
                'subject' => 'Urgent: You must update {{ name }} now',
                'body' => file_get_contents(__DIR__ . '/emails/security_alert.md'),
            ]);
        });

        // claim Craft/plugin licenses after user activation
        Event::on(Users::class, Users::EVENT_AFTER_ACTIVATE_USER, function(UserEvent $e) {
            $this->getCmsLicenseManager()->claimLicenses($e->user);
            $this->getPluginLicenseManager()->claimLicenses($e->user);
        });

        Event::on(Discounts::class, Discounts::EVENT_DISCOUNT_MATCHES_LINE_ITEM, function(MatchLineItemEvent $e) {
            if ($e->discount->code == 'FREEFORM') {

                $sku = $e->lineItem->getSku();

                // Is this Freeform Pro?
                if ($sku == 'FREEFORM-PRO') {
                    $options = $e->lineItem->getOptions();
                    if (isset($options['licenseKey'])) {
                        // Grab the existing Freeform license key
                        $key = $options['licenseKey'];
                        $license = $this->getPluginLicenseManager()->getLicenseByKey($key);

                        // See if this license has an existing lineItem associated with it
                        $lineItemId = (new Query())
                            ->select('lineItemId')
                            ->from(Table::PLUGINLICENSES_LINEITEMS)
                            ->where(['licenseId' => $license->id])
                            ->scalar();

                        if ($lineItemId) {
                            $lineItem = \craft\commerce\Plugin::getInstance()->getLineItems()->getLineItemById($lineItemId);

                            // See if the existing lineItem already had this discount applied
                            if ($lineItem && $lineItem->getOrder()->couponCode == 'FREEFORM') {
                                // Can't use it again.
                                $e->isValid = false;
                            }
                        }
                    }
                }
            }
        });

        // provide custom order receipt PDF generation
        Event::on(Pdfs::class, Pdfs::EVENT_BEFORE_RENDER_PDF, function(PdfEvent $e) {
            $e->pdf = (new PdfRenderer())->render($e->order);
        });

        // hard-delete plugins
        Event::on(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT, function(DeleteElementEvent $e) {
            if ($e->element instanceof Plugin) {
                $e->hardDelete = true;
            }
        });

        // request type-specific stuff
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            $this->controllerNamespace = 'craftnet\\console\\controllers';
            $this->_initConsoleRequset();
        } else {
            $this->controllerNamespace = 'craftnet\\controllers';

            if ($request->getIsCpRequest()) {
                $this->_initCpRequest();
            } else {
                $this->_initSiteRequest($request);
            }
        }

        // use Local volumes in dev for web requests
        if (\Craft::$app->env === 'dev' && !Craft::$app->getRequest()->getIsConsoleRequest()) {
            \Craft::$container->set(S3Volume::class, function($container, $params, $config) {
                if (empty($config['id'])) {
                    return new S3Volume($config);
                }

                return new LocalVolume([
                    'id' => $config['id'],
                    'uid' => $config['uid'],
                    'name' => $config['name'],
                    'handle' => $config['handle'],
                    'hasUrls' => $config['hasUrls'],
                    'url' => "@web/local-volumes/{$config['handle']}",
                    'path' => "@webroot/local-volumes/{$config['handle']}",
                    'sortOrder' => $config['sortOrder'],
                    'dateCreated' => $config['dateCreated'],
                    'dateUpdated' => $config['dateUpdated'],
                    'fieldLayoutId' => $config['fieldLayoutId'],
                ]);
            });
        }


        parent::init();
    }

    /**
     * @return CmsLicenseManager
     */
    public function getCmsLicenseManager(): CmsLicenseManager
    {
        return $this->get('cmsLicenseManager');
    }

    /**
     * @return InvoiceManager
     */
    public function getInvoiceManager(): InvoiceManager
    {
        return $this->get('invoiceManager');
    }

    /**
     * @return PluginLicenseManager
     */
    public function getPluginLicenseManager(): PluginLicenseManager
    {
        return $this->get('pluginLicenseManager');
    }

    /**
     * @return PackageManager
     */
    public function getPackageManager(): PackageManager
    {
        return $this->get('packageManager');
    }

    /**
     * @return JsonDumper
     */
    public function getJsonDumper(): JsonDumper
    {
        return $this->get('jsonDumper');
    }

    /**
     * @return Oauth
     */
    public function getOauth(): Oauth
    {
        return $this->get('oauth');
    }

    /**
     * @return SaleManager
     */
    public function getSaleManager(): SaleManager
    {
        return $this->get('saleManager');
    }

    /**
     * @return PayoutManager
     */
    public function getPayoutManager(): PayoutManager
    {
        return $this->get('payoutManager');
    }

    private function _initConsoleRequset()
    {
        Event::on(ResaveController::class, ConsoleController::EVENT_DEFINE_ACTIONS, function(DefineConsoleActionsEvent $e) {
            $e->actions['plugins'] = [
                'action' => function(): int {
                    /** @var ResaveController $controller */
                    $controller = Craft::$app->controller;
                    return $controller->saveElements(Plugin::find());
                },
                'options' => [],
                'helpSummary' => 'Re-saves Plugin Store plugins.',
            ];
        });
    }

    private function _initCpRequest()
    {
        $this->controllerNamespace = 'craftnet\\controllers';

        Event::on(Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS, function(RegisterCpNavItemsEvent $e) {
            $e->navItems[] = [
                'url' => 'cmslicenses',
                'label' => 'Craft Licenses',
            ];

            $e->navItems[] = [
                'url' => 'plugins',
                'label' => 'Plugins',
                'fontIcon' => 'plugin',
            ];

            $e->navItems[] = [
                'url' => 'partners',
                'label' => 'Partners',
                'icon' => __DIR__ . '/icons/partner.svg',
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $e) {
            $e->rules = array_merge($e->rules, [
                'cmslicenses' => 'craftnet/cms-licenses',
                'plugins' => ['template' => 'craftnet/plugins/_index'],
                'plugins/new' => 'craftnet/plugins/edit',
                'plugins/<pluginId:\d+><slug:(?:-[^\/]*)?>' => 'craftnet/plugins/edit',
                'partners' => ['template' => 'craftnet/partners/_index'],
                'partners/new' => 'craftnet/partners/edit',
                'partners/<partnerId:\d+><slug:(?:-[^\/]*)?>' => 'craftnet/partners/edit',
                'partners/foo' => 'craftnet/partners/foo',
                'GET partners/history/<partnerId:\d+>' => 'craftnet/partners/fetch-history',
                'POST partners/history' => 'craftnet/partners/save-history',
                'DELETE partners/history/<id:\d+>' => 'craftnet/partners/delete-history',
            ]);
        });

        Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $e) {
            $e->roots['craftnet'] = __DIR__ . '/templates';
        });

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $e) {
            $e->permissions['Craftcom'] = [
                'craftnet:managePlugins' => [
                    'label' => 'Manage plugins',
                ],
            ];
        });

        Event::on(FieldLayout::class, FieldLayout::EVENT_DEFINE_STANDARD_FIELDS, function(DefineFieldLayoutFieldsEvent $e) {
            /** @var FieldLayout $fieldLayout */
            $fieldLayout = $e->sender;

            switch ($fieldLayout->type) {
                case User::class:
                    $e->fields[] = [
                        'class' => StandardTextField::class,
                        'attribute' => 'payPalEmail',
                        'label' => 'PayPal Email',
                        'mandatory' => true,
                    ];
                    break;
            }
        });

        Event::on(ClearCaches::class, ClearCaches::EVENT_REGISTER_TAG_OPTIONS, function(RegisterCacheOptionsEvent $event) {
            $event->options[] = [
                'tag' => Cache::tag(Cache::TAG_PACKAGES),
                'label' => Craft::t('app', 'Packages'),
            ];
            $event->options[] = [
                'tag' => Cache::tag(Cache::TAG_PLUGIN_CHANGELOGS),
                'label' => Craft::t('app', 'Plugin changelogs'),
            ];
            $event->options[] = [
                'tag' => Cache::tag(Cache::TAG_PLUGIN_ICONS),
                'label' => Craft::t('app', 'Plugin icons'),
            ];
        });
    }

    private function _initSiteRequest(Request $request)
    {
        $idOrigin = rtrim(App::env('URL_ID'), '/');

        if (Craft::$app->getRequest()->getOrigin() === $idOrigin) {
            Craft::$app->getResponse()->getHeaders()->set('Access-Control-Allow-Origin', $idOrigin);
            Craft::$app->getResponse()->getHeaders()->set('Access-Control-Allow-Credentials', 'true');
        } else {
            Craft::$app->getResponse()->getHeaders()->set('Access-Control-Allow-Origin', '*');
        }

        Event::on(UsersController::class, UsersController::EVENT_BEFORE_ACTION, function(ActionEvent $event) use ($request) {
            if ($event->action->id == 'save-user') {
                $fieldsLocation = $request->getParam('fieldsLocation') ?? 'fields';
                $fields = $request->getBodyParam($fieldsLocation) ?? [];

                foreach ($fields as $key => $value) {
                    // Throw an exception if the field is disallowed.
                    if (in_array($key, ['supportPlan', 'supportPlanExpiryDate'])) {
                        throw new ForbiddenHttpException('One or more disallowed fields were submitted.');
                    }
                }
            }
        });
    }
}
