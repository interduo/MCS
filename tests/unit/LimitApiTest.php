<?php

use App\Libraries\LimitApi;
use App\Libraries\OpcjeMcs;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;

/**
 * @internal
 */
final class LimitApiTest extends CIUnitTestCase
{
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

    public function testLimitPerToken(): void
    {
        $limit = new LimitApi($this->throttler);

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($limit->pozwol('token-a', '203.0.113.1'), (string) $i);
        }
        // ten sam token, inny IP — kubełek tokenu już pusty
        $this->assertFalse($limit->pozwol('token-a', '203.0.113.2'));
        // inny token, ten sam IP — kubełek IP też już pusty (3 żądania z tego IP)
        $this->assertFalse($limit->pozwol('token-b', '203.0.113.1'));
        // inny token i inny IP — oba kubełki świeże
        $this->assertTrue($limit->pozwol('token-b', '203.0.113.2'));
    }

    public function testLimitPerIpDzialaNiezaleznieOdTokenu(): void
    {
        $limit = new LimitApi($this->throttler);

        $this->assertTrue($limit->pozwol('token-a', '198.51.100.5'));
        $this->assertTrue($limit->pozwol('token-b', '198.51.100.5'));
        $this->assertTrue($limit->pozwol('token-c', '198.51.100.5'));
        $this->assertFalse($limit->pozwol('token-d', '198.51.100.5'));
        // inny IP nadal przechodzi (osobny kubełek IP, świeży token)
        $this->assertTrue($limit->pozwol('token-d', '198.51.100.6'));
    }

    public function testPustyIpNieBlokujeKluczaIp(): void
    {
        $limit = new LimitApi($this->throttler);

        $this->assertTrue($limit->pozwol('token-a', ''));
        $this->assertTrue($limit->pozwol('token-a', ''));
    }

    public function testKluczBezZnakowZastrzezonych(): void
    {
        $k = LimitApi::klucz('t', 'abc/def:ghi');

        $this->assertStringNotContainsString('/', $k);
        $this->assertStringNotContainsString(':', $k);
        $this->assertSame('mcsapi_t_' . md5('abc/def:ghi'), $k);
    }

    public function testRetryAfterToCzasJednegoTokenu(): void
    {
        $this->assertSame(1, LimitApi::retryAfter(60));
        $this->assertSame(20, LimitApi::retryAfter(3));
        $this->assertSame(60, LimitApi::retryAfter(1));
    }
}
