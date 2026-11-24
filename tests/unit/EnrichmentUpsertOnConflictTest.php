<?php

use App\Models\EnrichmentAbuseipdbModel;
use App\Models\EnrichmentDnsblModel;
use App\Models\EnrichmentGreynoiseModel;
use App\Models\EnrichmentIsmaliciousModel;
use App\Models\EnrichmentLmsModel;
use App\Models\EnrichmentSpamratsModel;
use App\Models\EnrichmentSpeedtestModel;
use CodeIgniter\Model;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Issue #444: upsert enrichment jako pojedynczy INSERT … ON CONFLICT — dwa równoległe
 * wywołania dla tego samego klucza nie kończą się duplicate key (brak check-then-insert).
 *
 * @internal
 */
final class EnrichmentUpsertOnConflictTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    /**
     * @var array<string, array{0: class-string<Model>, 1: string, 2: string, 3: list<string>}>
     */
    private const MODELE = [
        'abuseipdb'   => [EnrichmentAbuseipdbModel::class, 'mcs_enrichment_abuseipdb', 'ip', ['ip', 'abuse_confidence_score', 'country_code', 'zrodlo_danych', 'checked_at', 'created_at', 'updated_at']],
        'dnsbl'       => [EnrichmentDnsblModel::class, 'mcs_enrichment_dnsbl', 'ip', ['ip', 'listed_any', 'zrodlo_danych', 'checked_at', 'created_at', 'updated_at']],
        'greynoise'   => [EnrichmentGreynoiseModel::class, 'mcs_enrichment_greynoise', 'ip', ['ip', 'classification', 'zrodlo_danych', 'checked_at', 'created_at', 'updated_at']],
        'ismalicious' => [EnrichmentIsmaliciousModel::class, 'mcs_enrichment_ismalicious', 'ip', ['ip', 'malicious', 'risk_score', 'zrodlo_danych', 'checked_at', 'created_at', 'updated_at']],
        'lms'         => [EnrichmentLmsModel::class, 'mcs_enrichment_lms', 'ip', ['ip', 'abonent', 'zrodlo_danych', 'checked_at', 'created_at', 'updated_at']],
        'spamrats'    => [EnrichmentSpamratsModel::class, 'mcs_enrichment_spamrats', 'ip', ['ip', 'listed', 'zrodlo_danych', 'checked_at', 'created_at', 'updated_at']],
        'speedtest'   => [EnrichmentSpeedtestModel::class, 'mcs_enrichment_speedtest', 'test_id', ['test_id', 'ip', 'zaobserwowano_at', 'download_kbps', 'zrodlo_danych', 'created_at']],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::MODELE as [$klasa, $tabela, $pk, $kolumny]) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($tabela));
            $defs = [];
            foreach ($kolumny as $k) {
                if ($k === $pk) {
                    $defs[] = $k . ' VARCHAR(64) PRIMARY KEY';
                } elseif (in_array($k, ['zrodlo_danych', 'checked_at', 'created_at', 'updated_at'], true)) {
                    $defs[] = $k . ' TEXT NOT NULL';
                } else {
                    $defs[] = $k . ' TEXT NULL';
                }
            }
            $this->db->query('CREATE TABLE ' . $this->db->prefixTable($tabela) . ' (' . implode(', ', $defs) . ')');
        }
    }

    protected function tearDown(): void
    {
        foreach (self::MODELE as [, $tabela]) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($tabela));
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: class-string<Model>, 1: string, 2: string}>
     */
    public function providerModeleIp(): iterable
    {
        foreach (self::MODELE as $nazwa => [$klasa, $tabela, $pk]) {
            if ($pk === 'ip') {
                yield $nazwa => [$klasa, $tabela, $pk];
            }
        }
    }

    /**
     * Dwa kolejne upsert tego samego IP (drugi trafia w ścieżkę konfliktu, jak przy
     * równoległym żądaniu) — jeden wiersz, brak wyjątku duplicate key, dane nadpisane.
     *
     * @dataProvider providerModeleIp
     */
    public function testPowtorzonyUpsertDlaIpNieRzucaDuplicateKey(string $klasa, string $tabela, string $pk): void
    {
        /** @var Model&object{upsertDlaIp(string, array): void} $model */
        $model = model($klasa);
        $ip    = '198.51.100.77';

        $model->upsertDlaIp($ip, ['zrodlo_danych' => 'api']);
        $pierwszy = $this->db->table($tabela)->where($pk, $ip)->get()->getRowArray();
        self::assertNotNull($pierwszy);
        self::assertSame('api', $pierwszy['zrodlo_danych']);

        // „drugie równoległe” wywołanie — konflikt po PK, nie może rzucić wyjątku
        $model->upsertDlaIp($ip, ['zrodlo_danych' => 'lms']);

        $wiersze = $this->db->table($tabela)->where($pk, $ip)->get()->getResultArray();
        self::assertCount(1, $wiersze);
        self::assertSame('lms', $wiersze[0]['zrodlo_danych']);
        // created_at z pierwszego wstawienia zostaje bez zmian
        self::assertSame($pierwszy['created_at'], $wiersze[0]['created_at']);
    }

    /**
     * Wiersz wstawiony „równolegle” (bezpośredni INSERT między check a insert w starym kodzie)
     * — upsert musi go nadpisać, a nie rzucić duplicate key.
     *
     * @dataProvider providerModeleIp
     */
    public function testUpsertDlaIpNadpisujeIstniejacyWiersz(string $klasa, string $tabela, string $pk): void
    {
        /** @var Model&object{upsertDlaIp(string, array): void} $model */
        $model = model($klasa);
        $ip    = '203.0.113.55';

        $this->db->table($tabela)->insert([
            $pk            => $ip,
            'zrodlo_danych' => 'api',
            'checked_at'   => '2026-01-01 00:00:00+00',
            'created_at'   => '2026-01-01 00:00:00+00',
            'updated_at'   => '2026-01-01 00:00:00+00',
        ]);

        $model->upsertDlaIp($ip, ['zrodlo_danych' => 'lms']);

        $wiersz = $this->db->table($tabela)->where($pk, $ip)->get()->getRowArray();
        self::assertNotNull($wiersz);
        self::assertSame('lms', $wiersz['zrodlo_danych']);
        self::assertSame('2026-01-01 00:00:00+00', $wiersz['created_at']);
        self::assertSame(1, $this->db->table($tabela)->where($pk, $ip)->countAllResults());
    }

    /**
     * Upsert wysyła pojedyncze polecenie INSERT … ON CONFLICT (bez poprzedzającego SELECT).
     *
     * @dataProvider providerModeleIp
     */
    public function testUpsertDlaIpToJedenInsertOnConflict(string $klasa, string $tabela, string $pk): void
    {
        /** @var Model&object{upsertDlaIp(string, array): void} $model */
        $model = model($klasa);

        $model->upsertDlaIp('192.0.2.10', ['zrodlo_danych' => 'api']);

        $sql = $this->db->getLastQuery()->getQuery();
        self::assertStringContainsStringIgnoringCase('INSERT INTO', $sql);
        self::assertStringContainsStringIgnoringCase('ON CONFLICT (ip) DO UPDATE SET', $sql);
        self::assertStringNotContainsStringIgnoringCase('SELECT', $sql);
    }

    public function testPowtorzonyUpsertPoTestIdNieRzucaDuplicateKey(): void
    {
        $model = model(EnrichmentSpeedtestModel::class);
        $id    = 'test-abc-1';

        $model->upsertPoTestId($id, [
            'ip'               => '198.51.100.5',
            'zaobserwowano_at' => '2026-11-01 10:00:00+00',
            'download_kbps'    => 100000,
            'zrodlo_danych'    => 'api',
        ]);
        $pierwszy = $this->db->table('mcs_enrichment_speedtest')->where('test_id', $id)->get()->getRowArray();
        self::assertNotNull($pierwszy);

        $model->upsertPoTestId($id, [
            'ip'               => '198.51.100.5',
            'zaobserwowano_at' => '2026-11-01 11:00:00+00',
            'download_kbps'    => 250000,
            'zrodlo_danych'    => 'api',
        ]);
        $sql = $this->db->getLastQuery()->getQuery();
        self::assertStringContainsStringIgnoringCase('ON CONFLICT (test_id) DO UPDATE SET', $sql);

        $wiersze = $this->db->table('mcs_enrichment_speedtest')->where('test_id', $id)->get()->getResultArray();
        self::assertCount(1, $wiersze);
        self::assertSame('250000', (string) $wiersze[0]['download_kbps']);
        self::assertSame($pierwszy['created_at'], $wiersze[0]['created_at']);
    }
}
