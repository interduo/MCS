<?php

use App\Libraries\Siec\RevDns;
use App\Models\UstawieniaModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RevDnsTest extends CIUnitTestCase
{
    public function testDomyslnieWylaczone(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturn(null);

        $rev = new RevDns($model, static fn (): ?string => 'ptr.example.test', $this->pamiecCache());
        $this->assertFalse($rev->jestWlaczone());
        $this->assertNull($rev->dlaIp('198.51.100.10'));
    }

    public function testWlaczoneZwracaHostnameICacheuje(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturnCallback(static function (string $klucz): ?string {
            return $klucz === RevDns::KLUCZ_WLACZONE ? '1' : null;
        });

        $wywolania = 0;
        $cache     = $this->pamiecCache();
        $rev       = new RevDns($model, static function () use (&$wywolania): ?string {
            $wywolania++;

            return 'host.example.test';
        }, $cache);

        $this->assertTrue($rev->jestWlaczone());
        $this->assertSame('host.example.test', $rev->dlaIp('198.51.100.10'));
        $this->assertSame('host.example.test', $rev->dlaIp('198.51.100.10'));
        $this->assertSame(1, $wywolania);
    }

    public function testTylkoCacheNieWykonujeLookupu(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturn('1');

        $wywolania = 0;
        $rev       = new RevDns($model, static function () use (&$wywolania): ?string {
            $wywolania++;

            return 'host.example.test';
        }, $this->pamiecCache());

        $this->assertNull($rev->dlaIp('198.51.100.10', true));
        $this->assertSame(0, $wywolania);

        $this->assertSame('host.example.test', $rev->dlaIp('198.51.100.10'));
        $this->assertSame('host.example.test', $rev->dlaIp('198.51.100.10', true));
        $this->assertSame(1, $wywolania);
    }

    public function testNiepoprawneIpZwracaNull(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturn('1');

        $rev = new RevDns($model, static fn (): ?string => 'x', $this->pamiecCache());
        $this->assertNull($rev->dlaIp('nie-ip'));
    }

    public function testResolverRzucaWyjatekZwracaNull(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturn('1');

        $rev = new RevDns($model, static function (): ?string {
            throw new \RuntimeException('resolver padł');
        }, $this->pamiecCache());

        $this->assertNull($rev->dlaIp('198.51.100.10'));
    }

    /**
     * Issue #447: domyślny resolver (bez wstrzykniętego callable) to PtrResolver z twardym
     * timeoutem — dla IP bez PTR zwraca null i nie może zawiesić requestu (/ip/{ip}).
     */
    public function testDomyslnyResolverMaTwardyTimeoutIIFallbackNull(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturn('1');

        $stare = [$_SERVER['mcs.revDns.resolver'] ?? null, $_SERVER['mcs.revDns.timeoutMs'] ?? null];
        $_SERVER['mcs.revDns.resolver']   = '127.0.0.1';
        $_SERVER['mcs.revDns.timeoutMs']  = '120';
        \App\Libraries\OpcjeMcs::ustawModel(null);

        try {
            $rev   = new RevDns($model, null, $this->pamiecCache());
            $start = microtime(true);
            $wynik = $rev->dlaIp('203.0.113.7'); // TEST-NET-3 — bez PTR
            $czas  = microtime(true) - $start;

            $this->assertNull($wynik);
            $this->assertLessThan(2.0, $czas, 'lookup PTR przy zimnym cache musi być ograniczony timeoutem');
        } finally {
            if ($stare[0] === null) {
                unset($_SERVER['mcs.revDns.resolver']);
            } else {
                $_SERVER['mcs.revDns.resolver'] = $stare[0];
            }
            if ($stare[1] === null) {
                unset($_SERVER['mcs.revDns.timeoutMs']);
            } else {
                $_SERVER['mcs.revDns.timeoutMs'] = $stare[1];
            }
            \App\Libraries\OpcjeMcs::ustawModel(null);
        }
    }

    public function testKluczUstawienia(): void
    {
        $this->assertSame('pokazuj_rev_dns', RevDns::KLUCZ_WLACZONE);
    }

    /**
     * @return array{get:callable,set:callable}
     */
    private function pamiecCache(): array
    {
        $store = [];

        return [
            'get' => static function (string $klucz) use (&$store) {
                return $store[$klucz] ?? null;
            },
            'set' => static function (string $klucz, mixed $wartosc, int $ttl) use (&$store): bool {
                unset($ttl);
                $store[$klucz] = $wartosc;

                return true;
            },
        ];
    }
}
