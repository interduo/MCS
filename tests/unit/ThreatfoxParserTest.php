<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\threatfox\Dopasowanie;
use McsPluginy\threatfox\Parser;

/**
 * Parser odpowiedzi ThreatFox API (bez DB i sieci).
 *
 * @internal
 */
final class ThreatfoxParserTest extends CIUnitTestCase
{
    public function testOkZwracaRekordyZPola(): void
    {
        $body = (string) json_encode([
            'query_status' => 'ok',
            'data'         => [
                [
                    'id'                => '123456',
                    'ioc'               => '91.231.81.5',
                    'ioc_type'          => 'ip',
                    'threat_type'       => 'botnet_c2',
                    'threat_type_desc'  => 'C2 server (Mozi)',
                    'malware'           => 'mozi',
                    'malware_printable' => 'Mozi',
                    'confidence_level'  => 95,
                    'first_seen'        => '2026-11-20 10:00:00',
                    'last_seen'         => '2026-11-24 08:00:00',
                    'reporter'          => 'someone',
                    'reference'         => 'https://example.com/report',
                    'tags'              => ['mozi', 'c2'],
                ],
            ],
        ]);

        $r = (new Parser())->parsuj($body);
        $this->assertCount(1, $r);
        $this->assertSame('123456', $r[0]['id']);
        $this->assertSame('91.231.81.5', $r[0]['ioc']);
        $this->assertSame('ip', $r[0]['ioc_type']);
        $this->assertSame('91.231.81.5', $r[0]['ip']);
        $this->assertNull($r[0]['port']);
        $this->assertSame('botnet_c2', $r[0]['threat_type']);
        $this->assertSame('C2 server (Mozi)', $r[0]['threat_type_desc']);
        $this->assertSame('Mozi', $r[0]['malware_printable']);
        $this->assertSame(95, $r[0]['confidence_level']);
        $this->assertSame(['mozi', 'c2'], $r[0]['tags']);
    }

    public function testNoResultsToSukcesZ0Rekordow(): void
    {
        $r = (new Parser())->parsuj('{"query_status":"no_results"}');
        $this->assertSame([], $r);
    }

    public function testIllegalToWyjatek(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/illegal/');
        (new Parser())->parsuj('{"query_status":"illegal"}');
    }

    public function testBrakQueryStatusToWyjatek(): void
    {
        $this->expectException(RuntimeException::class);
        (new Parser())->parsuj('{"data":[]}');
    }

    public function testOkZPustaDataToWyjatek(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pusta lista data/');
        (new Parser())->parsuj('{"query_status":"ok","data":[]}');
    }

    public function testOkBezPolaDataToWyjatek(): void
    {
        $this->expectException(RuntimeException::class);
        (new Parser())->parsuj('{"query_status":"ok"}');
    }

    public function testPustaTrescToWyjatek(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pusta odpowiedź/');
        (new Parser())->parsuj("  \n ");
    }

    public function testNieJsonToWyjatek(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/JSON/');
        (new Parser())->parsuj('<html>blocked</html>');
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: ?string, 3: ?int}>
     */
    public static function przypadkiIpPort(): iterable
    {
        yield 'ipv4 z portem'        => ['1.2.3.4:443', 'ip:port', '1.2.3.4', 443];
        yield 'ipv6 w nawiasach'     => ['[2001:db8::1]:8080', 'ip:port', '2001:db8::1', 8080];
        yield 'port spoza zakresu'   => ['1.2.3.4:70000', 'ip:port', '1.2.3.4', null];
        yield 'port niecyfrowy'      => ['1.2.3.4:abc', 'ip:port', '1.2.3.4', null];
        yield 'gole ipv4'            => ['1.2.3.4', 'ip', '1.2.3.4', null];
        yield 'gole ipv6'            => ['2001:db8::1', 'ip', '2001:db8::1', null];
        yield 'niepoprawny ip'       => ['999.1.1.1', 'ip', null, null];
        yield 'domena bez ip'        => ['example.com', 'domain', null, null];
        yield 'url bez ip'           => ['https://example.com/payload', 'url', null, null];
        yield 'hash md5 bez ip'      => ['d41d8cd98f00b204e9800998ecf8427e', 'md5', null, null];
        yield 'ipv6 bez nawiasow w ip:port' => ['2001:db8::1:443', 'ip:port', null, null];
    }

