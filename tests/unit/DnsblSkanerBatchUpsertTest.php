<?php

use App\Libraries\Dnsbl\DnsPakiet;
use App\Libraries\Dnsbl\Skaner;
use App\Libraries\Dnsbl\SilnikZapytan;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Issue #446: skaner DNSBL zapisuje listingi zbiorczym upsert (INSERT … ON CONFLICT partiami
 * po 500, multi-row VALUES) zamiast pojedynczego zapytania per IP — przy skanie /16 tysiące
 * upsertów i locków w jednej transakcji. Indeks (strefa_kod, aktywny) jest w migracji
 * 2026-11-24-110001 (osobny test migracji).
 *
 * @internal
 */
final class DnsblSkanerBatchUpsertTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private const TABELE = ['mcs_dnsbl_zdarzenia', 'mcs_dnsbl_listingi', 'mcs_dnsbl_wyniki', 'mcs_dnsbl_skany', 'mcs_dnsbl_strefy', 'mcs_obserwacje', 'mcs_incydenty'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        $p = fn (string $t): string => $this->db->prefixTable($t);
        $this->db->query("CREATE TABLE {$p('mcs_dnsbl_strefy')} (id INTEGER PRIMARY KEY AUTOINCREMENT, kod TEXT UNIQUE, nazwa TEXT, strefa TEXT,
            typ TEXT DEFAULT 'ip', mapa_kodow TEXT NULL, url_delisting TEXT DEFAULT '', informacyjna BOOLEAN DEFAULT 0, aktywna BOOLEAN DEFAULT 1,
            wymaga_klucza TEXT DEFAULT '', test_ip TEXT DEFAULT '127.0.0.2', kolejnosc INTEGER DEFAULT 100, uwagi TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_dnsbl_skany')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zakres TEXT, strefa_filtr TEXT NULL, tryb TEXT DEFAULT 'cli',
            status TEXT DEFAULT 'oczekuje', uzytkownik TEXT DEFAULT '', utworzono_at TEXT NOT NULL, start_at TEXT NULL, koniec_at TEXT NULL, heartbeat_at TEXT NULL,
            liczba_ip INTEGER DEFAULT 0, zapytan_plan INTEGER DEFAULT 0, zapytan_wykonano INTEGER DEFAULT 0, czyste INTEGER DEFAULT 0, listowane INTEGER DEFAULT 0,
            bledy INTEGER DEFAULT 0, czas_ms INTEGER DEFAULT 0, strefy_json TEXT NULL, komunikat TEXT NULL,
            pary TEXT NULL, nowe INTEGER DEFAULT 0, zdjete INTEGER DEFAULT 0, incydenty INTEGER DEFAULT 0, fala BOOLEAN DEFAULT 0)");
        $this->db->query("CREATE TABLE {$p('mcs_dnsbl_wyniki')} (id INTEGER PRIMARY KEY AUTOINCREMENT, skan_id INTEGER, ip TEXT, strefa_kod TEXT, status TEXT,
            kody TEXT DEFAULT '', txt TEXT NULL, blad TEXT DEFAULT '')");
        $this->db->query("CREATE TABLE {$p('mcs_dnsbl_listingi')} (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, strefa_kod TEXT, kody TEXT DEFAULT '', txt TEXT NULL,
            pierwszy_raz TEXT NOT NULL, ostatni_raz TEXT NOT NULL, aktywny BOOLEAN DEFAULT 1, skan_id INTEGER NULL, UNIQUE (ip, strefa_kod))");
        $this->db->query("CREATE TABLE {$p('mcs_dnsbl_zdarzenia')} (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, ip TEXT, strefa_kod TEXT, typ TEXT,
            skan_id INTEGER NULL, kody TEXT DEFAULT '', incydent_id INTEGER NULL, uwagi TEXT DEFAULT '')");
        $this->db->query("CREATE TABLE {$p('mcs_incydenty')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo TEXT NOT NULL, klucz_dedupu TEXT NOT NULL,
            podmiot_typ TEXT NOT NULL DEFAULT 'ip', podmiot TEXT NOT NULL, pierwsze_widziane TEXT NOT NULL, ostatnio_widziane TEXT NOT NULL, waga TEXT NOT NULL,
            wersja_wagi TEXT NULL, kategoria TEXT NOT NULL, tytul TEXT NOT NULL, port INTEGER NULL, protokol TEXT NULL, ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
            UNIQUE (zrodlo, klucz_dedupu))");
        $this->db->query("CREATE TABLE {$p('mcs_obserwacje')} (id INTEGER PRIMARY KEY AUTOINCREMENT, incydent_id INTEGER NOT NULL, import_id INTEGER NULL,
            zaobserwowano_at TEXT NOT NULL, payload_json TEXT NULL, id_w_zrodle TEXT NULL)");

        // mailspike = strefa niskiej wagi (bez incydentów i bez fali przy 512 nowych listingach)
        $this->db->table('mcs_dnsbl_strefy')->insert([
            'kod' => 'mailspike', 'nazwa' => 'Mailspike', 'strefa' => 'bl.mailspike.net', 'typ' => 'ip',
            'informacyjna' => 0, 'kolejnosc' => 20, 'url_delisting' => 'https://x/?{ip}', 'mapa_kodow' => '{}', 'aktywna' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testSkan23Zapisuje512ListingowBatchowo(): void
    {
        // wszystkie 512 IP z 91.231.70.0/23 listowane na mailspike
        $listed = [];
        foreach (['91.231.70.', '91.231.71.'] as $p) {
            for ($i = 0; $i < 256; $i++) {
                $listed['mailspike|' . $p . $i] = ['127.0.0.10'];
            }
        }
        $silnik = new FakeSilnikBatch($listed);
        $skaner = new Skaner($this->db, $silnik);

        $inserty = 0;
        Events::on('DBQuery', static function ($q) use (&$inserty): void {
            $sql = $q->getQuery();
            if (str_starts_with($sql, 'INSERT INTO') && str_contains($sql, 'mcs_dnsbl_listingi')) {
                $inserty++;
            }
        });
        try {
            $id = $skaner->utworzSkan(['91.231.70.0/23'], null, 'cli', 'test');
            $w  = $skaner->wykonaj($id);
        } finally {
            Events::removeAllListeners('DBQuery');
        }

        $this->assertSame(512, $w['liczba_ip']);
        $this->assertSame(512, $w['listowane']);
        $this->assertSame(2, $inserty, 'zbiorczy upsert partiami po 500: ceil(512/500) = 2 INSERTy zamiast 512');
        $this->assertSame(512, $this->db->table('mcs_dnsbl_listingi')->where('aktywny', 1)->countAllResults());
        $this->assertSame(512, $this->db->table('mcs_dnsbl_wyniki')->where('status', 'listed')->countAllResults());
    }

    public function testUtrzymanyListingZachowujePierwszyRazPoZdjeciuNowy(): void
    {
        $skaner = new Skaner($this->db, new FakeSilnikBatch(['mailspike|91.231.70.1' => ['127.0.0.10']]));

        $skaner->wykonaj($skaner->utworzSkan(['91.231.70.0/30'], null, 'cli', 'test'));
        $pierwszy = $this->db->table('mcs_dnsbl_listingi')->where('ip', '91.231.70.1')->get()->getRowArray();
        $this->assertSame(1, (int) $pierwszy['aktywny']);
        $pierwszyRaz = (string) $pierwszy['pierwszy_raz'];

        // ponowny skan z tym samym listingiem — pierwszy_raz bez zmian (utrzymany)
        $skaner->wykonaj($skaner->utworzSkan(['91.231.70.0/30'], null, 'cli', 'test'));
        $drugi = $this->db->table('mcs_dnsbl_listingi')->where('ip', '91.231.70.1')->get()->getRowArray();
        $this->assertSame($pierwszyRaz, (string) $drugi['pierwszy_raz'], 'utrzymany listing: pierwszy_raz zostaje');

        // skan bez listinga — zdjęty (aktywny = 0)
        $pustySkaner = new Skaner($this->db, new FakeSilnikBatch([]));
        $pustySkaner->wykonaj($pustySkaner->utworzSkan(['91.231.70.0/30'], null, 'cli', 'test'));
        $zdjety = $this->db->table('mcs_dnsbl_listingi')->where('ip', '91.231.70.1')->get()->getRowArray();
        $this->assertSame(0, (int) $zdjety['aktywny']);
        // ręcznie postarz pierwszy_raz (skany w teście lecą w tej samej sekundzie)
        $this->db->table('mcs_dnsbl_listingi')->where('ip', '91.231.70.1')
            ->update(['pierwszy_raz' => '2000-01-01 00:00:00+00']);

        // ponowne listowanie po zdjęciu — pierwszy_raz = nowa data (CASE … ELSE EXCLUDED.pierwszy_raz)
        $skaner = new Skaner($this->db, new FakeSilnikBatch(['mailspike|91.231.70.1' => ['127.0.0.10']]));
        $skaner->wykonaj($skaner->utworzSkan(['91.231.70.0/30'], null, 'cli', 'test'));
        $ponownie = $this->db->table('mcs_dnsbl_listingi')->where('ip', '91.231.70.1')->get()->getRowArray();
        $this->assertSame(1, (int) $ponownie['aktywny']);
        $this->assertNotSame('2000-01-01 00:00:00+00', (string) $ponownie['pierwszy_raz'], 'listing po zdjęciu dostaje nowy pierwszy_raz');
    }
}

/**
 * Silnik DNS bez sieci: testy kontrolne OK, A wg klucza „kod|ip”, reszta NXDOMAIN.
 */
final class FakeSilnikBatch extends SilnikZapytan
{
    /**
     * @param array<string, list<string>> $listed
     */
    public function __construct(private array $listed)
    {
        parent::__construct();
    }

    public function wykonaj(array $zadania, ?callable $poOdpowiedzi = null): array
    {
        $out = [];
        foreach ($zadania as $z) {
            $k = $z['klucz'];
            if ($z['typ'] === DnsPakiet::TYP_TXT) {
                $w = SilnikZapytan::wynik('odp', 0, [], ['listed ' . $k], '');
            } elseif (str_starts_with($k, 't+|')) {
                $w = SilnikZapytan::wynik('odp', 0, ['127.0.0.2'], [], '');
            } elseif (str_starts_with($k, 't-|')) {
                $w = SilnikZapytan::wynik('odp', DnsPakiet::RCODE_NXDOMAIN, [], [], '');
            } else {
                $w = isset($this->listed[$k])
                    ? SilnikZapytan::wynik('odp', 0, $this->listed[$k], [], '')
                    : SilnikZapytan::wynik('odp', DnsPakiet::RCODE_NXDOMAIN, [], [], '');
            }
            $out[$k] = $w;
            if ($poOdpowiedzi !== null) {
                $poOdpowiedzi($k, $w, $z);
            }
        }

        return $out;
    }
}
