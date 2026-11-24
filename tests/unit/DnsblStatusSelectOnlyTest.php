<?php

use App\Controllers\Dashboard;
use App\Libraries\Dnsbl\SkanyWidok;
use CodeIgniter\Config\Services;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\App;

/**
 * Issue #454: dnsblStatus (pollowany co 2 s) wykonuje tylko SELECT — oznaczPorzucone()
 * zostaje w renderze widoku (daneSkanera/dnsblSkan) i cron HarmonogramSkanera::krok.
 *
 * @internal
 */
final class DnsblStatusSelectOnlyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $t = $this->db->prefixTable('mcs_dnsbl_skany');
        $this->db->query("DROP TABLE IF EXISTS {$t}");
        $this->db->query(
            "CREATE TABLE {$t} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zakres TEXT, strefa_filtr TEXT NULL, tryb TEXT DEFAULT 'cli',
                status TEXT DEFAULT 'oczekuje', uzytkownik TEXT DEFAULT '',
                utworzono_at TEXT NOT NULL, start_at TEXT NULL, koniec_at TEXT NULL, heartbeat_at TEXT NULL,
                liczba_ip INTEGER DEFAULT 0, zapytan_plan INTEGER DEFAULT 0, zapytan_wykonano INTEGER DEFAULT 0,
                czyste INTEGER DEFAULT 0, listowane INTEGER DEFAULT 0, bledy INTEGER DEFAULT 0,
                czas_ms INTEGER DEFAULT 0, strefy_json TEXT NULL, komunikat TEXT NULL
            )",
        );
        // porzucony skan: w_toku bez heartbeat od godziny
        $this->db->table('mcs_dnsbl_skany')->insert([
            'zakres' => '91.231.70.0/30', 'status' => 'w_toku', 'uzytkownik' => 'test',
            'utworzono_at' => gmdate('Y-m-d H:i:sP', time() - 3600), 'heartbeat_at' => null,
            'zapytan_plan' => 4, 'zapytan_wykonano' => 2, 'strefy_json' => '[]', 'komunikat' => '',
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('mcs_dnsbl_skany'));
        parent::tearDown();
    }

    public function testDnsblStatusWykonujeTylkoSelectBezUpdate(): void
    {
        $kontroler = $this->kontroler();

        $zapytania = [];
        Events::on('DBQuery', static function ($q) use (&$zapytania): void {
            $zapytania[] = $q->getQuery();
        });
        try {
            $res = $kontroler->dnsblStatus(1);
        } finally {
            Events::removeAllListeners('DBQuery');
        }

        $j = json_decode($res->getBody(), true);
        $this->assertTrue($j['ok']);
        $this->assertSame('w_toku', $j['status']);

        $update = array_filter($zapytania, static fn (string $sql) => (bool) preg_match('/^\s*UPDATE/i', $sql));
        $this->assertSame([], array_values($update), 'dnsblStatus nie może wykonywać UPDATE (oznaczPorzucone poza endpointem)');

        // porzucony skan dalej w_toku — oznaczy go dopiero render widoku albo cron
        $wiersz = $this->db->table('mcs_dnsbl_skany')->where('id', 1)->get()->getRowArray();
        $this->assertSame('w_toku', (string) $wiersz['status']);
    }

    public function testOznaczPorzuconeNadalDzialaPozaStatus(): void
    {
        (new SkanyWidok($this->db))->oznaczPorzucone();

        $wiersz = $this->db->table('mcs_dnsbl_skany')->where('id', 1)->get()->getRowArray();
        $this->assertSame('blad', (string) $wiersz['status']);
        $this->assertStringContainsString('heartbeat', (string) $wiersz['komunikat']);
    }

    private function kontroler(): Dashboard
    {
        $config          = config(App::class);
        $config->baseURL = 'http://example.com/';
        $request         = new IncomingRequest($config, new SiteURI($config, 'dashboard/dnsbl/status/1'), 'php://input', new \CodeIgniter\HTTP\UserAgent());
        $request->setMethod('GET');
        $kontroler = new Dashboard();
        $kontroler->initController($request, Services::response(), Services::logger());

        return $kontroler;
    }
}
