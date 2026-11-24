<?php

use App\Libraries\Enrichment\LmsMapowanieClient;
use App\Libraries\Siec\BezpiecznyUrl;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * #431: egress HTTP tylko https + publiczne IP (po resolpcie DNS) — SSRF.
 *
 * @internal
 */
final class BezpiecznyUrlTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BezpiecznyUrl::resetujCache();
    }

    protected function tearDown(): void
    {
        BezpiecznyUrl::resetujCache();
        unset(
            $_SERVER['mcs.lms.baseUrl'],
            $_SERVER['mcs.lms.mapToken'],
            $_SERVER['mcs.lms.apiPath'],
            $_SERVER['mcs.snds.dataUrlTemplate'],
        );
        \App\Libraries\OpcjeMcs::resetuj();
        parent::tearDown();
    }

    public function testSchematHttpJestOdrzucany(): void
    {
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat('http://example.com'));
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat('http://127.0.0.1/'));
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat('ftp://example.com'));
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat('example.com'));
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat(''));
    }

    public function testHttpsZPublicznymHostemPrzechodzi(): void
    {
        $this->assertNull(BezpiecznyUrl::walidujSchemat('https://api.greynoise.io'));
        $this->assertNull(BezpiecznyUrl::walidujSchemat('https://api.abuseipdb.com/api/v2?query=1'));
        $this->assertNull(BezpiecznyUrl::walidujSchemat('https://8.8.8.8/dns'));
    }

    public function testHttpsAlePrywatnyLiteraIpJestOdrzucany(): void
    {
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat('https://127.0.0.1/'));
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat('https://169.254.169.254/latest/meta-data'));
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat('https://10.0.0.5/'));
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat('https://192.168.1.1/'));
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat('https://[::1]/'));
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat('https://[fe80::1]/'));
    }

    public function testBrakHostaLubUserinfoJestOdrzucany(): void
    {
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat('https://'));
        $this->assertNotNull(BezpiecznyUrl::walidujSchemat('https://user:pass@example.com/'));
    }

    public function testIpPubliczny(): void
    {
        foreach (['127.0.0.1', '169.254.169.254', '10.1.2.3', '172.16.0.1', '192.168.0.1', '100.64.0.1', '0.0.0.0', '224.0.0.1', '::1', 'fe80::1', 'fc00::1', '2001:db8::1'] as $ip) {
            $this->assertFalse(BezpiecznyUrl::ipPubliczny($ip), $ip);
        }
        foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111'] as $ip) {
            $this->assertTrue(BezpiecznyUrl::ipPubliczny($ip), $ip);
        }
        $this->assertFalse(BezpiecznyUrl::ipPubliczny('nie-ip'));
    }

    public function testWalidujOdrzucaPrywatneIpPoResolpcieDns(): void
    {
        $resolver = static fn (string $host): array => $host === 'wewn.test' ? ['10.0.0.9'] : ['93.184.216.34'];

        $this->assertNotNull(BezpiecznyUrl::waliduj('https://wewn.test/api', $resolver));
        $this->assertNull(BezpiecznyUrl::waliduj('https://zewn.test/api', $resolver));
    }

    public function testWalidujFailClosedGdyBrakResolpcji(): void
    {
        $resolver = static fn (string $host): array => [];

        $this->assertNotNull(BezpiecznyUrl::waliduj('https://niedziala.test/', $resolver));
    }

    public function testBaseUrlZEnvOdrzucaHttpZWarningiem(): void
    {
        $this->assertSame('', BezpiecznyUrl::baseUrlZEnv('http://127.0.0.1/', 'mcs.test.baseUrl'));
        $this->assertLogContains('warning', 'MCS egress odrzucony');
        $this->assertLogContains('warning', 'mcs.test.baseUrl');

        $this->assertSame('https://api.example.com', BezpiecznyUrl::baseUrlZEnv('https://api.example.com', 'mcs.test.baseUrl'));
        $this->assertSame('', BezpiecznyUrl::baseUrlZEnv('', 'mcs.test.baseUrl'));
    }

    public function testLmsApiPathHttp127JestOdrzucanyPrzezFromEnv(): void
    {
        // DoD #431: mcs.lms.apiPath = 'http://127.0.0.1/' zostaje odrzucony.
        $_SERVER['mcs.lms.baseUrl'] = 'https://lms.example.test';
        $_SERVER['mcs.lms.mapToken'] = 'tok';
        $_SERVER['mcs.lms.apiPath'] = 'http://127.0.0.1/';
        \App\Libraries\OpcjeMcs::resetuj();

        $client = LmsMapowanieClient::fromEnv();

        $this->assertFalse($client->hasConfig());
        $this->assertLogContains('warning', 'MCS egress odrzucony dla mcs.lms.apiPath');
        $this->assertLogContains('warning', 'host=127.0.0.1');
    }

    public function testLmsBaseUrlPrywatnyJestOdrzucanyPrzezFromEnv(): void
    {
        $_SERVER['mcs.lms.baseUrl'] = 'http://169.254.169.254/';
        $_SERVER['mcs.lms.mapToken'] = 'tok';
        \App\Libraries\OpcjeMcs::resetuj();

        $client = LmsMapowanieClient::fromEnv();

        $this->assertFalse($client->hasConfig());
        $this->assertLogContains('warning', 'MCS egress odrzucony dla mcs.lms.baseUrl');
    }

    public function testLmsFromEnvHttpsPrzechodzi(): void
    {
        $_SERVER['mcs.lms.baseUrl'] = 'https://lms.example.test';
        $_SERVER['mcs.lms.mapToken'] = 'tok';
        unset($_SERVER['mcs.lms.apiPath']);
        \App\Libraries\OpcjeMcs::resetuj();

        $client = LmsMapowanieClient::fromEnv();

        $this->assertTrue($client->hasConfig());
        $this->assertSame('https://lms.example.test', $client->getBaseUrl());
    }

    public function testLmsRequestOdrzucaEgressHttpBezMocka(): void
    {
        // Bez wstrzykniętego httpFn (ścieżka produkcyjna curl) pełny URL z apiPath http jest blokowany przed siecią.
        $client = new LmsMapowanieClient('https://lms.example.test', 'tok', 'http://127.0.0.1/steal', 5, true, null);

        $wynik = $client->lookup('198.51.100.10');

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('egress', $wynik['error']);
        $this->assertLogContains('warning', 'MCS LMS egress odrzucony');
    }

    public function testLmsRequestOdrzucaLocalhostPoResolpcieDns(): void
    {
        $client = new LmsMapowanieClient('https://lms.example.test', 'tok', 'https://localhost/api.php', 5, true, null);

        $wynik = $client->health();

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('egress', $wynik['error']);
    }

    public function testSndsPobierzDzienOdrzucaNiebezpiecznyTemplate(): void
    {
        $_SERVER['mcs.snds.dataUrlTemplate'] = 'http://169.254.169.254/x?Key=%s&Date=%s';

        $plugin = new \McsPluginy\snds\Plugin();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/egress/');
        try {
            $plugin->pobierzDzien('uuid-test', '101225');
        } finally {
            $this->assertLogContains('warning', 'MCS SNDS egress odrzucony');
        }
    }
}
