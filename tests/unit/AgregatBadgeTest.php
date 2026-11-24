<?php

use App\Libraries\Badge\AgregatBadge;
use App\Models\IncydentModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Issue #464: logika agregatu badge w serwisie App\Libraries\Badge\AgregatBadge;
 * IncydentModel tylko deleguje (kontrakt publiczny metod i stałych bez zmian).
 *
 * @internal
 */
final class AgregatBadgeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $pluginy   = $this->db->prefixTable('mcs_pluginy');
        $incydenty = $this->db->prefixTable('mcs_incydenty');

        $this->db->query("DROP TABLE IF EXISTS {$incydenty}");
        $this->db->query("DROP TABLE IF EXISTS {$pluginy}");

        $this->db->query(
            "CREATE TABLE {$pluginy} (
                zrodlo VARCHAR(50) PRIMARY KEY,
                nazwa VARCHAR(100) NOT NULL,
                import_wstrzymany BOOLEAN NOT NULL DEFAULT 1,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
                health_at TEXT NULL,
                health_ok BOOLEAN NULL,
                health_msg TEXT NULL
            )",
        );
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
                kategoria VARCHAR(100) NOT NULL,
                tytul VARCHAR(255) NOT NULL,
                port INTEGER NULL,
                protokol VARCHAR(20) NULL,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
                UNIQUE (zrodlo, klucz_dedupu)
            )",
        );

        $this->db->table('mcs_pluginy')->insertBatch([
            ['zrodlo' => 'shadowserver', 'nazwa' => 'Shadowserver', 'import_wstrzymany' => 1, 'ukryty_w_ui' => 0],
            ['zrodlo' => 'play', 'nazwa' => 'Play', 'import_wstrzymany' => 1, 'ukryty_w_ui' => 0],
            ['zrodlo' => 'snds', 'nazwa' => 'SNDS', 'import_wstrzymany' => 1, 'ukryty_w_ui' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('mcs_incydenty'));
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('mcs_pluginy'));
        parent::tearDown();
    }

    public function testPustyAgregatDlaNieznanegoIp(): void
    {
        $serwis  = new AgregatBadge($this->db);
        $agregat = $serwis->dlaIp('198.51.100.10');

        $this->assertSame(7, $agregat['okno_dni']);
        $this->assertSame(0, $agregat['liczba']);
        $this->assertNull($agregat['max_waga']);
        $this->assertSame(0, $agregat['liczba_zrodel']);
        $this->assertSame([], $agregat['zrodla']);
        $this->assertSame([], $agregat['kategorie']);
        $this->assertSame([], $agregat['przyklady']);
    }

    public function testOknoDniClampowaneDoMinimumJeden(): void
    {
        $serwis = new AgregatBadge($this->db);

        $this->assertSame(1, $serwis->dlaIp('198.51.100.10', 0)['okno_dni']);
        $this->assertSame(30, $serwis->dlaIp('198.51.100.10', 30)['okno_dni']);
    }

    public function testDlaIpListaBatchWhereInZOknemIUkryciami(): void
    {
        $ipA    = '198.51.100.10';
        $ipB    = '203.0.113.1';
        $ipC    = '203.0.113.50';
        $teraz  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $wOknie = $teraz->modify('-1 days')->format('Y-m-d H:i:s');
        $poza   = $teraz->modify('-12 days')->format('Y-m-d H:i:s');

        $this->wstawIncydent('shadowserver', 'a1', $ipA, 'high', $wOknie, false, 'botnet', 'SS bot');
        $this->wstawIncydent('play', 'a2', $ipA, 'low', $wOknie, false, 'spam', 'Play low');
        $this->wstawIncydent('play', 'a3', $ipA, 'critical', $wOknie, false, 'botnet', 'Play crit');
        $this->wstawIncydent('snds', 'a4', $ipA, 'critical', $wOknie); // ukryte źródło
        $this->wstawIncydent('shadowserver', 'a5', $ipA, 'medium', $wOknie, true); // ukryty_w_ui
        $this->wstawIncydent('play', 'b1', $ipB, 'medium', $wOknie);
        $this->wstawIncydent('shadowserver', 'c-old', $ipC, 'critical', $poza); // poza oknem

        $serwis = new AgregatBadge($this->db);
        $mapa   = $serwis->dlaIpLista([$ipA, $ipB, $ipC, $ipA]);

        $this->assertSame([$ipA, $ipB, $ipC], array_keys($mapa));

        $this->assertSame(3, $mapa[$ipA]['liczba']);
        $this->assertSame('critical', $mapa[$ipA]['max_waga']);
        $this->assertSame(2, $mapa[$ipA]['liczba_zrodel']);
        $this->assertSame(['play', 'shadowserver'], $mapa[$ipA]['zrodla']);
        $this->assertSame(['botnet', 'spam'], $mapa[$ipA]['kategorie']);
        $this->assertSame(['Play crit', 'SS bot', 'Play low'], $mapa[$ipA]['przyklady']);
        $this->assertSame(7, $mapa[$ipA]['okno_dni']);

        $this->assertSame(1, $mapa[$ipB]['liczba']);
        $this->assertSame('medium', $mapa[$ipB]['max_waga']);

        $this->assertSame($serwis->pusty(7), $mapa[$ipC]);
    }

    public function testDlaIpListaPustaIDeduplikacja(): void
    {
        $serwis = new AgregatBadge($this->db);

        $this->assertSame([], $serwis->dlaIpLista([]));
        $this->assertSame([], $serwis->dlaIpLista(['', '   ', 123]));

        $mapa = $serwis->dlaIpLista([' 198.51.100.10 ', '198.51.100.10']);
        $this->assertSame(['198.51.100.10'], array_keys($mapa));
    }

    public function testLimityKategoriiIPrzykladow(): void
    {
        $ip     = '198.51.100.10';
        $teraz  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $wOknie = $teraz->modify('-1 days')->format('Y-m-d H:i:s');

        // 7 kategorii (limit 5, najczęstsze pierwsze) i 5 tytułów (limit 3, wg rangi wagi)
        foreach (['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7'] as $i => $kat) {
            $this->wstawIncydent('shadowserver', "k{$i}", $ip, 'low', $wOknie, false, $kat, "Tytul {$i}");
        }
        $this->wstawIncydent('play', 't1', $ip, 'critical', $wOknie, false, 'c1', 'Krytyczny 1');
        $this->wstawIncydent('play', 't2', $ip, 'high', $wOknie, false, 'c2', 'High 1');

        $agregat = (new AgregatBadge($this->db))->dlaIp($ip);

        $this->assertCount(AgregatBadge::TOP_KATEGORII, $agregat['kategorie']);
        $this->assertSame('c1', $agregat['kategorie'][0]); // c1 ma 2 wystąpienia
        $this->assertCount(AgregatBadge::PRZYKLADOW, $agregat['przyklady']);
        $this->assertSame(['Krytyczny 1', 'High 1', 'Tytul 0'], $agregat['przyklady']);
    }

    public function testModelDelegujeDoSerwisuIStaleSaAliasami(): void
    {
        $this->assertSame(AgregatBadge::TOP_KATEGORII, IncydentModel::BADGE_TOP_KATEGORII);
        $this->assertSame(AgregatBadge::PRZYKLADOW, IncydentModel::BADGE_PRZYKLADOW);
        $this->assertSame(5, IncydentModel::BADGE_TOP_KATEGORII);
        $this->assertSame(3, IncydentModel::BADGE_PRZYKLADOW);

        $ip     = '198.51.100.10';
        $teraz  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $wOknie = $teraz->modify('-2 days')->format('Y-m-d H:i:s');
        $this->wstawIncydent('shadowserver', 'a1', $ip, 'high', $wOknie, false, 'botnet', 'SS bot');
        $this->wstawIncydent('play', 'b1', $ip, 'critical', $wOknie, false, 'abuse', 'Play crit');

        $model  = model(IncydentModel::class);
        $serwis = new AgregatBadge($this->db);

        $this->assertSame($serwis->dlaIp($ip), $model->agregatBadgeDlaIp($ip));
        $this->assertSame($serwis->dlaIp($ip, 14), $model->agregatBadgeDlaIp($ip, 14));
        $this->assertSame(
            $serwis->dlaIpLista([$ip, '203.0.113.9']),
            $model->agregatBadgeDlaIpLista([$ip, '203.0.113.9']),
        );
    }

    private function wstawIncydent(
        string $zrodlo,
        string $klucz,
        string $ip,
        string $waga,
        string $ostatnio,
        bool $ukrytyWUi = false,
        string $kategoria = 'other',
        string $tytul = '',
        ?int $port = null,
    ): void {
        $this->db->table('mcs_incydenty')->insert([
            'zrodlo'            => $zrodlo,
            'klucz_dedupu'      => $klucz,
            'podmiot_typ'       => 'ip',
            'podmiot'           => $ip,
            'pierwsze_widziane' => $ostatnio,
            'ostatnio_widziane' => $ostatnio,
            'waga'              => $waga,
            'kategoria'         => $kategoria,
            'tytul'             => $tytul !== '' ? $tytul : "Test {$klucz}",
            'port'              => $port,
            'ukryty_w_ui'       => $ukrytyWUi ? 1 : 0,
        ]);
    }
}
