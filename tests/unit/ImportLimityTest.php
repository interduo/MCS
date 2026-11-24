<?php

use App\Libraries\BladUi;
use App\Libraries\ImportLimity;
use App\Libraries\OpcjeMcs;
use App\Libraries\PrzekroczonyLimitImportu;
use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\shadowserver\Parser as ShadowserverParser;
use McsPluginy\snds\Parser as SndsParser;

/**
 * Limity importów (issue #453): rozmiar pliku/załącznika, liczba wierszy,
 * strumieniowe parsowanie CSV bez wzrostu pamięci.
 *
 * @internal
 */
final class ImportLimityTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $envUstawione = [];

    protected function setUp(): void
    {
        parent::setUp();
        OpcjeMcs::resetuj();
    }

    protected function tearDown(): void
    {
        foreach ($this->envUstawione as $k) {
            unset($_ENV[$k], $_SERVER[$k]);
        }
        OpcjeMcs::resetuj();
        parent::tearDown();
    }

    private function ustawEnv(string $klucz, string $wartosc): void
    {
        $_ENV[$klucz]      = $wartosc;
        $_SERVER[$klucz]   = $wartosc;
        $this->envUstawione[] = $klucz;
        OpcjeMcs::resetuj();
    }

    public function testDomyslneLimity(): void
    {
        $this->assertSame(100 * 1024 * 1024, ImportLimity::maxPlikB());
        $this->assertSame(500000, ImportLimity::maxWierszy());
    }

    public function testLimityKonfigurowalnePrzezOpcjeMcs(): void
    {
        $this->assertTrue(OpcjeMcs::istnieje('mcs.import.maxPlikMb'));
        $this->assertTrue(OpcjeMcs::istnieje('mcs.import.maxWierszy'));

        $this->ustawEnv('mcs.import.maxPlikMb', '7');
        $this->ustawEnv('mcs.import.maxWierszy', '123');

        $this->assertSame(7 * 1024 * 1024, ImportLimity::maxPlikB());
        $this->assertSame(123, ImportLimity::maxWierszy());
        $this->assertSame(OpcjeMcs::ZRODLO_ENV, OpcjeMcs::odczyt('mcs.import.maxPlikMb')['zrodlo']);
    }

    public function testSprawdzPlikRzucaPrzyPrzekroczeniuRozmiaru(): void
    {
        $this->ustawEnv('mcs.import.maxPlikMb', '1');

        $plik = tempnam(sys_get_temp_dir(), 'mcs_limit_');
        $this->assertNotFalse($plik);
        file_put_contents($plik, str_repeat('a', 2 * 1024 * 1024));

        try {
            $this->expectException(PrzekroczonyLimitImportu::class);
            $this->expectExceptionMessage('2 MB');
            ImportLimity::sprawdzPlik($plik, 'Test');
        } finally {
            @unlink($plik);
        }
    }

    public function testSprawdzPlikPrzechodziGdyWLimicie(): void
    {
        $this->ustawEnv('mcs.import.maxPlikMb', '1');

        $plik = tempnam(sys_get_temp_dir(), 'mcs_limit_');
        $this->assertNotFalse($plik);
        file_put_contents($plik, 'mały plik');

        try {
            ImportLimity::sprawdzPlik($plik, 'Test');
            $this->assertTrue(true); // bez wyjątku
        } finally {
            @unlink($plik);
        }
    }

    public function testSprawdzTrescRzucaDlaZalacznikaPonadLimit(): void
    {
        $this->ustawEnv('mcs.import.maxPlikMb', '1');

        $this->expectException(PrzekroczonyLimitImportu::class);
        $this->expectExceptionMessage('raport.csv');
        ImportLimity::sprawdzTresc(str_repeat('a', 2 * 1024 * 1024), 'Shadowserver', 'raport.csv');
    }

    public function testSprawdzLiczbeWierszyRzucaPoPrzekroczeniu(): void
    {
        $this->ustawEnv('mcs.import.maxWierszy', '10');

        ImportLimity::sprawdzLiczbeWierszy('Test', 10); // w limicie — OK
        $this->assertTrue(true);

        $this->expectException(PrzekroczonyLimitImportu::class);
        $this->expectExceptionMessage('limit 10 wierszy');
        ImportLimity::sprawdzLiczbeWierszy('Test', 11);
    }

    public function testKomunikatLimituJestZaufanyDlaBladUi(): void
    {
        $e = new PrzekroczonyLimitImportu(ImportLimity::komunikatRozmiar('Shadowserver', 'raport.csv', 200 * 1024 * 1024, 100 * 1024 * 1024));

        $this->assertTrue(BladUi::zaufany($e));
        $this->assertSame($e->getMessage(), BladUi::komunikat($e));
        $this->assertStringContainsString('200 MB', $e->getMessage());
        $this->assertStringContainsString('100 MB', $e->getMessage());
    }

    public function testShadowserverParserStrumieniowyNieGromadziWierszy(): void
    {
        // DoD #453: duży fixture strumieniowo, bez wzrostu pamięci.
        $plik = tempnam(sys_get_temp_dir(), 'mcs_ss_stream_');
        $this->assertNotFalse($plik);
        $fp = fopen($plik, 'wb');
        $this->assertNotFalse($fp);
        fwrite($fp, "timestamp,ip,protocol,port,tag\n");
        for ($i = 0; $i < 50000; $i++) {
            fwrite($fp, sprintf("2026-11-24 10:00:%02d,198.51.100.%d,tcp,%d,tag-%d\n", $i % 60, ($i % 250) + 1, 1000 + $i, $i % 40));
        }
        fclose($fp);

        try {
            $fp = fopen($plik, 'rb');
            $this->assertNotFalse($fp);
            $przed = memory_get_usage();
            $n     = 0;
            foreach ((new ShadowserverParser())->parsujCsvStrumien($fp) as $w) {
                $n++;
                $this->assertSame('198.51.100.' . (($n - 1) % 250 + 1), $w['ip']);
            }
            fclose($fp);
            $wzrost = memory_get_usage() - $przed;

            $this->assertSame(50000, $n);
            $this->assertLessThan(1024 * 1024, $wzrost, 'generator nie może akumulować wierszy (wzrost ' . $wzrost . ' B)');
        } finally {
            @unlink($plik);
        }
    }

    public function testShadowserverParserStrumienZgodnyZParsujCsv(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/shadowserver_sample.csv';
        $tresc   = (string) file_get_contents($sciezka);

        $fp = fopen($sciezka, 'rb');
        $this->assertNotFalse($fp);
        $strumien = iterator_to_array((new ShadowserverParser())->parsujCsvStrumien($fp), false);
        fclose($fp);

        $this->assertSame((new ShadowserverParser())->parsujCsv($tresc), $strumien);
        $this->assertCount(3, $strumien);
    }

    public function testShadowserverParserStrumienRzucaPrzyLimicieWierszy(): void
    {
        $this->ustawEnv('mcs.import.maxWierszy', '2');

        $fp = fopen(ROOTPATH . 'tests/_support/fixtures/shadowserver_sample.csv', 'rb');
        $this->assertNotFalse($fp);

        try {
            $this->expectException(PrzekroczonyLimitImportu::class);
            $this->expectExceptionMessage('limit 2 wierszy');
            iterator_to_array((new ShadowserverParser())->parsujCsvStrumien($fp), false);
        } finally {
            fclose($fp);
        }
    }

    public function testSndsParserStrumienZgodnyZParsujCsvObaTryby(): void
    {
        $parser = new SndsParser();

        foreach (['snds_datakey_sample.csv', 'snds_ip_status_sample.csv'] as $nazwa) {
            $sciezka = ROOTPATH . 'tests/_support/fixtures/' . $nazwa;
            $tresc   = (string) file_get_contents($sciezka);

            $fp = fopen($sciezka, 'rb');
            $this->assertNotFalse($fp);
            $strumien = iterator_to_array($parser->parsujCsvStrumien($fp), false);
            fclose($fp);

            $this->assertSame($parser->parsujCsv($tresc), $strumien, $nazwa);
            $this->assertNotCount(0, $strumien, $nazwa);
        }
    }

    public function testSndsParserStrumienRzucaPrzyLimicieWierszy(): void
    {
        $this->ustawEnv('mcs.import.maxWierszy', '1');

        $fp = fopen(ROOTPATH . 'tests/_support/fixtures/snds_datakey_sample.csv', 'rb');
        $this->assertNotFalse($fp);

        try {
            $this->expectException(PrzekroczonyLimitImportu::class);
            iterator_to_array((new SndsParser())->parsujCsvStrumien($fp), false);
        } finally {
            fclose($fp);
        }
    }
}
