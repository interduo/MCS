<?php

use App\Libraries\Enrichment\DnsblClient;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Issue #456: twardy deadline całego checkIp (konfigurowalny, domyślnie 5 s) — wolny resolver
 * nie może zawiesić requestu POST /ip/{ip}/dnsbl; strefy po terminie dostają błąd (nigdy „czysty”).
 *
 * @internal
 */
final class DnsblClientDeadlineTest extends CIUnitTestCase
{
    public function testDeadlineOgraniczaCzasIOznaczaPozostaleStrefyBledem(): void
    {
        $strefy = [
            'a' => 'a.dnsbl.example.net',
            'b' => 'b.dnsbl.example.net',
            'c' => 'c.dnsbl.example.net',
        ];
        // resolver „wisi” 0.2 s na strefę
        $resolver = static function (string $fqdn): array {
            usleep(200_000);

            return [];
        };
        $client = new DnsblClient($strefy, $resolver, 25, false, 0.3);

        $start = microtime(true);
        $wynik = $client->checkIp('198.51.100.10');
        $elapsed = microtime(true) - $start;

        $this->assertTrue($wynik['ok'], 'częściowy sukces (2 z 3 stref) nadal ok=true');
        $this->assertLessThan(1.5, $elapsed, 'checkIp musi respektować deadline zamiast czekać na wszystkie strefy');
        $this->assertSame('', $wynik['data']['listy']['a']['error']);
        $this->assertSame('', $wynik['data']['listy']['b']['error']);
        $this->assertStringContainsString('limit czasu', $wynik['data']['listy']['c']['error']);
        $this->assertFalse($wynik['data']['listy']['c']['listed'], 'timeout nigdy nie oznacza „listed” ani „czysty” bez błędu');
    }

    public function testSzybkiResolverSprawdzaWszystkieStrefyBezBledu(): void
    {
        $strefy   = ['a' => 'a.dnsbl.example.net', 'b' => 'b.dnsbl.example.net'];
        $wywolane = [];
        $client   = new DnsblClient($strefy, static function (string $fqdn) use (&$wywolane): array {
            $wywolane[] = $fqdn;

            return [];
        }, 25, false, 5.0);

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertTrue($wynik['ok']);
        $this->assertCount(2, $wywolane);
        $this->assertSame('', $wynik['data']['listy']['a']['error']);
        $this->assertSame('', $wynik['data']['listy']['b']['error']);
    }

    public function testDeadlineParametrPerWywolanieNadpisujeDomyslny(): void
    {
        $strefy   = ['a' => 'a.dnsbl.example.net', 'b' => 'b.dnsbl.example.net'];
        $resolver = static function (): array {
            usleep(250_000);

            return [];
        };
        // domyślny timeout 5 s, ale per-wywołanie 0.2 s: druga strefa startuje już po deadline
        $client = new DnsblClient($strefy, $resolver, 25, false, 5.0);

        $wynik = $client->checkIp('198.51.100.10', 0.2);

        $this->assertTrue($wynik['ok']);
        $this->assertSame('', $wynik['data']['listy']['a']['error']);
        $this->assertStringContainsString('limit czasu', $wynik['data']['listy']['b']['error']);
    }

    public function testFromEnvCzytaCheckTimeoutS(): void
    {
        putenv('mcs.dnsbl.checkTimeoutS=7');
        $_ENV['mcs.dnsbl.checkTimeoutS'] = '7';
        try {
            $client = DnsblClient::fromEnv(static fn () => []);
            $ref    = new ReflectionProperty(DnsblClient::class, 'checkTimeoutS');
            $this->assertSame(7.0, $ref->getValue($client));
        } finally {
            putenv('mcs.dnsbl.checkTimeoutS');
            unset($_ENV['mcs.dnsbl.checkTimeoutS']);
        }
    }
}
