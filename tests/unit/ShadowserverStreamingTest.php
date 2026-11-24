<?php

use App\Libraries\OpcjeMcs;
use App\Libraries\PrzekroczonyLimitImportu;
use App\Models\ImportModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use McsPluginy\shadowserver\Plugin;

/**
 * Import shadowserver strumieniowo z limitami (issue #453), na SQLite :memory:
 * duży fixture = paczki 500 bez wzrostu pamięci; przekroczenie limitu rozmiaru
 * albo wierszy = status `blad` z komunikatem i bez częściowego importu.
 *
 * @internal
 */
final class ShadowserverStreamingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    /** @var list<string> */
    private array $envUstawione = [];

    protected function setUp(): void
    {
        parent::setUp();
        OpcjeMcs::resetuj();

        $p = fn (string $t): string => $this->db->prefixTable($t);
        foreach (['mcs_importy', 'mcs_shadowserver_wiersze', 'mcs_incydenty', 'mcs_obserwacje'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $p($t));
        }
        $this->db->query("CREATE TABLE {$p('mcs_importy')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo VARCHAR(50) NOT NULL,
            id_wiadomosci TEXT NULL, rozpoczeto_at TEXT NOT NULL, zakonczono_at TEXT NULL, status VARCHAR(20) NOT NULL,
            checksum VARCHAR(255) NULL, meta_json TEXT NULL, blad_text TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_shadowserver_wiersze')} (id INTEGER PRIMARY KEY AUTOINCREMENT,
            import_id INTEGER NULL, zaobserwowano_at TEXT NOT NULL, ip VARCHAR(45) NOT NULL, port INTEGER NULL,
            protokol VARCHAR(20) NULL, tag VARCHAR(100) NULL, severity VARCHAR(40) NULL, klucz_dedupu VARCHAR(255) NOT NULL,
            payload_json TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_incydenty')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo VARCHAR(50) NOT NULL,
            klucz_dedupu VARCHAR(255) NOT NULL, podmiot_typ VARCHAR(20) NOT NULL DEFAULT 'ip', podmiot VARCHAR(255) NOT NULL,
            pierwsze_widziane TEXT NOT NULL, ostatnio_widziane TEXT NOT NULL, waga VARCHAR(20) NOT NULL, wersja_wagi VARCHAR(20) NULL,
            kategoria VARCHAR(100) NOT NULL, tytul VARCHAR(255) NOT NULL, port INTEGER NULL, protokol VARCHAR(20) NULL,
            ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0, UNIQUE (zrodlo, klucz_dedupu))");
        $this->db->query("CREATE TABLE {$p('mcs_obserwacje')} (id INTEGER PRIMARY KEY AUTOINCREMENT, incydent_id INTEGER NOT NULL,
            import_id INTEGER NULL, zaobserwowano_at TEXT NOT NULL, payload_json TEXT NULL, id_w_zrodle TEXT NULL)");
    }

    protected function tearDown(): void
    {
        $p = fn (string $t): string => $this->db->prefixTable($t);
        foreach (['mcs_importy', 'mcs_shadowserver_wiersze', 'mcs_incydenty', 'mcs_obserwacje'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $p($t));
        }
        foreach ($this->envUstawione as $k) {
            unset($_ENV[$k], $_SERVER[$k]);
        }
        OpcjeMcs::resetuj();
        parent::tearDown();
    }

    private function ustawEnv(string $klucz, string $wartosc): void
    {
        $_ENV[$klucz]         = $wartosc;
        $_SERVER[$klucz]      = $wartosc;
        $this->envUstawione[] = $klucz;
        OpcjeMcs::resetuj();
    }

    private function napiszCsv(int $ileWierszy): string
    {
        $plik = tempnam(sys_get_temp_dir(), 'mcs_ss_big_');
        $this->assertNotFalse($plik);
        $fp = fopen($plik, 'wb');
        $this->assertNotFalse($fp);
        fwrite($fp, "timestamp,ip,protocol,port,tag,severity\n");
        for ($i = 0; $i < $ileWierszy; $i++) {
            fwrite($fp, sprintf(
                "2026-11-24 10:%02d:%02d,198.51.100.%d,tcp,%d,tag-%d,low\n",
                $i % 60,
                ($i * 7) % 60,
                ($i % 250) + 1,
                1000 + ($i % 60000),
                $i % 50,
            ));
        }
        fclose($fp);

        return $plik;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ostatniImport(): ?array
    {
        return model(ImportModel::class)->where('zrodlo', 'shadowserver')->orderBy('id', 'DESC')->first();
    }

    public function testDuzyFixtureImportujeSieStrumieniowo(): void
    {
        // 20 000 wierszy (~1 MB CSV) — więcej niż jedna paczka 500, czytane strumieniowo.
        $plik = $this->napiszCsv(20000);

        try {
            $plugin = new Plugin();
            $liczba = $plugin->importujPlik($plik, null);

            $this->assertSame(20000, $liczba);
            $this->assertSame(20000, $this->db->table('mcs_shadowserver_wiersze')->countAllResults());

            $import = $this->ostatniImport();
            $this->assertIsArray($import);
            $this->assertSame('ok', $import['status']);
            $this->assertStringContainsString('"wierszy":20000', (string) $import['meta_json']);
        } finally {
            @unlink($plik);
        }
    }

    public function testLimitRozmiaruPlikuOznaczaImportJakoBladBezCzesciowegoImportu(): void
    {
        $this->ustawEnv('mcs.import.maxPlikMb', '1');
        $plik = $this->napiszCsv(30000); // ~1.4 MB > 1 MB

        try {
            $plugin = new Plugin();
            try {
                $plugin->importujPlik($plik, null);
                $this->fail('oczekiwano PrzekroczonyLimitImportu');
            } catch (PrzekroczonyLimitImportu $e) {
                $this->assertStringContainsString('Shadowserver', $e->getMessage());
                $this->assertStringContainsString('MB', $e->getMessage());
            }

            $import = $this->ostatniImport();
            $this->assertIsArray($import);
            $this->assertSame('blad', $import['status']);
            $this->assertStringContainsString('Shadowserver', (string) $import['blad_text']);
            $this->assertStringContainsString('limit', (string) $import['blad_text']);

            // NIE importujemy częściowo bez oznaczenia — limit rozmiaru łapiemy przed czytaniem.
            $this->assertSame(0, $this->db->table('mcs_shadowserver_wiersze')->countAllResults());
        } finally {
            @unlink($plik);
        }
    }

    public function testLimitWierszyOznaczaImportJakoBlad(): void
    {
        $this->ustawEnv('mcs.import.maxWierszy', '100');
        $plik = $this->napiszCsv(500);

        try {
            $plugin = new Plugin();
            try {
                $plugin->importujPlik($plik, null);
                $this->fail('oczekiwano PrzekroczonyLimitImportu');
            } catch (PrzekroczonyLimitImportu $e) {
                $this->assertStringContainsString('limit 100 wierszy', $e->getMessage());
            }

            $import = $this->ostatniImport();
            $this->assertIsArray($import);
            $this->assertSame('blad', $import['status']);
            $this->assertStringContainsString('100', (string) $import['blad_text']);

            // Paczki flushują się co 500 — przy limicie 100 nie było jeszcze żadnego zapisu.
            $this->assertSame(0, $this->db->table('mcs_shadowserver_wiersze')->countAllResults());
        } finally {
            @unlink($plik);
        }
    }

    public function testMalyFixtureImportujeSieOk(): void
    {
        $plik = $this->napiszCsv(10);

        try {
            $plugin = new Plugin();
            $this->assertSame(10, $plugin->importujPlik($plik, null));

            $import = $this->ostatniImport();
            $this->assertSame('ok', $import['status']);
        } finally {
            @unlink($plik);
        }
    }
}