    /**
     * @dataProvider przypadkiIpPort
     */
    public function testWyciagnijIpPort(string $ioc, string $typ, ?string $ip, ?int $port): void
    {
        [$wIp, $wPort] = Parser::wyciagnijIpPort($ioc, $typ);
        $this->assertSame($ip, $wIp, 'ip dla ' . $ioc);
        $this->assertSame($port, $wPort, 'port dla ' . $ioc);
    }

    public function testFiltrowanieTypowIOC(): void
    {
        $body = (string) json_encode([
            'query_status' => 'ok',
            'data'         => [
                ['id' => '1', 'ioc' => '91.231.81.5', 'ioc_type' => 'ip'],
                ['id' => '2', 'ioc' => '91.231.80.9:4444', 'ioc_type' => 'ip:port'],
                ['id' => '3', 'ioc' => 'evil.example.com', 'ioc_type' => 'domain'],
                ['id' => '4', 'ioc' => 'https://evil.example.com/x', 'ioc_type' => 'url'],
                ['id' => '5', 'ioc' => 'd41d8cd98f00b204e9800998ecf8427e', 'ioc_type' => 'md5'],
                ['id' => '6', 'ioc' => 'a@b.example', 'ioc_type' => 'email'],
            ],
        ]);

        $r = (new Parser())->parsuj($body);
        $this->assertCount(6, $r);
        $zIp = array_values(array_filter($r, static fn (array $x): bool => is_string($x['ip']) && $x['ip'] !== ''));
        $this->assertCount(2, $zIp);
        $this->assertSame('91.231.81.5', $zIp[0]['ip']);
        $this->assertSame('91.231.80.9', $zIp[1]['ip']);
        $this->assertSame(4444, $zIp[1]['port']);
    }

    public function testConfidencePozaZakresemJestPrzycinane(): void
    {
        $body = (string) json_encode([
            'query_status' => 'ok',
            'data'         => [['id' => '1', 'ioc' => '1.2.3.4', 'ioc_type' => 'ip', 'confidence_level' => 250]],
        ]);
        $r = (new Parser())->parsuj($body);
        $this->assertSame(100, $r[0]['confidence_level']);
    }

    public function testDopasowanieIpDoNaszychCidrow(): void
    {
        $rekordy = (new Parser())->parsuj((string) json_encode([
            'query_status' => 'ok',
            'data'         => [
                ['id' => '1', 'ioc' => '91.231.81.5', 'ioc_type' => 'ip'],
                ['id' => '2', 'ioc' => '8.8.8.8', 'ioc_type' => 'ip'],
                ['id' => '3', 'ioc' => 'evil.example.com', 'ioc_type' => 'domain'],
                ['id' => '4', 'ioc' => '[2a0f:1700:10::5]:443', 'ioc_type' => 'ip:port'],
            ],
        ]));

        $t = Dopasowanie::dopasujIps($rekordy, ['91.231.80.0/22', '2a0f:1700::/29']);
        $this->assertCount(2, $t);
        $this->assertArrayHasKey('91.231.81.5|91.231.80.0/22', $t);
        $this->assertArrayHasKey('[2a0f:1700:10::5]:443|2a0f:1700::/29', $t);
        $this->assertSame('2a0f:1700:10::5', $t['[2a0f:1700:10::5]:443|2a0f:1700::/29']['ip']);
        $this->assertSame(443, $t['[2a0f:1700:10::5]:443|2a0f:1700::/29']['port']);
    }

    public function testIpWCidrRozneRodziny(): void
    {
        $this->assertTrue(Dopasowanie::ipWCidr('91.231.80.9', '91.231.80.0/22'));
        $this->assertFalse(Dopasowanie::ipWCidr('91.231.84.1', '91.231.80.0/22'));
        $this->assertFalse(Dopasowanie::ipWCidr('2a0f:1700::1', '91.231.80.0/22'));
        $this->assertTrue(Dopasowanie::ipWCidr('2a0f:1700:ffff::1', '2a0f:1700::/29'));
        $this->assertFalse(Dopasowanie::ipWCidr('2a0f:1708::1', '2a0f:1700::/29'));
        $this->assertFalse(Dopasowanie::ipWCidr('bzdura', '91.231.80.0/22'));
        $this->assertFalse(Dopasowanie::ipWCidr('91.231.80.9', 'bzdura'));
    }
}
