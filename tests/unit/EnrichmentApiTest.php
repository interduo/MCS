<?php

use App\Controllers\Api\Enrichment;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Modules;
use Config\Routing;
use Config\Services;

/**
 * Issue #461: jeden kontroler Api\Enrichment::check(string $zrodlo) z whitelistą źródeł —
 * stare URL-e api/enrichment/{abuseipdb,dnsbl,greynoise,ismalicious,lms,spamrats} działają
 * dalej 1:1 (alias segmentu), kontrakt JSON odpowiedzi bez zmian.
 *
 * @internal
 */
final class EnrichmentApiTest extends CIUnitTestCase
{
    private const LEGACY = ['abuseipdb', 'dnsbl', 'greynoise', 'ismalicious', 'lms', 'spamrats'];

    private function wywolaj(string $zrodlo, array $get): Response
    {
        $config          = config(App::class);
        $config->baseURL = 'http://example.com/';
        $uri             = new URI('http://example.com/api/enrichment/' . $zrodlo);
        $request         = new IncomingRequest($config, $uri, null, new UserAgent());
        $request->setMethod('GET');
        $request->setGlobal('get', $get);

        $controller = new Enrichment();
        $controller->initController($request, service('response'), service('logger'));

        return $controller->check($zrodlo);
    }

    /**
     * @return array<string, mixed>
     */
    private function cialo(Response $resp): array
    {
        $body = json_decode((string) $resp->getBody(), true);

        return is_array($body) ? $body : [];
    }

    public function testTrasaEnrichmentWskazujeJedenKontrolerParametryzowany(): void
    {
        $routes = new RouteCollection(Services::locator(), new Modules(), config(Routing::class));
        require APPPATH . 'Config/Routes.php';

        $get = $routes->getRoutes('GET');
        // (:segment) jest kompilowane do regexu ([^/]+)
        $this->assertArrayHasKey('api/enrichment/([^/]+)', $get);
        $handler = (string) $get['api/enrichment/([^/]+)'];
        $this->assertStringContainsString('Api\Enrichment::check/$1', $handler);

        // żaden route nie wskazuje już na stare 6 kontrolerów
        foreach ($get as $route => $h) {
            $h = is_array($h) ? (string) ($h['handler'] ?? '') : (string) $h;
            $this->assertStringNotContainsString('EnrichmentAbuseipdb::', $h, (string) $route);
            $this->assertStringNotContainsString('EnrichmentDnsbl::', $h, (string) $route);
            $this->assertStringNotContainsString('EnrichmentGreynoise::', $h, (string) $route);
            $this->assertStringNotContainsString('EnrichmentIsmalicious::', $h, (string) $route);
            $this->assertStringNotContainsString('EnrichmentLms::', $h, (string) $route);
            $this->assertStringNotContainsString('EnrichmentSpamrats::', $h, (string) $route);
        }
    }

    public function testWhitelistaZrodelZgodnaZLegacyUrlami(): void
    {
        $stale = new ReflectionClass(Enrichment::class);
        $zrodla = $stale->getReflectionConstant('ZRODLA')->getValue();

        $this->assertSame(self::LEGACY, array_keys($zrodla));
        // tryb błędu → 503 musi być taki jak w starych kontrolerach
        $this->assertSame('brak_klucza', $zrodla['abuseipdb'][1]);
        $this->assertNull($zrodla['dnsbl'][1]); // dnsbl: zawsze 400
        $this->assertSame('brak_klucza', $zrodla['greynoise'][1]);
        $this->assertSame('brak_klucza', $zrodla['ismalicious'][1]);
        $this->assertSame('brak_konfiguracji', $zrodla['lms'][1]);
        $this->assertSame('brak_klucza', $zrodla['spamrats'][1]);
    }

    public function testNieznaneZrodloZwraca400Json(): void
    {
        $resp = $this->wywolaj('hakier', ['ip' => '198.51.100.10']);

        $this->assertSame(400, $resp->getStatusCode());
        $body = $this->cialo($resp);
        $this->assertSame(false, $body['ok']);
        $this->assertSame('nieznane_zrodlo', $body['blad']);
        $this->assertSame('nieznane_zrodlo', $body['tryb']);
        $this->assertSame('198.51.100.10', $body['ip']);
    }

    public function testDnsblBladIpZwraca400NiezaleznieOdTrybu(): void
    {
        $resp = $this->wywolaj('dnsbl', ['ip' => 'zly-ip']);

        $this->assertSame(400, $resp->getStatusCode());
        $body = $this->cialo($resp);
        $this->assertSame(false, $body['ok']);
        $this->assertSame('Wymagany prawidłowy adres IP.', $body['blad']);
        $this->assertSame('zly-ip', $body['ip']);
        $this->assertArrayHasKey('tryb', $body);
    }

    public function testAbuseipdbBrakKluczaZwraca503(): void
    {
        if (trim((string) env('mcs.abuseipdb.apiKey', '')) !== '') {
            $this->markTestSkipped('Ustawiony mcs.abuseipdb.apiKey w środowisku testowym.');
        }

        $resp = $this->wywolaj('abuseipdb', ['ip' => 'zly-ip']);

        $this->assertSame(503, $resp->getStatusCode());
        $body = $this->cialo($resp);
        $this->assertSame(false, $body['ok']);
        $this->assertSame('brak_klucza', $body['tryb']);
        $this->assertSame('zly-ip', $body['ip']);
    }
}
