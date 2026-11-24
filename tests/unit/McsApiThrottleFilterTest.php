<?php

use App\Filters\McsApiThrottleFilter;
use App\Filters\McsBadgeTokenFilter;
use App\Libraries\OpcjeMcs;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;
use Config\App;

/**
 * Filtr rate limit dla tokenowych endpointów API (issue #451): 429 + Retry-After.
 *
 * @internal
 */
final class McsApiThrottleFilterTest extends CIUnitTestCase
{
    private string $token = 'test-badge-token-nie-prod-0123456789';

    private Throttler $throttler;

    /** @var array<string, mixed> */
    private array $envKopia = [];

    protected function setUp(): void
    {
        parent::setUp();
        OpcjeMcs::ustawModel(null);
        foreach (['mcs.api.rateLimit'] as $k) {
            $this->envKopia[$k] = $_SERVER[$k] ?? null;
            unset($_SERVER[$k], $_ENV[$k]);
        }
        $_SERVER['mcs.api.rateLimit'] = '3';
        $this->throttler              = new Throttler(new MockCache());
        $this->throttler->setTestTime(1_800_000_000);
    }

    protected function tearDown(): void
    {
        foreach ($this->envKopia as $k => $v) {
            if ($v === null) {
                unset($_SERVER[$k], $_ENV[$k]);
            } else {
                $_SERVER[$k] = $v;
            }
        }
        $this->envKopia = [];
        OpcjeMcs::ustawModel(null);
        parent::tearDown();
    }

    public function testLimitTokenuZwraca429ZRetryAfter(): void
    {
        $filtr = new McsApiThrottleFilter($this->throttler);

        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($filtr->before($this->zrobRequest($this->token)), (string) $i);
        }

        $odmowa = $filtr->before($this->zrobRequest($this->token));
        $this->assertInstanceOf(Response::class, $odmowa);
        $this->assertSame(429, $odmowa->getStatusCode());
        $this->assertSame('20', $odmowa->getHeaderLine('Retry-After')); // ceil(60/3)
        $json = json_decode((string) $odmowa->getBody(), true);
        $this->assertFalse($json['ok']);
        $this->assertSame('limit_api', $json['tryb']);
    }

    public function testLimitPerIpBlokujeInnyTokenZTegoSamegoIp(): void
    {
        $filtr = new McsApiThrottleFilter($this->throttler);

        $this->assertNull($filtr->before($this->zrobRequest($this->token)));
        $this->assertNull($filtr->before($this->zrobRequest('inny-token-aaaa-bbbb-cccc-dddd')));
        $this->assertNull($filtr->before($this->zrobRequest('jeszcze-inny-token-0000-1111')));

        // kubełek IP (203.0.113.9) wyczerpany — nawet nowy token dostaje 429
        $odmowa = $filtr->before($this->zrobRequest('kolejny-token-2222-3333-4444'));
        $this->assertInstanceOf(Response::class, $odmowa);
        $this->assertSame(429, $odmowa->getStatusCode());
    }

    public function testInnyIpNieJestBlokowanyPrzezKubelekTokenu(): void
    {
        $filtr = new McsApiThrottleFilter($this->throttler);

        // wyczerpujemy kubełek tokenu z jednego IP
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($filtr->before($this->zrobRequest($this->token)));
        }

        // ten sam token z innego IP → 429 (kubełek tokenu), inny token z innego IP → null
        $odmowa = $filtr->before($this->zrobRequest($this->token, '198.51.100.77'));
        $this->assertInstanceOf(Response::class, $odmowa);
        $this->assertSame(429, $odmowa->getStatusCode());

        $this->assertNull($filtr->before($this->zrobRequest('nowy-token-5555-6666-7777', '198.51.100.77')));
    }

    public function testTokenWyciaganyJakWFiltrzeBadge(): void
    {
        $req = $this->zrobRequest(null);
        $req->setHeader('X-Mcs-Badge-Token', $this->token);
        $this->assertSame($this->token, McsBadgeTokenFilter::wyciagnijToken($req));

        $req2 = $this->zrobRequest(null);
        $req2->setHeader('Authorization', 'Bearer ' . $this->token);
        $this->assertSame($this->token, McsBadgeTokenFilter::wyciagnijToken($req2));

        $this->assertNull(McsBadgeTokenFilter::wyciagnijToken($this->zrobRequest(null)));
    }

    public function testFiltrPodpietyPoTokenieNaBadgeIEnrichment(): void
    {
        $filters = new \Config\Filters();

        $this->assertSame(McsApiThrottleFilter::class, $filters->aliases['mcsApiThrottle']);
        $przed = $filters->filters['mcsApiThrottle']['before'] ?? [];
        $this->assertContains('api/badge', $przed);
        $this->assertContains('api/enrichment/*', $przed);

        // kolejność: najpierw mcsBadgeToken (401 bez tokenu), potem throttling
        $klucze = array_keys($filters->filters);
        $this->assertLessThan(
            array_search('mcsApiThrottle', $klucze, true),
            array_search('mcsBadgeToken', $klucze, true),
        );
    }

    private function zrobRequest(?string $token, string $ip = '203.0.113.9'): IncomingRequest
    {
        $config          = config(App::class);
        $config->baseURL = 'http://example.com/';

        $uri     = new SiteURI($config, 'api/badge');
        $request = new IncomingRequest($config, $uri, 'php://input', new UserAgent());
        if ($token !== null) {
            $request->setHeader('Authorization', 'Bearer ' . $token);
        }
        // getIPAddress() czyta przez shared superglobals (snapshot $_SERVER) — ustawiamy wprost.
        $rp = new \ReflectionProperty($request, 'ipAddress');
        $rp->setValue($request, $ip);

        return $request;
    }
}
