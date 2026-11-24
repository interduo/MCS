<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

// migracje są wykluczone z classmapy (exclude-from-classmap) — CI4 ładuje je po pliku
require_once APPPATH . 'Database/Migrations/2026-11-24-120001_IndeksMcsThreatfoxPanelSorty.php';

/**
 * Migracja indeksów pod sorty panelu threatfox (issue #449) na SQLite :memory: (testy):
 * indeksy kompozytowe (aktywne DESC, ostatnie_at DESC) na mcs_threatfox_trafienia oraz
 * (aktywne DESC, ostatni_import_at DESC) na mcs_threatfox_ioc — dokładnie pod ORDER BY
 * z plugins/threatfox/Widok.php::dane (tam: ...->orderBy('aktywne','DESC')->orderBy(...,'DESC')->limit(...)).
 *
 * @internal
 */
final class MigracjeThreatfoxIndeksyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private const TABELE = ['mcs_threatfox_trafienia', 'mcs_threatfox_ioc'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        $p = fn (string $t): string => $this->db->prefixTable($t);
        $this->db->query(
            "CREATE TABLE {$p('mcs_threatfox_trafienia')} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ioc TEXT NOT NULL,
                aktywne BOOLEAN DEFAULT 1,
                ostatnie_at TEXT NULL
            )",
        );
        $this->db->query(
            "CREATE TABLE {$p('mcs_threatfox_ioc')} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ioc TEXT NOT NULL,
                aktywne BOOLEAN DEFAULT 1,
                ostatni_import_at TEXT NULL
            )",
        );
    }

    protected function tearDown(): void
    {
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    /** @return array<string, list<string>> nazwa indeksu => kolumny (w kolejności) */
    private function indeksyTabeli(string $tabela): array
    {
        $wiersze = $this->db->query(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name NOT LIKE 'sqlite_%'",
            [$this->db->prefixTable($tabela)],
        )->getResultArray();
        $wynik = [];
        foreach ($wiersze as $w) {
            $wynik[$w['name']] = array_column(
                $this->db->query("PRAGMA index_info(" . $this->db->escape($w['name']) . ")")->getResultArray(),
                'name',
            );
        }
        return $wynik;
    }

    public function testIndeksyPaneluThreatfoxUpDown(): void
    {
        $m = new \App\Database\Migrations\IndeksMcsThreatfoxPanelSorty();
        $m->up();

        $indeksyTraf = $this->indeksyTabeli('mcs_threatfox_trafienia');
        $this->assertArrayHasKey('mcs_threatfox_trafienia_aktywne_ostatnie_at_idx', $indeksyTraf);
        $this->assertSame(['aktywne', 'ostatnie_at'], $indeksyTraf['mcs_threatfox_trafienia_aktywne_ostatnie_at_idx']);

        $indeksyIoc = $this->indeksyTabeli('mcs_threatfox_ioc');
        $this->assertArrayHasKey('mcs_threatfox_ioc_aktywne_ostatni_import_at_idx', $indeksyIoc);
        $this->assertSame(['aktywne', 'ostatni_import_at'], $indeksyIoc['mcs_threatfox_ioc_aktywne_ostatni_import_at_idx']);

        // obie kolumny indeksów DESC (jak w ORDER BY ... DESC w Widok.php)
        foreach ([
            'mcs_threatfox_trafienia_aktywne_ostatnie_at_idx',
            'mcs_threatfox_ioc_aktywne_ostatni_import_at_idx',
        ] as $nazwa) {
            $xinfo = $this->db->query("PRAGMA index_xinfo(" . $this->db->escape($nazwa) . ")")->getResultArray();
            $kolumnyKlucza = array_values(array_filter($xinfo, fn (array $r): bool => (int) $r['key'] === 1));
            $this->assertCount(2, $kolumnyKlucza, $nazwa);
            foreach ($kolumnyKlucza as $r) {
                $this->assertSame(1, (int) $r['desc'], $nazwa . ' kolumna ' . $r['name'] . ' ma być DESC');
            }
        }

        $m->up(); // IF NOT EXISTS — drugi raz bez błędu
        $this->assertArrayHasKey('mcs_threatfox_trafienia_aktywne_ostatnie_at_idx', $this->indeksyTabeli('mcs_threatfox_trafienia'));

        $m->down();
        $this->assertSame([], $this->indeksyTabeli('mcs_threatfox_trafienia'));
        $this->assertSame([], $this->indeksyTabeli('mcs_threatfox_ioc'));

        $m->down(); // IF EXISTS — drugi raz bez błędu
    }

    public function testSortyWidokuUzywajaIndeksuBezSortowania(): void
    {
        $m = new \App\Database\Migrations\IndeksMcsThreatfoxPanelSorty();
        $m->up();

        // dane jak w panelu: aktywne/nieaktywne przeplatane datami
        $this->db->table('mcs_threatfox_trafienia')->insertBatch([
            ['ioc' => 'a', 'aktywne' => true,  'ostatnie_at' => '2026-11-20 10:00:00+00'],
            ['ioc' => 'b', 'aktywne' => false, 'ostatnie_at' => '2026-11-24 10:00:00+00'],
            ['ioc' => 'c', 'aktywne' => true,  'ostatnie_at' => '2026-11-24 09:00:00+00'],
            ['ioc' => 'd', 'aktywne' => true,  'ostatnie_at' => '2026-11-23 10:00:00+00'],
        ]);
        $this->db->table('mcs_threatfox_ioc')->insertBatch([
            ['ioc' => 'x', 'aktywne' => true,  'ostatni_import_at' => '2026-11-21 10:00:00+00'],
            ['ioc' => 'y', 'aktywne' => false, 'ostatni_import_at' => '2026-11-24 10:00:00+00'],
            ['ioc' => 'z', 'aktywne' => true,  'ostatni_import_at' => '2026-11-24 09:00:00+00'],
        ]);

        // dokładnie zapytania z plugins/threatfox/Widok.php::dane (:35-40)
        $traf = $this->db->table('mcs_threatfox_trafienia')
            ->orderBy('aktywne', 'DESC')->orderBy('ostatnie_at', 'DESC')->limit(200)->get()->getResultArray();
        $this->assertSame(['c', 'd', 'a', 'b'], array_column($traf, 'ioc'));

        $ioc = $this->db->table('mcs_threatfox_ioc')
            ->orderBy('aktywne', 'DESC')->orderBy('ostatni_import_at', 'DESC')->limit(100)->get()->getResultArray();
        $this->assertSame(['z', 'x', 'y'], array_column($ioc, 'ioc'));

        // EXPLAIN QUERY PLAN: skan po indeksie, BEZ "USE TEMP B-TREE FOR ORDER BY" (brak węzła Sort)
        foreach ([
            'mcs_threatfox_trafienia' => 'ORDER BY aktywne DESC, ostatnie_at DESC LIMIT 200',
            'mcs_threatfox_ioc'        => 'ORDER BY aktywne DESC, ostatni_import_at DESC LIMIT 100',
        ] as $tabela => $orderLimit) {
            $sql = 'EXPLAIN QUERY PLAN SELECT * FROM ' . $this->db->prefixTable($tabela) . ' ' . $orderLimit;
            $res = $this->db->query($sql);
            $planText = implode(' ', array_column($res->getResultArray(), 'detail'));
            $res->freeResult();
            unset($res);
            $this->assertStringNotContainsStringIgnoringCase('TEMP B-TREE', $planText, $tabela . ': sort ma iść po indeksie, nie po temp b-tree');
            $this->assertStringContainsStringIgnoringCase('USING INDEX', $planText, $tabela . ': plan ma użyć indeksu kompozytowego');
        }
    }
}
