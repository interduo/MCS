<?php

use App\Libraries\IncydentZapis;
use App\Libraries\WagaV1;
use App\Models\IncydentModel;
use App\Models\ObserwacjaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class IncydentZapisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $incydenty   = $this->db->prefixTable('mcs_incydenty');
        $obserwacje  = $this->db->prefixTable('mcs_obserwacje');
        $this->db->query("DROP TABLE IF EXISTS {$obserwacje}");
        $this->db->query("DROP TABLE IF EXISTS {$incydenty}");

        $this->db->query(
            "CREATE TABLE {$incydenty} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zrodlo VARCHAR(50) NOT NULL,
                klucz_dedupu VARCHAR(255) NOT NULL,
                podmiot_typ VARCHAR(20) NOT NULL DEFAULT 'ip',
                podmiot VARCHAR(255) NOT NULL,
                pierwsze_widziane TEXT NOT NULL,
                ostatnio_widziane TEXT NOT NULL,
                waga VARCHAR(20) NOT NULL,
                wersja_wagi VARCHAR(20) NULL,
                kategoria VARCHAR(100) NOT NULL,
                tytul VARCHAR(255) NOT NULL,
                port INTEGER NULL,
                protokol VARCHAR(20) NULL,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
                UNIQUE (zrodlo, klucz_dedupu)
            )",
        );

        $this->db->query(
            "CREATE TABLE {$obserwacje} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                incydent_id INTEGER NOT NULL,
                import_id INTEGER NULL,
                zaobserwowano_at TEXT NOT NULL,
                payload_json TEXT NULL,
                id_w_zrodle TEXT NULL
            )",
        );
    }

    protected function tearDown(): void
    {
        $incydenty  = $this->db->prefixTable('mcs_incydenty');
        $obserwacje = $this->db->prefixTable('mcs_obserwacje');
        $this->db->query("DROP TABLE IF EXISTS {$obserwacje}");
        $this->db->query("DROP TABLE IF EXISTS {$incydenty}");
        parent::tearDown();
    }

    public function testNowyIncydentIObserwacja(): void
    {
        $waga = (new WagaV1())->wylicz('malware');
        $zapis = new IncydentZapis($this->db);

        $wynik = $zapis->zapisz([
            'zrodlo'            => 'shadowserver',
            'klucz_dedupu'      => '198.51.100.10|open|22',
            'podmiot'           => '198.51.100.10',
            'zaobserwowano_at'  => '2026-09-11 10:00:00+00',
            'waga'              => $waga['waga'],
            'wersja_wagi'       => $waga['wersja'],
            'kategoria'         => 'malware',
            'tytul'             => 'Shadowserver — test',
            'id_w_zrodle'       => 'src-1',
            'payload'           => ['raw' => 'x'],
        ]);

        $this->assertTrue($wynik['utworzono_incydent']);
        $this->assertGreaterThan(0, $wynik['incydent_id']);
        $this->assertGreaterThan(0, $wynik['obserwacja_id']);

        $inc = model(IncydentModel::class)->find($wynik['incydent_id']);
        $this->assertSame('critical', $inc['waga']);
        $this->assertSame('v1', $inc['wersja_wagi']);

        $obs = model(ObserwacjaModel::class)->find($wynik['obserwacja_id']);
        $this->assertSame($wynik['incydent_id'], (int) $obs['incydent_id']);
    }

    public function testPowtorzenieAktualizujeIncydentIDodajeObserwacje(): void
    {
        $zapis = new IncydentZapis($this->db);

        $baza = [
            'zrodlo'       => 'play',
            'klucz_dedupu' => 'dedup-1',
            'podmiot'      => '198.51.100.20',
            'waga'         => 'medium',
            'wersja_wagi'  => 'v0',
            'kategoria'    => 'abuse',
            'tytul'        => 'Play',
        ];

        $a = $zapis->zapisz(array_merge($baza, ['zaobserwowano_at' => '2026-09-10 10:00:00+00']));
        $b = $zapis->zapisz(array_merge($baza, [
            'zaobserwowano_at' => '2026-09-11 12:00:00+00',
            'waga'             => 'high',
            'tytul'            => 'Play — update',
        ]));

        $this->assertTrue($a['utworzono_incydent']);
        $this->assertFalse($b['utworzono_incydent']);
        $this->assertSame($a['incydent_id'], $b['incydent_id']);
        $this->assertNotSame($a['obserwacja_id'], $b['obserwacja_id']);

        $inc = model(IncydentModel::class)->find($a['incydent_id']);
        $this->assertSame('high', $inc['waga']);
        $this->assertSame('Play — update', $inc['tytul']);
        $this->assertSame('2026-09-10 10:00:00+00', $inc['pierwsze_widziane']);
        $this->assertSame('2026-09-11 12:00:00+00', $inc['ostatnio_widziane']);

        $ile = model(ObserwacjaModel::class)->where('incydent_id', $a['incydent_id'])->countAllResults();
        $this->assertSame(2, $ile);
    }

    public function testPowtorzenieAktualizujePodmiotTypZCidrNaIp(): void
    {
        $zapis = new IncydentZapis($this->db);
        $a     = $zapis->zapisz([
            'zrodlo'           => 'spoofer',
            'klucz_dedupu'     => 'session|1|v4',
            'podmiot_typ'      => 'cidr',
            'podmiot'          => '91.231.70.0/24',
            'zaobserwowano_at' => '2026-09-14 18:19:47+00',
            'waga'             => 'low',
            'kategoria'        => 'other',
            'tytul'            => 'Spoofer',
        ]);
        $zapis->zapisz([
            'zrodlo'           => 'spoofer',
            'klucz_dedupu'     => 'session|1|v4',
            'podmiot_typ'      => 'ip',
            'podmiot'          => '91.231.70.0',
            'zaobserwowano_at' => '2026-09-14 18:19:47+00',
            'waga'             => 'low',
            'kategoria'        => 'other',
            'tytul'            => 'Spoofer IPv4: routed blocked (91.231.70.0/24)',
        ]);

        $inc = model(IncydentModel::class)->find($a['incydent_id']);
        $this->assertSame('ip', $inc['podmiot_typ']);
        $this->assertSame('91.231.70.0', $inc['podmiot']);
    }

    public function testZapiszWieleZbiorczo(): void
    {
        $zapis = new IncydentZapis($this->db);

        $wiersze = [];
        for ($i = 1; $i <= 3; $i++) {
            $wiersze[] = [
                'zrodlo'           => 'shadowserver',
                'klucz_dedupu'     => 'klucz-' . $i,
                'podmiot'          => '198.51.100.' . $i,
                'zaobserwowano_at' => '2026-09-11 10:00:0' . $i . '+00',
                'waga'             => 'high',
                'kategoria'        => 'exposure',
                'tytul'            => 't' . $i,
                'id_w_zrodle'      => 'src-' . $i,
                'payload'          => ['i' => $i],
            ];
        }

        $wyniki = $zapis->zapiszWiele($wiersze);

        $this->assertCount(3, $wyniki);
        foreach ($wyniki as $i => $w) {
            $this->assertTrue($w['utworzono_incydent']);
            $this->assertGreaterThan(0, $w['incydent_id']);
            $this->assertGreaterThan(0, $w['obserwacja_id']);
            $obs = model(ObserwacjaModel::class)->find($w['obserwacja_id']);
            $this->assertSame($w['incydent_id'], (int) $obs['incydent_id']);
            $this->assertSame('src-' . ($i + 1), $obs['id_w_zrodle']);
        }
        $this->assertSame(3, model(IncydentModel::class)->countAll());
        $this->assertSame(3, model(ObserwacjaModel::class)->countAll());
        // wyniki w kolejności wejściowej — różne incydenty
        $this->assertNotSame($wyniki[0]['incydent_id'], $wyniki[1]['incydent_id']);
    }

    /**
     * Issue #442: powtórzony klucz (w jednej paczce i między wywołaniami) nie rzuca wyjątku
     * (ON CONFLICT), incydent jest jeden, obserwacja po każdym wierszu, ostatnia waga wygrywa.
     */
    public function testZapiszWielePowtorzonyKluczBezWyjatku(): void
    {
        $zapis = new IncydentZapis($this->db);
        $baza  = [
            'zrodlo'           => 'play',
            'klucz_dedupu'     => 'dup-1',
            'podmiot'          => '198.51.100.7',
            'zaobserwowano_at' => '2026-09-11 10:00:00+00',
            'waga'             => 'low',
            'kategoria'        => 'abuse',
            'tytul'            => 'pierwszy',
        ];

        // dwa razy ten sam klucz w jednym wywołaniu zbiorczym
        $wyniki = $zapis->zapiszWiele([
            $baza,
            array_merge($baza, ['waga' => 'high', 'tytul' => 'drugi', 'zaobserwowano_at' => '2026-09-11 11:00:00+00']),
        ]);

        $this->assertCount(2, $wyniki);
        $this->assertTrue($wyniki[0]['utworzono_incydent']);
        $this->assertFalse($wyniki[1]['utworzono_incydent']);
        $this->assertSame($wyniki[0]['incydent_id'], $wyniki[1]['incydent_id']);
        $this->assertNotSame($wyniki[0]['obserwacja_id'], $wyniki[1]['obserwacja_id']);

        // kolejne wywołanie z tym samym kluczem — też bez wyjątku (jak dwa równoległe procesy)
        $trzeci = $zapis->zapiszWiele([array_merge($baza, [
            'waga'             => 'critical',
            'tytul'            => 'trzeci',
            'zaobserwowano_at' => '2026-09-11 12:00:00+00',
        ])])[0];
        $this->assertFalse($trzeci['utworzono_incydent']);
        $this->assertSame($wyniki[0]['incydent_id'], $trzeci['incydent_id']);

        $this->assertSame(1, model(IncydentModel::class)->countAll());
        $this->assertSame(3, model(ObserwacjaModel::class)->countAll());
        $inc = model(IncydentModel::class)->find($wyniki[0]['incydent_id']);
        $this->assertSame('critical', $inc['waga']);
        $this->assertSame('trzeci', $inc['tytul']);
        $this->assertSame('2026-09-11 10:00:00+00', $inc['pierwsze_widziane']);
        $this->assertSame('2026-09-11 12:00:00+00', $inc['ostatnio_widziane']);
    }

    public function testZapiszWieleAktualizujeIstniejacyBezZmianyPierwszegoWidziane(): void
    {
        $zapis = new IncydentZapis($this->db);
        $baza  = [
            'zrodlo'           => 'snds',
            'klucz_dedupu'     => 'snds-1',
            'podmiot_typ'      => 'cidr',
            'podmiot'          => '91.231.70.0/24',
            'zaobserwowano_at' => '2026-09-10 10:00:00+00',
            'waga'             => 'low',
            'kategoria'        => 'other',
            'tytul'            => 'stary',
        ];
        $a = $zapis->zapisz($baza);

        $wyniki = $zapis->zapiszWiele([array_merge($baza, [
            'podmiot_typ'      => 'ip',
            'podmiot'          => '91.231.70.0',
            'zaobserwowano_at' => '2026-09-12 10:00:00+00',
            'waga'             => 'medium',
            'tytul'            => 'nowy',
            'port'             => 25,
        ])]);

        $this->assertFalse($wyniki[0]['utworzono_incydent']);
        $this->assertSame($a['incydent_id'], $wyniki[0]['incydent_id']);
        $inc = model(IncydentModel::class)->find($a['incydent_id']);
        $this->assertSame('ip', $inc['podmiot_typ']);
        $this->assertSame('91.231.70.0', $inc['podmiot']);
        $this->assertSame('medium', $inc['waga']);
        $this->assertSame('nowy', $inc['tytul']);
        $this->assertSame(25, (int) $inc['port']);
        $this->assertSame('2026-09-10 10:00:00+00', $inc['pierwsze_widziane']);
        $this->assertSame('2026-09-12 10:00:00+00', $inc['ostatnio_widziane']);
        $this->assertSame(2, model(ObserwacjaModel::class)->where('incydent_id', $a['incydent_id'])->countAllResults());
    }

    /**
     * Więcej niż jedna paczka (domyślnie 500) — każda paczka w osobnej transakcji,
     * klucz powtórzony między paczkami nie jest tworzony drugi raz.
     */
    public function testZapiszWielePonadRozmiarPaczki(): void
    {
        $zapis   = new IncydentZapis($this->db);
        $wiersze = [];
        for ($i = 0; $i < 1200; $i++) {
            $wiersze[] = [
                'zrodlo'           => 'wazuh',
                'klucz_dedupu'     => 'w-' . $i,
                'podmiot'          => '203.0.113.' . ($i % 250 + 1),
                'zaobserwowano_at' => '2026-09-11 10:00:00+00',
                'waga'             => 'medium',
                'kategoria'        => 'abuse',
                'tytul'            => 't' . $i,
            ];
        }
        // duplikat klucza w drugiej paczce (indeks 700)
        $wiersze[700] = array_merge($wiersze[700], ['klucz_dedupu' => 'w-0', 'waga' => 'high']);

        $wyniki = $zapis->zapiszWiele($wiersze, 500);

        $this->assertCount(1200, $wyniki);
        $this->assertTrue($wyniki[0]['utworzono_incydent']);
        $this->assertFalse($wyniki[700]['utworzono_incydent']);
        $this->assertSame($wyniki[0]['incydent_id'], $wyniki[700]['incydent_id']);
        $this->assertSame(1199, model(IncydentModel::class)->countAll());
        $this->assertSame(1200, model(ObserwacjaModel::class)->countAll());
        $inc = model(IncydentModel::class)->find($wyniki[0]['incydent_id']);
        $this->assertSame('high', $inc['waga']);
    }

    public function testZapiszWieleWalidacjaRzucaWyjatekPrzedZapisem(): void
    {
        $zapis = new IncydentZapis($this->db);

        try {
            $zapis->zapiszWiele([
                [
                    'zrodlo'           => 'play',
                    'klucz_dedupu'     => 'ok-1',
                    'podmiot'          => '198.51.100.1',
                    'zaobserwowano_at' => '2026-09-11 10:00:00+00',
                    'waga'             => 'low',
                    'kategoria'        => 'abuse',
                    'tytul'            => 'ok',
                ],
                [
                    'zrodlo'           => 'play',
                    'klucz_dedupu'     => 'bez-tytulu',
                    'podmiot'          => '198.51.100.2',
                    'zaobserwowano_at' => '2026-09-11 10:00:00+00',
                    'waga'             => 'low',
                    'kategoria'        => 'abuse',
                ],
            ]);
            $this->fail('Oczekiwano RuntimeException przy braku wymaganego pola.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Brak wymaganego pola: tytul', $e->getMessage());
        }

        $this->assertSame(0, model(IncydentModel::class)->countAll());
        $this->assertSame(0, model(ObserwacjaModel::class)->countAll());
    }

    /**
     * Po błędzie zapisu (tu: brak tabeli obserwacji) kolejny zapis na tym samym połączeniu
     * ma działać. transStrict zostawia transStatus=false, jeśli go nie wyzerować.
     */
    public function testZapisPoBledzieNaTymSamymPolaczeniuDziala(): void
    {
        $zapis = new IncydentZapis($this->db);
        $dane  = [
            'zrodlo'           => 'shadowserver',
            'klucz_dedupu'     => '198.51.100.20|open|22',
            'podmiot'          => '198.51.100.20',
            'zaobserwowano_at' => '2026-09-11 10:00:00+00',
            'waga'             => 'high',
            'kategoria'        => 'exposure',
            'tytul'            => 'test',
        ];

        $obserwacje = $this->db->prefixTable('mcs_obserwacje');
        $this->db->query("ALTER TABLE {$obserwacje} RENAME TO {$obserwacje}_x");

        try {
            $zapis->zapisz($dane);
            $this->fail('Oczekiwano wyjątku przy braku tabeli obserwacji.');
        } catch (\Throwable $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->db->query("ALTER TABLE {$obserwacje}_x RENAME TO {$obserwacje}");

        $wynik = $zapis->zapisz($dane);

        $this->assertGreaterThan(0, $wynik['obserwacja_id']);
        $this->assertSame(1, (int) $this->db->table('mcs_obserwacje')->countAllResults());
    }
}
