<?php

use App\Libraries\Imap\ImapKlientFabryka;
use App\Libraries\Imap\ImapKlientInterface;
use App\Libraries\Imap\ImapKlientStub;
use App\Libraries\OpcjeMcs;
use App\Models\ImportModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use McsPluginy\play\Parser as PlayParser;
use McsPluginy\play\Plugin as PlayPlugin;
use McsPluginy\shadowserver\Plugin as ShadowserverPlugin;

/**
 * Wspólna baza IMAP pluginów (issue #463): PluginImapBaza obsługuje pobierz()
 * (fixture → fabryka → importujZImap → health) i fallback „ostatnie N UID”;
 * play i shadowserver zostają cienkimi klasami.
 *
 * @internal
 */
final class ImapPluginBazaTest extends CIUnitTestCase
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
        foreach (['mcs_pluginy', 'mcs_importy', 'mcs_play_wiersze', 'mcs_shadowserver_wiersze', 'mcs_incydenty', 'mcs_obserwacje'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $p($t));
        }
        $this->db->query("CREATE TABLE {$p('mcs_pluginy')} (
            zrodlo VARCHAR(50) PRIMARY KEY, nazwa VARCHAR(100) NOT NULL, import_wstrzymany BOOLEAN NOT NULL DEFAULT 1,
            ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0, health_at TEXT NULL, health_ok BOOLEAN NULL, health_msg TEXT NULL)");
        foreach (['play', 'shadowserver'] as $z) {
            $this->db->table('mcs_pluginy')->insert(['zrodlo' => $z, 'nazwa' => $z, 'import_wstrzymany' => 0, 'ukryty_w_ui' => 0]);
        }
        $this->db->query("CREATE TABLE {$p('mcs_importy')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo VARCHAR(50) NOT NULL,
            id_wiadomosci TEXT NULL, rozpoczeto_at TEXT NOT NULL, zakonczono_at TEXT NULL, status VARCHAR(20) NOT NULL,
            checksum VARCHAR(255) NULL, meta_json TEXT NULL, blad_text TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_play_wiersze')} (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INTEGER NULL,
            zaobserwowano_at TEXT NOT NULL, ip VARCHAR(45) NULL, klucz_dedupu VARCHAR(255) NOT NULL, payload_json TEXT NULL)");
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
        foreach (['mcs_pluginy', 'mcs_importy', 'mcs_play_wiersze', 'mcs_shadowserver_wiersze', 'mcs_incydenty', 'mcs_obserwacje'] as $t) {
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

    private function fabryka(ImapKlientStub $stub): ImapKlientFabryka
    {
        return new class ($stub) extends ImapKlientFabryka {
            public function __construct(private ImapKlientStub $stub)
            {
            }

            public function dlaZrodla(string $prefiksZrodla): ImapKlientInterface
            {
                return $this->stub;
            }
        };
    }

    /**
     * Treść maila DDoS Play (fixture bez linii Subject).
     */
    private function trescDdosPlay(string $fixture): array
    {
        $raw = (string) file_get_contents(ROOTPATH . 'tests/_support/fixtures/' . $fixture);
        preg_match('/^Subject:\s*(.+)$/mi', $raw, $m);
        $subject = trim($m[1]);
        $tresc   = (string) preg_replace('/^Subject:\s*.+$/mi', '', $raw, 1);

        return [$subject, $tresc];
    }

    public function testPlayImapPrzezWspolnaBaze(): void
    {
        [$subject, $tresc] = $this->trescDdosPlay('play_ddos_cidr_update.txt');
        $stub = new ImapKlientStub([
            [
                'uid'        => 11,
                'message_id' => '<ddos-11@play>',
                'subject'    => $subject,
                'from'       => PlayParser::nadawca(),
                'tresc'      => $tresc,
            ],
        ]);

        $plugin = new PlayPlugin(null, null, null, null, $this->fabryka($stub));
        $plugin->pobierz();

        $health = $plugin->health();
        $this->assertTrue($health['ok']);
        $this->assertSame(
            'import IMAP OK (wiadomosci=1, ddos=1, nowe_importy=1, wiersze=2)',
            $health['msg'],
        );
        $this->assertSame(2, $this->db->table('mcs_play_wiersze')->countAllResults());
        // Po udanym imporcie wiadomość oznaczona jako przeczytana.
        $this->assertSame([], $stub->listujUid(true));

        // Dedup: drugi przebieg tego samego maila = 0 nowych importów.
        $plugin2 = new PlayPlugin(null, null, null, null, $this->fabryka($stub));
        $plugin2->pobierz();
        $health2 = $plugin2->health();
        $this->assertTrue($health2['ok']);
        $this->assertStringContainsString('nowe_importy=0', $health2['msg']);
    }

    public function testPlayFallbackOstatnieNUidGdyWszystkoSeen(): void
    {
        $this->ustawEnv('mcs.play.imapRecentLimit', '1');
        [$subject, $tresc] = $this->trescDdosPlay('play_ddos_event_finished.txt');

        $stub = new ImapKlientStub([
            ['uid' => 5, 'przeczytane' => true, 'from' => 'inny@example.com', 'subject' => 'test', 'tresc' => 'Witamy'],
            ['uid' => 6, 'przeczytane' => true, 'from' => 'inny@example.com', 'subject' => 'test', 'tresc' => 'Witamy'],
            [
                'uid'        => 7,
                'przeczytane' => true,
                'message_id' => '<ddos-7@play>',
                'subject'    => $subject,
                'from'       => PlayParser::nadawca(),
                'tresc'      => $tresc,
            ],
        ]);

        $plugin = new PlayPlugin(null, null, null, null, $this->fabryka($stub));
        $plugin->pobierz();

        $health = $plugin->health();
        $this->assertTrue($health['ok']);
        // Fallback „ostatnie N UID”: tylko uid=7, komunikat z tryb=ostatnie_SEEN.
        $this->assertSame(
            'import IMAP OK (wiadomosci=1, ddos=1, nowe_importy=1, wiersze=1, tryb=ostatnie_SEEN)',
            $health['msg'],
        );
    }

    public function testPlaySkrzynkaBezDdosTorGotowy(): void
    {
        $stub = new ImapKlientStub([
            ['uid' => 3, 'from' => 'inny@example.com', 'subject' => 'Witamy', 'tresc' => 'test'],
        ]);

        $plugin = new PlayPlugin(null, null, null, null, $this->fabryka($stub));
        $plugin->pobierz();

        $health = $plugin->health();
        $this->assertTrue($health['ok']);
        $this->assertStringContainsString('0× DDoS', $health['msg']);
        $this->assertStringContainsString('tor gotowy', $health['msg']);
    }

    public function testPlayFixturePrzezWspolnaBaze(): void
    {
        $this->ustawEnv('mcs.play.fixturePath', ROOTPATH . 'tests/_support/fixtures/play_ddos_event_finished.txt');

        $plugin = new PlayPlugin();
        $plugin->pobierz();

        $health = $plugin->health();
        $this->assertTrue($health['ok']);
        $this->assertSame('import fixture OK (1 wierszy)', $health['msg']);

        // Drugi przebieg = duplikat (checksum/id_wiadomosci w kopercie).
        $plugin2 = new PlayPlugin();
        $plugin2->pobierz();
        $this->assertSame('fixture bez wierszy DDoS / duplikat', $plugin2->health()['msg']);
    }

    public function testShadowserverImapPrzezWspolnaBaze(): void
    {
        $csv = (string) file_get_contents(ROOTPATH . 'tests/_support/fixtures/shadowserver_sample.csv');
        $stub = new ImapKlientStub([
            [
                'uid'         => 21,
                'message_id'  => '<ss-21@shadowserver>',
                'subject'     => 'Report',
                'zalaczniki'  => [
                    ['filename' => 'report.csv', 'mime' => 'text/csv', 'tresc' => $csv],
                ],
            ],
        ]);

        $plugin = new ShadowserverPlugin(null, null, null, null, $this->fabryka($stub));
        $plugin->pobierz();

        $health = $plugin->health();
        $this->assertTrue($health['ok']);
        $this->assertSame('import IMAP OK (wiadomosci=1, nowe_importy=1, wiersze=3)', $health['msg']);
        $this->assertSame(3, $this->db->table('mcs_shadowserver_wiersze')->countAllResults());
        $this->assertSame([], $stub->listujUid(true));
    }

    public function testShadowserverFixturePrzezWspolnaBaze(): void
    {
        $this->ustawEnv('mcs.shadowserver.fixturePath', ROOTPATH . 'tests/_support/fixtures/shadowserver_sample.csv');

        $plugin = new ShadowserverPlugin();
        $plugin->pobierz();

        $health = $plugin->health();
        $this->assertTrue($health['ok']);
        $this->assertSame('import fixture OK (3 wierszy)', $health['msg']);
    }

    public function testShadowserverZbytDuzyZalacznikDajeStatusBlad(): void
    {
        $this->ustawEnv('mcs.import.maxPlikMb', '1');

        $stub = new ImapKlientStub([
            [
                'uid'         => 31,
                'message_id'  => '<ss-31@shadowserver>',
                'subject'     => 'Report',
                'zalaczniki'  => [
                    ['filename' => 'huge.csv', 'mime' => 'text/csv', 'tresc' => str_repeat('a', 2 * 1024 * 1024)],
                ],
            ],
        ]);

        $plugin = new ShadowserverPlugin(null, null, null, null, $this->fabryka($stub));
        $plugin->pobierz();

        // Sam import przebiegu kończy się OK (pominięto załącznik), ale odrzucenie jest oznaczone `blad`.
        $health = $plugin->health();
        $this->assertTrue($health['ok']);
        $this->assertStringContainsString('nowe_importy=0', $health['msg']);

        $blad = model(ImportModel::class)->where('zrodlo', 'shadowserver')->where('status', 'blad')->first();
        $this->assertIsArray($blad);
        $this->assertStringContainsString('huge.csv', (string) $blad['blad_text']);
        $this->assertStringContainsString('limit', (string) $blad['blad_text']);

        $this->assertSame(0, $this->db->table('mcs_shadowserver_wiersze')->countAllResults());
    }

    public function testOpisPolaczeniaImapDlaObuZrodel(): void
    {
        // Bez .env oba źródła raportują „brak konfiguracji”, ale opis idzie wspólną ścieżką IMAP.
        $play = (new PlayPlugin())->opisPolaczenia();
        $ss   = (new ShadowserverPlugin())->opisPolaczenia();

        $this->assertSame('brak', $play['tryb_aktywny']);
        $this->assertSame('brak', $ss['tryb_aktywny']);
        $this->assertSame($play, (new PlayPlugin())->opisPolaczenia());

        // Z konfiguracją IMAP w env opis przełącza się na tryb imap.
        $this->ustawEnv('mcs.play.imapHost', 'imap.play.test');
        $this->ustawEnv('mcs.play.imapUser', 'alerty@play.test');
        $opis = (new PlayPlugin())->opisPolaczenia();
        $this->assertSame('imap', $opis['tryb_aktywny']);
        $this->assertStringContainsString('imap.play.test', $opis['skrot']);
    }
}
