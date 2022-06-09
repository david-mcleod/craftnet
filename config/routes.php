<?php
/**
 * Site URL Rules
 *
 * You can define custom site URL rules here, which Craft will check in addition
 * to any routes you’ve defined in Settings → Routes.
 *
 * See http://www.yiiframework.com/doc-2.0/guide-runtime-routing.html for more
 * info about URL rules.
 *
 * In addition to Yii’s supported syntaxes, Craft supports a shortcut syntax for
 * defining template routes:
 *
 *     'blog/archive/<year:\d{4}>' => ['template' => 'blog/_archive'],
 *
 * That example would match URIs such as `/blog/archive/2012`, and pass the
 * request along to the `blog/_archive` template, providing it a `year` variable
 * set to the value `2012`.
 */

return [
    'api' => [
        'OPTIONS <uri:.*>' => 'craftnet/api/options',
        'GET     v1/account' => 'craftnet/api/v1/account',
        'POST    v1/available-plugins' => 'craftnet/api/v1/available-plugins',
        'POST    v1/carts' => 'craftnet/api/v1/carts/create',
        'GET     v1/carts/<orderNumber:.*>' => 'craftnet/api/v1/carts/get',
        'POST    v1/carts/<orderNumber:.*>' => 'craftnet/api/v1/carts/update',
        'DELETE  v1/carts/<orderNumber:.*>' => 'craftnet/api/v1/carts/delete',
        'POST    v1/checkout' => 'craftnet/api/v1/checkout',
        'GET     v1/cms-editions' => 'craftnet/api/v1/cms-editions/get',
        'GET     v1/cms-licenses' => 'craftnet/api/v1/cms-licenses/get',
        'POST    v1/cms-licenses' => 'craftnet/api/v1/cms-licenses/create',
        'GET     v1/countries' => 'craftnet/api/v1/countries',
        'GET     v1/developer/<userId:\d+>' => 'craftnet/api/v1/developer',
        'POST    v1/optimize-composer-reqs' => 'craftnet/api/v1/optimize-composer-reqs',
        'POST    v1/composer-whitelist' => 'craftnet/api/v1/composer-whitelist',
        'POST    v1/partners' => 'craftnet/api/v1/partners/list',
        'POST    v1/partners/<id:\d+>' => 'craftnet/api/v1/partners/get',
        'POST    v1/payments' => 'craftnet/api/v1/payments/pay',
        'GET     v1/ping' => 'craftnet/api/v1/utils/ping',
        'GET     v1/plugin-licenses' => 'craftnet/api/v1/plugin-licenses/list',
        'POST    v1/plugin-licenses' => 'craftnet/api/v1/plugin-licenses/create',
        'GET     v1/plugin-licenses/<key:.*>' => 'craftnet/api/v1/plugin-licenses/get',
        'GET     v1/plugin-store' => 'craftnet/api/v1/plugin-store',
        'GET     v1/plugin-store/core-data' => 'craftnet/api/v1/plugin-store/core-data',
        'GET     v1/plugin-store/featured-section/<handle:{slug}>' => 'craftnet/api/v1/plugin-store/featured-section',
        'GET     v1/plugin-store/featured-sections' => 'craftnet/api/v1/plugin-store/featured-sections',
        'GET     v1/plugin-store/plugin/<handle:{slug}>' => 'craftnet/api/v1/plugin-store/plugin',
        'GET     v1/plugin-store/plugins' => 'craftnet/api/v1/plugin-store/plugins',
        'GET     v1/plugin-store/plugins-by-featured-section/<handle:{slug}>' => 'craftnet/api/v1/plugin-store/plugins-by-featured-section',
        'GET     v1/plugin-store/plugins-by-handles' => 'craftnet/api/v1/plugin-store/plugins-by-handles',
        'GET     v1/plugin/<pluginId:\d+>' => 'craftnet/api/v1/plugin',
        'GET     v1/plugin/<pluginId:\d+>/changelog' => 'craftnet/api/v1/plugin/changelog',
        'GET     v1/plugins' => 'craftnet/api/v1/plugins',
        'GET     v1/package/<packageName:[\w\-]+/[\w\-]+>' => 'craftnet/api/v1/package/get',
        'POST    v1/support' => 'craftnet/api/v1/support/create',
        'GET     v1/upgrade-info' => 'craftnet/api/v1/upgrade-info',
        'GET     v1/updates' => 'craftnet/api/v1/updates',
        'POST    v1/updates' => 'craftnet/api/v1/updates/old',
        'POST    v1/utils/releases-2-changelog' => 'craftnet/api/v1/utils/releases-2-changelog',
        'POST    webhook/github' => 'craftnet/api/webhook/github',
        'POST    front/create-ticket' => 'craftnet/api/front/create-ticket',
        'POST    front/test' => 'craftnet/api/front/test',
        'GET     front' => 'craftnet/api/front',
        'GET     front/get-license-info' => 'craftnet/api/front/get-license-info',
    ],
    'craftId' => [
        'POST    queue/handle-message' => 'craftnet/queue/handle-message',

        'GET     v1/id' => 'craftnet/id/v1/id',
        'GET     craft-id/countries' => 'craftnet/id/craft-id/countries',
        'GET     apps/connect/<appTypeHandle:{handle}>' => 'craftnet/id/apps/connect',
        'GET     apps/callback' => 'craftnet/id/apps/callback',
        'GET     apps/disconnect/<appTypeHandle:{handle}>' => 'craftnet/id/apps/disconnect',

        'POST    v1/carts' => 'craftnet/api/v1/carts/create',
        'GET     v1/carts/<orderNumber:.*>' => 'craftnet/api/v1/carts/get',
        'POST    v1/carts/<orderNumber:.*>' => 'craftnet/api/v1/carts/update',
        'DELETE  v1/carts/<orderNumber:.*>' => 'craftnet/api/v1/carts/delete',
        'POST    v1/checkout' => 'craftnet/api/v1/checkout',
        'POST    v1/payments' => 'craftnet/api/v1/payments/pay',

        'GET     stripe/connect' => 'craftnet/id/stripe/connect',
        'GET     stripe/account' => 'craftnet/id/stripe/account',
        'POST    stripe/disconnect' => 'craftnet/id/stripe/disconnect',
        'GET     stripe/customer' => 'craftnet/id/stripe/customer',
        'POST    stripe/save-card' => 'craftnet/id/stripe/save-card',
        'POST    stripe/remove-card' => 'craftnet/id/stripe/remove-card',

        'oauth/login' => 'oauth-server/oauth/login',
        'oauth/authorize' => 'oauth-server/oauth/authorize',
        'oauth/access-token' => 'oauth-server/oauth/access-token',
        'oauth/revoke' => 'oauth-server/oauth/revoke',

        // Catch-all route for Vue when people reload the page.
        '<url:(?!setpassword$).*>' => 'craftnet/id/index',
    ],
    'plugins' => [
        '/' => 'craftnet/plugins/index/index',
        '<url:(.*)>' => 'craftnet/plugins/index/index',
    ],
    'feeds' => [
        'new.atom' => 'craftnet/feeds/feeds/new',
        'releases.atom' => 'craftnet/feeds/feeds/releases',
        'critical.atom' => 'craftnet/feeds/feeds/critical',
        'cms.atom' => 'craftnet/feeds/feeds/cms',
        '<handle:[\w\-]+>.atom' => 'craftnet/feeds/feeds/plugin',
    ]
];
