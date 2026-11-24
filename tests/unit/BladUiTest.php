<?php

use App\Libraries\BladUi;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Issue #433: surowe getMessage() nie może trafić do UI/JSON — tylko do logu z ID zdarzenia;
 * whitelist (zaufane prefiksy/klasy) przechodzi, reszta dostaje generyczny komunikat + ID.
 *
 * @internal
 */
final class BladUiTest extends CIUnitTestCase
{
    public function testNieZaufanyWyjatekDajeGenerycznyKomunikatBezSzczegolow(): void
    {
        $e = new RuntimeException('SQLSTATE[08006]: connection to db.internal:5432 failed /var/www/secret');

        $msg = BladUi::komunikat($e, 'test 433');

        $this->assertMatchesRegularExpression('/^Wystąpił błąd wewnętrzny \(zdarzenie [0-9a-f]{8}\) — szczegóły w logu serwera\.$/', $msg);
        $this->assertStringNotContainsString('SQLSTATE', $msg);
        $this->assertStringNotContainsString('db.internal', $msg);
        $this->assertStringNotContainsString('/var/www', $msg);
    }

    public function testNieZaufanyWyjatekMaPelnyStackWLoguZIdZdarzenia(): void
    {
        $e = new RuntimeException('tajny-SQLSTATE-12345 db.internal');

        $msg = BladUi::komunikat($e, 'test 433 log');
        preg_match('/zdarzenie ([0-9a-f]{8})/', $msg, $m);
        $this->assertNotEmpty($m[1], 'komunikat powinien zawierać ID zdarzenia');
        $id = $m[1];

        $pliki = glob(WRITEPATH . 'logs/log-*.log') ?: glob(WRITEPATH . 'logs/log-*.php') ?: [];
        $this->assertNotEmpty($pliki, 'brak pliku logu — log_message nie zapisał błędu');
        sort($pliki);
        $log = (string) file_get_contents((string) end($pliki));

        $this->assertStringContainsString('zdarzenie ' . $id, $log);
        $this->assertStringContainsString('tajny-SQLSTATE-12345 db.internal', $log);
        $this->assertStringContainsString('BladUiTest.php', $log); // pełny stack, nie tylko message
    }

    public function testZaufanyPrefiksPrzechodziBezZmian(): void
    {
        $this->assertSame('ThreatFox: HTTP 503', BladUi::komunikat(new RuntimeException('ThreatFox: HTTP 503'), 'x'));
        $this->assertSame('Spamhaus drop_v4: cURL: timeout', BladUi::komunikat(new RuntimeException('Spamhaus drop_v4: cURL: timeout'), 'x'));
        $this->assertTrue(BladUi::zaufany(new RuntimeException('ThreatFox: query_status=illegal')));
    }

    public function testZaufanaKlasaBladPobraniaPrzechodziBezZmian(): void
    {
        $e = new \McsPluginy\spamhaus\BladPobrania('Spamhaus drop_v6: HTTP 429', 7200);

        $this->assertTrue(BladUi::zaufany($e));
        $this->assertSame('Spamhaus drop_v6: HTTP 429', BladUi::komunikat($e, 'x'));
    }

    public function testIdZdarzeniaMa8ZnakowHexIJestLosowe(): void
    {
        $a = BladUi::idZdarzenia();
        $b = BladUi::idZdarzenia();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $a);
        $this->assertNotSame($a, $b);
    }
}
