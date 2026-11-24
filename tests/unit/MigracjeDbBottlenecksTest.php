<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

// migracje są wykluczone z classmapy (exclude-from-classmap) — CI4 ładuje je po pliku
require_once APPPATH . 'Database/Migrations/2026-11-24-110001_IndeksMcsDnsblListingiStrefaAktywny.php';
require_once APPPATH . 'Database/Migrations/2026-11-24-110002_PgTrgmMcsImportySzukaj.php';

/**
 * Migracje paczki DB-bottlenecks (issue #446 / #450) na SQLite :memory: (testy):
 * indeks kompozytowy (strefa_kod, aktywny) na mcs_dnsbl_listingi; migracja pg_trgm dla
 * wyszukiwarki mcs_importy to no-op poza PostgreSQL (tam CREATE EXTENSION + GIN gin_trgm_ops).
 *
 * @internal
 */
final class MigracjeDbBottlenecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['mcs_dnsbl_listingi', 'mcs_importy'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        $this->db->query(
            "CREATE TABLE " . $this->db->prefixTable('mcs_dnsbl_listingi') . " (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip VARCHAR(45) NOT NULL,
                strefa_kod VARCHAR(32) NOT NULL,
                aktywny BOOLEAN DEFAULT 1,
                UNIQUE (ip, strefa_kod)
            )",
        );
        $this->db->query(
            "CREATE TABLE " . $this->db->prefixTable('mcs_importy') . " (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zrodlo VARCHAR(50) NOT NULL,
                id_wiadomosci TEXT NULL,
                checksum VARCHAR(255) NULL,
                blad_text TEXT NULL
            )",
        );
    }

    protected function tearDown(): void
    {
        foreach (['mcs_dnsbl_listingi', 'mcs_importy'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testIndeksStrefaAktywnyUpDown(): void
    {
        $m = new \App\Database\Migrations\IndeksMcsDnsblListingiStrefaAktywny();
        $m->up();

        $indeksy = array_column(
            $this->db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ?", [$this->db->prefixTable('mcs_dnsbl_listingi')])->getResultArray(),
            'name',
        );
        $this->assertContains('mcs_dnsbl_listingi_strefa_aktywny_idx', $indeksy);

        // kolumny indeksu: strefa_kod, aktywny (w tej kolejności)
        $kolumny = array_column(
            $this->db->query("PRAGMA index_info('mcs_dnsbl_listingi_strefa_aktywny_idx')")->getResultArray(),
            'name',
        );
        $this->assertSame(['strefa_kod', 'aktywny'], $kolumny);

        $m->up(); // IF NOT EXISTS — drugi raz bez błędu
        $m->down();
        $indeksy = array_column(
            $this->db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ?", [$this->db->prefixTable('mcs_dnsbl_listingi')])->getResultArray(),
            'name',
        );
        $this->assertNotContains('mcs_dnsbl_listingi_strefa_aktywny_idx', $indeksy);
    }

    public function testPgTrgmMigracjaNaSqliteJestNoOp(): void
    {
        $m = new \App\Database\Migrations\PgTrgmMcsImportySzukaj();
        $m->up(); // na SQLite (testy) migracja nie robi nic — pg_trgm jest tylko na Postgre
        $m->down();

        $indeksy = array_column(
            $this->db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ?", [$this->db->prefixTable('mcs_importy')])->getResultArray(),
            'name',
        );
        $this->assertSame([], $indeksy, 'GIN gin_trgm_ops tylko na Postgre; na SQLite brak indeksów trgm');

        // wyszukiwarka nadal działa (LIKE bez indeksu na małej tabeli testowej)
        $this->db->table('mcs_importy')->insert([
            'zrodlo' => 'play', 'id_wiadomosci' => '<abc@x>', 'checksum' => 'deadbeef', 'blad_text' => 'timeout przy pobieraniu',
        ]);
        $this->assertCount(1, model(\App\Models\ImportModel::class)->szukaj(null, null, 'timeout', null, null, 25));
        $this->assertCount(1, model(\App\Models\ImportModel::class)->szukaj(null, null, 'deadbeef', null, null, 25));
    }
}
