<?php

namespace craftnettests\unit\cms;

use Codeception\Test\Unit;
use craftnet\Module;

class CmsLicenseManagerTest extends Unit
{
    /**
     * @dataProvider normalizeDomainDataProvider
     * @param $domain
     * @param $expected
     * @param bool $allowCustom
     */
    public function testNormalizeDomain($domain, $expected, bool $allowCustom = false)
    {
        $actual = Module::getInstance()->getCmsLicenseManager()->normalizeDomain($domain, $allowCustom);
        self::assertSame($expected, $actual);
    }

    /**
     * @return array
     */
    public function normalizeDomainDataProvider(): array
    {
        return [
            ['localhost', null],
            ['127.0.0.1', null],
            ['22.2.55.15', null],
            ['1', null],
            ['foobar', null],
            ['foobar.test', null],
            ['foobar.test', 'foobar.test', true],
            ['foobar.com:123', null],
            ['127.0.0.1:123', null],
            ['foobar:123', null],
            ['acc.site.com', null],
            ['acceptance.site.com', null],
            ['craftdemo.site.com', null],
            ['dev.site.com', null],
            ['integration.site.com', null],
            ['loc.site.com', null],
            ['local.site.com', null],
            ['preprod.site.com', null],
            ['qa.site.com', null],
            ['sandbox.site.com', null],
            ['stage.site.com', null],
            ['staging.site.com', null],
            ['systest.site.com', null],
            ['test.site.com', null],
            ['testing.site.com', null],
            ['uat.site.com', null],
            ['clientdev.site.com', 'site.com'],
            ['client-dev.site.com', null],
            ['site.foobar', null],
            ['xn--bcher-kva.com', 'bücher.com'],
            ['site.co.uk', 'site.co.uk'],
            ['site.clientdev.co.uk', 'clientdev.co.uk'],
            ['client-dev.co.uk', 'client-dev.co.uk'],
            ['dev-client.site.co.uk', null],
            ['dev.client.co.uk', null],
            ['craftcms.com', null],
            ['craftdemos.io', null],
            ['ngrok.io', null],
            ['herokuapp.com', null],
            ['site.herokuapp.com', null],
            ['dev.site.kr.com', null],
            ['site.kr.com', 'site.kr.com'],
            ['https://localhost', null],
            ['https://127.0.0.1', null],
            ['https://22.2.55.15', null],
            ['https://1', null],
            ['https://foobar', null],
            ['https://foobar.test', null],
            ['https://foobar.test', 'foobar.test', true],
            ['https://foobar.com:123', null],
            ['https://127.0.0.1:123', null],
            ['https://foobar:123', null],
            ['https://acc.site.com', null],
            ['https://acceptance.site.com', null],
            ['https://craftdemo.site.com', null],
            ['https://dev.site.com', null],
            ['https://integration.site.com', null],
            ['https://loc.site.com', null],
            ['https://local.site.com', null],
            ['https://preprod.site.com', null],
            ['https://qa.site.com', null],
            ['https://sandbox.site.com', null],
            ['https://stage.site.com', null],
            ['https://staging.site.com', null],
            ['https://systest.site.com', null],
            ['https://test.site.com', null],
            ['https://testing.site.com', null],
            ['https://uat.site.com', null],
            ['https://clientdev.site.com', 'site.com'],
            ['client-dev.site.com', null],
            ['https://site.foobar', null],
            ['https://xn--bcher-kva.com', 'bücher.com'],
            ['https://site.co.uk', 'site.co.uk'],
            ['https://site.clientdev.co.uk', 'clientdev.co.uk'],
            ['https://client-dev.co.uk', 'client-dev.co.uk'],
            ['https://dev-client.site.co.uk', null],
            ['https://dev.client.co.uk', null],
            ['https://craftcms.com', null],
            ['https://craftdemos.io', null],
            ['https://ngrok.io', null],
            ['https://herokuapp.com', null],
            ['https://site.herokuapp.com', null],
            ['https://dev.site.kr.com', null],
            ['https://site.kr.com', 'site.kr.com'],
        ];
    }
}
