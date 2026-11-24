<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use McsPluginy\threatfox\Klient;
use McsPluginy\threatfox\Serwis;

/**
 * Przebieg pluginu threatfox na SQLite :memory: z podstawionym HTTP (callable Klienta).
 *
 * @internal
 */
final class ThreatfoxSerwisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private const TABELE = [
        'mcs_threatfox_stan', 'mcs_threatfox_ioc', 'mcs_threatfox_trafienia', 'mcs_threatfox_historia',
        'mcs_importy', 'mcs_incydenty', 'mcs_obserwacje',
    ];

    private int $teraz = 1_800_000_000;

    /** @var array{ok: bool, http_code: int, body: string, error: string, retry_after: int} */
    private array $odpowiedz = ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'nie ustawiono', 'retry_after' => 0];

    /** @var array<string, mixed>|null ostatnie żądanie przekazane do callable HTTP */
    private ?array $ostatnieReq = null;

    private int $liczbaWolanHttp = 0;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        $p = fn (string $t): string => $this->db->prefixTable($t);
        $this->db->query("CREATE TABLE {$p('mcs_threatfox_stan')} (id INTEGER PRIMARY KEY, ostatnia_proba_at TEXT NULL, ostatnie_ok_at TEXT NULL,
            nastepne_po TEXT NULL, bledy_z_rzedu INTEGER NOT NULL DEFAULT 0, ostatni_blad TEXT NULL, ostatni_blad_at TEXT NULL,
            wynik VARCHAR(16) NOT NULL DEFAULT 'brak', wynik_at TEXT NULL, aktywne_trafienia INTEGER NOT NULL DEFAULT 0,
            rekordy INTEGER NOT NULL DEFAULT 0)");
        $this->db->query("CREATE TABLE {$p('mcs_threatfox_ioc')} (id INTEGER PRIMARY KEY AUTOINCREMENT, ioc TEXT NOT NULL UNIQUE,
            ioc_type VARCHAR(16) DEFAULT '', ip VARCHAR(45) NULL, port INTEGER NULL, threat_type VARCHAR(64) DEFAULT '',
            threat_type_desc VARCHAR(255) DEFAULT '', malware VARCHAR(255) DEFAULT '', malware_printable VARCHAR(255) DEFAULT '',
            confidence_level INTEGER NULL, first_seen VARCHAR(32) NULL, last_seen VARCHAR(32) NULL, reporter VARCHAR(255) DEFAULT '',
            reference TEXT NULL, tags VARCHAR(255) DEFAULT '', threatfox_id VARCHAR(32) NULL, aktywne BOOLEAN DEFAULT 1,
            zdjete_at TEXT NULL, pierwszy_import_at TEXT NULL, ostatni_import_at TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_threatfox_trafienia')} (id INTEGER PRIMARY KEY AUTOINCREMENT, ioc TEXT NOT NULL,
            nasz_prefiks VARCHAR(64) NOT NULL, ip VARCHAR(45) NOT NULL, port INTEGER NULL, threat_type VARCHAR(64) DEFAULT '',
            kategoria VARCHAR(32) DEFAULT '', aktywne BOOLEAN DEFAULT 1, pierwsze_at TEXT, ostatnie_at TEXT, zdjete_at TEXT NULL,
            incydent_id INTEGER NULL, payload_json TEXT NULL, UNIQUE (ioc, nasz_prefiks))");
        $this->db->query("CREATE TABLE {$p('mcs_threatfox_historia')} (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT, zdarzenie VARCHAR(16),
            ioc TEXT NULL, nasz_prefiks VARCHAR(64) NULL, opis TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_importy')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo VARCHAR(50) NOT NULL,
            id_wiadomosci TEXT NULL, rozpoczeto_at TEXT NOT NULL, zakonczono_at TEXT NULL, status VARCHAR(20) NOT NULL,
            checksum VARCHAR(255) NULL, meta_json TEXT NULL, blad_text TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_incydenty')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo VARCHAR(50) NOT NULL,
            klucz_dedupu VARCHAR(255) NOT NULL, podmiot_typ VARCHAR(20) NOT NULL DEFAULT 'ip', podmiot VARCHAR(255) NOT NULL,
            pierwsze_widziane TEXT NOT NULL, ostatnio_widziane TEXT NOT NULL, waga VARCHAR(20) NOT NULL, wersja_wagi VARCHAR(20) NULL,
            kategoria VARCHAR(100) NOT NULL, tytul VARCHAR(255) NOT NULL, port INTEGER NULL, protokol VARCHAR(20) NULL,
            ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0, UNIQUE (zrodlo, klucz_dedupu))");
        $this->db->query("CREATE TABLE {$p('mcs_obserwacje')} (id INTEGER PRIMARY KEY AUTOINCREMENT, incydent_id INTEGER NOT NULL,
            import_id INTEGER NULL, zaobserwowano_at TEXT NOT NULL, payload_json TEXT NULL, id_w_zrodle TEXT NULL)");

        $this->ustawData([
            ['id' => '1', 'ioc' => '8.8.8.8', 'ioc_type' => 'ip', 'threat_type' => 'scanner'],
            ['id' => '2', 'ioc' => 'evil.example.com', 'ioc_type' => 'domain', 'threat_type' => 'phishing'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testCzystoPoUdanymPobraniu(): void
    {
        $w = $this->serwis()->uruchom();

        $this->assertSame('ok', $w['status'], $w['msg']);
        $this->assertSame(0, $w['trafienia']);
        $this->assertSame(2, $w['rekordy']);
        $stan = $this->stan();
        $this->assertSame('czysto', $stan['wynik']);
        $this->assertNotNull($stan['ostatnie_ok_at']);
        $this->assertSame(2, (int) $stan['rekordy']);
        $this->assertSame(2, $this->db->table('mcs_threatfox_ioc')->countAllResults());
        $this->assertSame(0, $this->db->table('mcs_threatfox_trafienia')->countAllResults());
        $this->assertSame(0, $this->db->table('mcs_incydenty')->countAllResults());

        // żądanie: POST JSON z days=7 i nagłówek Auth-Key
        $this->assertSame(1, $this->liczbaWolanHttp);
        $this->assertNotNull($this->ostatnieReq);
        $this->assertSame(Klient::URL_API, $this->ostatnieReq['_url'] ?? '');
        $req = $this->ostatnieReq['req'];
        $this->assertSame('POST', $req['method']);
        $this->assertSame(['query' => 'get_iocs', 'days' => 7], json_decode($req['body'], true));
        $this->assertContains('Auth-Key: test-key-123', $req['headers']);
        $this->assertContains('Content-Type: application/json', $req['headers']);

        // następne pobranie za 6 h (+ losowa minuta 0)
        $nastepne = Serwis::ts($stan['nastepne_po']);
        $this->assertSame($this->teraz + 6 * 3600, $nastepne);
    }

    public function testDuplikatyIocWjednejOdpowiedziSaDedupowane(): void
    {
        // ThreatFox zwraca ten sam ioc wielokrotnie (różne threat_type) — UNIQUE(ioc) w tabeli.
        $this->ustawData([
            ['id' => '1', 'ioc' => '8.8.8.8', 'ioc_type' => 'ip', 'threat_type' => 'scanner'],
            ['id' => '2', 'ioc' => '8.8.8.8', 'ioc_type' => 'ip', 'threat_type' => 'botnet_c2'],
            ['id' => '3', 'ioc' => 'evil.example.com', 'ioc_type' => 'domain', 'threat_type' => 'phishing'],
        ]);

        $w = $this->serwis()->uruchom();

        $this->assertSame('ok', $w['status'], $w['msg']);
        $this->assertSame(2, $w['rekordy']);
        $this->assertSame(2, $this->db->table('mcs_threatfox_ioc')->countAllResults());
    }

    public function testNoResultsToSukcesZCzystym(): void
    {
        $this->odpowiedz = ['ok' => true, 'http_code' => 200, 'body' => '{"query_status":"no_results"}', 'error' => '', 'retry_after' => 0];

        $w = $this->serwis()->uruchom();

        $this->assertSame('ok', $w['status'], $w['msg']);
        $this->assertSame(0, $w['rekordy']);
        $this->assertSame('czysto', $this->stan()['wynik']);
        $this->assertSame(0, $this->db->table('mcs_threatfox_ioc')->countAllResults());
    }

    public function testBladHttpToNieCzysto(): void
    {
        $this->odpowiedz = ['ok' => false, 'http_code' => 503, 'body' => '', 'error' => 'HTTP 503', 'retry_after' => 0];

        $w = $this->serwis()->uruchom();

        $this->assertSame('blad', $w['status']);
        $this->assertStringContainsString('HTTP 503', $w['msg']);
        $stan = $this->stan();
        $this->assertSame('brak', $stan['wynik']);
        $this->assertNull($stan['ostatnie_ok_at']);
        $this->assertSame(1, (int) $stan['bledy_z_rzedu']);
        $this->assertSame(0, $this->db->table('mcs_threatfox_ioc')->countAllResults());
        $this->assertSame(1, $this->db->table('mcs_threatfox_historia')->where('zdarzenie', 'blad')->countAllResults());

        // 30 min później: harmonogram nie pozwala (min. 1 h)
        $this->teraz += 1800;
        $this->assertSame('pominieto', $this->serwis()->uruchom()['status']);
    }

    public function testRetryAfterWydluzaBackoff(): void
    {
        $this->odpowiedz = ['ok' => false, 'http_code' => 429, 'body' => '', 'error' => 'HTTP 429', 'retry_after' => 7200];

        $w = $this->serwis()->uruchom();
        $this->assertSame('blad', $w['status']);
        $nastepne = Serwis::ts($this->stan()['nastepne_po']);
        $this->assertSame($this->teraz + 7200, $nastepne);
    }

    public function testIllegalQueryStatusToBlad(): void
    {
        $this->odpowiedz = ['ok' => true, 'http_code' => 200, 'body' => '{"query_status":"illegal"}', 'error' => '', 'retry_after' => 0];

        $w = $this->serwis()->uruchom();
        $this->assertSame('blad', $w['status']);
        $this->assertStringContainsString('illegal', $w['msg']);
        $this->assertSame('brak', $this->stan()['wynik']);
    }

    public function testOkPustaDataToBlad(): void
    {
        $this->odpowiedz = ['ok' => true, 'http_code' => 200, 'body' => '{"query_status":"ok","data":[]}', 'error' => '', 'retry_after' => 0];

        $this->assertSame('blad', $this->serwis()->uruchom()['status']);
        $this->assertSame('brak', $this->stan()['wynik']);
    }

    public function testBrakAuthKeyToBladBezHttp(): void
    {
        $serwis = new Serwis(
            $this->db,
            new Klient(fn (string $url, array $req): array => $this->http($url, $req), 30, '', 7),
            null,
            new \App\Libraries\ImportKoperta(null, $this->db),
            fn (): int => $this->teraz,
            static fn (): array => ['91.231.80.0/22'],
            6,
            static fn (): int => 0,
        );

        $w = $serwis->uruchom();
        $this->assertSame('blad', $w['status']);
        $this->assertStringContainsString('Auth-Key', $w['msg']);
        $this->assertSame(0, $this->liczbaWolanHttp);
    }

    public function testTrafienieIpTworzyIncydentyZDedupem(): void
    {
        $this->ustawData([
            ['id' => '10', 'ioc' => '91.231.81.5', 'ioc_type' => 'ip', 'threat_type' => 'botnet_c2',
                'threat_type_desc' => 'C2 Mozi', 'malware_printable' => 'Mozi', 'confidence_level' => 95],
            ['id' => '11', 'ioc' => '91.231.80.9:4444', 'ioc_type' => 'ip:port', 'threat_type' => 'phishing',
                'threat_type_desc' => 'Phishing site', 'confidence_level' => 50],
            ['id' => '12', 'ioc' => '8.8.8.8', 'ioc_type' => 'ip', 'threat_type' => 'scanner', 'confidence_level' => 10],
            ['id' => '13', 'ioc' => 'evil.example.com', 'ioc_type' => 'domain', 'threat_type' => 'phishing'],
        ]);

        $w = $this->serwis()->uruchom();
        $this->assertSame('ok', $w['status'], $w['msg']);
        $this->assertSame(2, $w['trafienia']);
        $this->assertSame(2, $w['nowe']);
        $this->assertSame('trafienie', $this->stan()['wynik']);
        $this->assertSame(4, $this->db->table('mcs_threatfox_ioc')->countAllResults());

        $inc = $this->db->table('mcs_incydenty')->orderBy('podmiot')->get()->getResultArray();
        $this->assertCount(2, $inc);
        // 91.231.80.9 < 91.231.81.5
        $this->assertSame('91.231.80.9', $inc[0]['podmiot']);
        $this->assertSame('threatfox|91.231.80.9:4444|91.231.80.0/22', $inc[0]['klucz_dedupu']);
        $this->assertSame('abuse', $inc[0]['kategoria']);   // phishing → abuse
        $this->assertSame('high', $inc[0]['waga']);          // abuse=high, confidence 50 → medium, max = high
        $this->assertSame(4444, (int) $inc[0]['port']);
        $this->assertSame('91.231.81.5', $inc[1]['podmiot']);
        $this->assertSame('threatfox|91.231.81.5|91.231.80.0/22', $inc[1]['klucz_dedupu']);
        $this->assertSame('malware', $inc[1]['kategoria']);  // botnet_c2 → malware
        $this->assertSame('critical', $inc[1]['waga']);      // malware=critical (confidence 95 też critical)
        $this->assertNull($inc[1]['port']);
        $this->assertSame(2, $this->db->table('mcs_obserwacje')->countAllResults());

        // trafienia w tabeli wskazują na incydenty
        $traf = $this->db->table('mcs_threatfox_trafienia')->where('aktywne', true)->get()->getResultArray();
        $this->assertCount(2, $traf);
        foreach ($traf as $t) {
            $this->assertNotEmpty($t['incydent_id']);
        }

        // ponowienie po 7 h: bez nowych trafień, ale nowe obserwacje (dedup incydentu)
        $this->teraz += 7 * 3600;
        $w2 = $this->serwis()->uruchom();
        $this->assertSame('ok', $w2['status'], $w2['msg']);
        $this->assertSame(0, $w2['nowe']);
        $this->assertSame(2, $w2['trafienia']);
        $this->assertSame(2, $this->db->table('mcs_incydenty')->countAllResults());
        $this->assertSame(4, $this->db->table('mcs_obserwacje')->countAllResults());

        // IOC znikają z odpowiedzi (no_results) → trafienia zdjęte, wynik czysto
        $this->odpowiedz = ['ok' => true, 'http_code' => 200, 'body' => '{"query_status":"no_results"}', 'error' => '', 'retry_after' => 0];
        $this->teraz += 7 * 3600;
        $w3 = $this->serwis()->uruchom();
        $this->assertSame('ok', $w3['status'], $w3['msg']);
        $this->assertSame(2, $w3['zdjete']);
        $this->assertSame('czysto', $this->stan()['wynik']);
        $this->assertSame(0, $this->db->table('mcs_threatfox_trafienia')->where('aktywne', true)->countAllResults());
        $this->assertSame(0, $this->db->table('mcs_threatfox_ioc')->where('aktywne', true)->countAllResults());
        $this->assertSame(2, $this->db->table('mcs_threatfox_historia')->where('zdarzenie', 'zdjete')->countAllResults());
    }

    /**
     * Issue #443 regresja: więcej niż jedna paczka (500 rekordów) — upsert/diff/zdjęcia
     * zbiorcze dają tę samą liczbę aktywnych IOC i trafień co import „per wiersz”.
     */
    public function testWielePaczekPo500Rekordow(): void
    {
        // 700 IOC-ów IP w naszym prefiksie 91.231.80.0/22 (91.231.80.1 … 91.231.82.190)
        $data = [];
        $i    = 0;
        for ($a = 80; $a <= 82 && count($data) < 700; $a++) {
            for ($b = 1; $b <= 254 && count($data) < 700; $b++) {
                $data[] = [
                    'id' => (string) (++$i), 'ioc' => "91.231.{$a}.{$b}", 'ioc_type' => 'ip',
                    'threat_type' => 'scanner', 'confidence_level' => 10,
                ];
            }
        }
        $this->ustawData($data);

        $w = $this->serwis()->uruchom();
        $this->assertSame('ok', $w['status'], $w['msg']);
        $this->assertSame(700, $w['rekordy']);
        $this->assertSame(700, $w['trafienia']);
        $this->assertSame(700, $w['nowe']);
        $this->assertSame(700, $this->db->table('mcs_threatfox_ioc')->countAllResults());
        $this->assertSame(700, $this->db->table('mcs_threatfox_ioc')->where('aktywne', true)->countAllResults());
        $this->assertSame(700, $this->db->table('mcs_threatfox_trafienia')->where('aktywne', true)->countAllResults());
        $this->assertSame(700, $this->db->table('mcs_incydenty')->countAllResults());
        $this->assertSame(700, $this->db->table('mcs_obserwacje')->countAllResults());
        // każdy aktywny trafienie ma podpięty incydent_id
        $this->assertSame(
            0,
            $this->db->table('mcs_threatfox_trafienia')->where('aktywne', true)->where('incydent_id', null)->countAllResults(),
        );

        // drugie pobranie z podzbiorem 300 → zdjęcia zbiorcze: 400 trafień i 400 IOC zdjętych
        $this->teraz += 7 * 3600;
        $this->ustawData(array_slice($data, 0, 300));
        $w2 = $this->serwis()->uruchom();
        $this->assertSame('ok', $w2['status'], $w2['msg']);
        $this->assertSame(300, $w2['rekordy']);
        $this->assertSame(300, $w2['trafienia']);
        $this->assertSame(0, $w2['nowe']);
        $this->assertSame(400, $w2['zdjete']);
        $this->assertSame(300, $this->db->table('mcs_threatfox_ioc')->where('aktywne', true)->countAllResults());
        $this->assertSame(300, $this->db->table('mcs_threatfox_trafienia')->where('aktywne', true)->countAllResults());
        $this->assertSame(400, $this->db->table('mcs_threatfox_historia')->where('zdarzenie', 'zdjete')->countAllResults());
        // incydenty zostają (dedup), obserwacje dopisane tylko dla aktywnych trafień
        $this->assertSame(700, $this->db->table('mcs_incydenty')->countAllResults());
        $this->assertSame(1000, $this->db->table('mcs_obserwacje')->countAllResults());
    }

    public function testBladPoCzystymNieZmieniaWyniku(): void
    {
        $this->serwis()->uruchom();
        $this->assertSame('czysto', $this->stan()['wynik']);

        $this->teraz += 7 * 3600;
        $this->odpowiedz = ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'cURL: timeout', 'retry_after' => 0];

        $this->assertSame('blad', $this->serwis()->uruchom()['status']);
        $this->assertSame('czysto', $this->stan()['wynik']);
        $this->assertSame(2, $this->db->table('mcs_threatfox_ioc')->where('aktywne', true)->countAllResults());
    }

    public function testMapowanieThreatTypeNaKategorie(): void
    {
        $this->assertSame('malware', Serwis::kategoriaDla('botnet_c2'));
        $this->assertSame('malware', Serwis::kategoriaDla('Malware'));
        $this->assertSame('spam', Serwis::kategoriaDla('spam'));
        $this->assertSame('abuse', Serwis::kategoriaDla('phishing'));
        $this->assertSame('abuse', Serwis::kategoriaDla('brute_force'));
        $this->assertSame('vulnerability', Serwis::kategoriaDla('exploit'));
        $this->assertSame('reputation', Serwis::kategoriaDla('proxy'));
        $this->assertSame(Serwis::KATEGORIA_DOMYSLNA, Serwis::kategoriaDla('jakis_nowy_typ'));
        $this->assertSame('abuse', Serwis::KATEGORIA_DOMYSLNA);
    }

    public function testEtykietaUfnosci(): void
    {
        $this->assertSame('critical', Serwis::etykietaUfnosci(100));
        $this->assertSame('critical', Serwis::etykietaUfnosci(90));
        $this->assertSame('high', Serwis::etykietaUfnosci(60));
        $this->assertSame('medium', Serwis::etykietaUfnosci(30));
        $this->assertNull(Serwis::etykietaUfnosci(29));
        $this->assertNull(Serwis::etykietaUfnosci(null));
    }

    private function serwis(): Serwis
    {
        return new Serwis(
            $this->db,
            new Klient(fn (string $url, array $req): array => $this->http($url, $req), 30, 'test-key-123', 7),
            null,
            new \App\Libraries\ImportKoperta(null, $this->db),
            fn (): int => $this->teraz,
            static fn (): array => ['91.231.80.0/22', '2a0f:1700::/29'],
            6,
            static fn (): int => 0,
        );
    }

    /**
     * @return array{ok: bool, http_code: int, body: string, error: string, retry_after: int}
     */
    private function http(string $url, array $req): array
    {
        $this->liczbaWolanHttp++;
        $this->ostatnieReq = ['_url' => $url, 'req' => $req];

        return $this->odpowiedz;
    }

    /**
     * @param list<array<string, mixed>> $data
     */
    private function ustawData(array $data): void
    {
        $this->odpowiedz = [
            'ok'          => true,
            'http_code'   => 200,
            'body'        => (string) json_encode(['query_status' => 'ok', 'data' => $data]),
            'error'       => '',
            'retry_after' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stan(): array
    {
        return $this->db->table('mcs_threatfox_stan')->where('id', 1)->get()->getRowArray() ?? [];
    }
}
