<?php
/**
 * General Configuration
 *
 * All of your system's general configuration settings go in here. You can see a
 * list of the available settings in vendor/craftcms/cms/src/config/GeneralConfig.php.
 */

use craft\helpers\App;

return [
    '*' => [
        'aliases' => [
            '@webroot' => dirname(__DIR__) . '/web',
            '@nodeModules' => dirname(__DIR__) . '/node_modules',
        ],
        'allowUpdates' => false,
        'devMode' => isset($_REQUEST['secret']) && $_REQUEST['secret'] === App::env('DEV_MODE_SECRET'),
        'omitScriptNameInUrls' => true,
        'baseCpUrl' => App::env('URL_ID'),
        'cpTrigger' => App::env('CRAFT_CP_TRIGGER'),
        'imageDriver' => 'gd',
        'preventUserEnumeration' => true,
        'securityKey' => App::env('CRAFT_SECURITY_KEY'),
        'csrfTokenName' => 'CRAFTNET_CSRF_TOKEN',
        'phpSessionName' => 'CraftnetSessionId',
        'generateTransformsBeforePageLoad' => true,
        'activateAccountSuccessPath' => '/login?activated=1',
        'backupOnUpdate' => false,
        'backupCommand' => 'PGPASSWORD="{password}" ' .
            'pg_dump ' .
            '--dbname={database} ' .
            '--host={server} ' .
            '--port={port} ' .
            '--username={user} ' .
            '--if-exists ' .
            '--clean ' .
            '--file="{file}" ' .
            '--schema={schema} ' .
            '--schema=apilog ' .
            '--exclude-table-data \'{schema}.assetindexdata\' ' .
            '--exclude-table-data \'{schema}.assettransformindex\' ' .
            '--exclude-table-data \'{schema}.cache\' ' .
            '--exclude-table-data \'{schema}.sessions\' ' .
            '--exclude-table-data \'{schema}.templatecaches\' ' .
            '--exclude-table-data \'{schema}.templatecachecriteria\' ' .
            '--exclude-table-data \'{schema}.templatecacheelements\' ' .
            '--exclude-table-data \'apilog.logs\' ' .
            '--exclude-table-data \'apilog.request_cmslicenses\' ' .
            '--exclude-table-data \'apilog.request_errors\' ' .
            '--exclude-table-data \'apilog.request_pluginlicenses\' ' .
            '--exclude-table-data \'apilog.requests\'',
        'testToEmailAddress' => App::env('TEST_EMAIL') ?: null
    ],
    'prod' => [
        'defaultCookieDomain' => '.craftcms.com',
        'runQueueAutomatically' => false,
    ],
    'stage' => [
        'defaultCookieDomain' => '.craftcms.com',
    ],
    'dev' => [
        'devMode' => true,
        'useCompressedJs' => false,
        'allowUpdates' => true,
        'defaultCookieDomain' => '.craftcms.test',
        'enableBasicHttpAuth' => true,
    ],
    'next' => [
        'devMode' => true,
        'useCompressedJs' => false,
        'allowUpdates' => true,
        'defaultCookieDomain' => '.craftcms.next',
    ]
];
