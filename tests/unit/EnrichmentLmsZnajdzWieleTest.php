<?php

use App\Models\EnrichmentLmsModel;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Issue #445: zbiorczy lookup LMS znajdzWiele() — jeden SELECT … WHERE ip IN (…) na partię 500
 * zamiast N zapytań per IP (Dashboard::dnsbl robił do 500 osobnych SELECTów).
 *
 * @internal
 */
final class EnrichmentLmsZnajdzWieleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $tabela = $this->db->prefixTable('mcs_enrichment_lms');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
                ip VARCHAR(45) PRIMARY KEY,
                node_id INTEGER NULL,
                komputer VARCHAR(64) NULL,
                node_login VARCHAR(128) NULL,
                customer_id INTEGER NULL,
                abonent VARCHAR(255) NULL,
                customer_lastname VARCHAR(128) NULL,
                customer_name VARCHAR(128) NULL,
                netid INTEGER NULL,
                access SMALLINT NULL,
                trafiony INTEGER NOT NULL DEFAULT 0,
                raw_json TEXT NULL,
                zrodlo_danych VARCHAR(32) NOT NULL DEFAULT 'api',
                checked_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
        );
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('mcs_enrichment_lms'));
        parent::tearDown();
    }

    public function testZnajdzWieleZwracaMapeJednymZapytaniem(): void
    {
        $model = model(EnrichmentLmsModel::class);
        foreach (['91.231.80.10', '91.231.80.11', '91.231.81.20'] as $i => $ip) {
            $model->upsertDlaIp($ip, ['abonent' => 'Abonent ' . $i, 'komputer' => 'PC-' . $i]);
        }

        $liczba = 0;
        Events::on('DBQuery', static function () use (&$liczba): void {
            $liczba++;
        });
        try {
            $mapa = $model->znajdzWiele(['91.231.80.10', '91.231.81.20', '10.9.9.9', '', '91.231.80.10']);
        } finally {
            Events::removeAllListeners('DBQuery');
        }

        $this->assertSame(1, $liczba, 'jeden SELECT WHERE ip IN (…) zamiast N zapytań per IP');
        $this->assertSame(['91.231.80.10', '91.231.81.20'], array_keys($mapa), 'mapa ip => wiersz, bez duplikatów i pustych IP');
        $this->assertSame('Abonent 0', $mapa['91.231.80.10']['abonent']);
        $this->assertSame('PC-2', $mapa['91.231.81.20']['komputer']);
        $this->assertArrayNotHasKey('10.9.9.9', $mapa);
    }

    public function testZnajdzWieleChunkujePo500(): void
    {
        $model = model(EnrichmentLmsModel::class);
        $ips   = [];
        for ($i = 0; $i < 1200; $i++) {
            $ip    = '91.231.80.' . ($i % 250);
            $ips[] = $ip;
            $model->upsertDlaIp($ip, ['abonent' => 'A' . $i]);
        }
        // unikalne IP: 250 sztuk
        $liczba = 0;
        Events::on('DBQuery', static function () use (&$liczba): void {
            $liczba++;
        });
        try {
            $mapa = $model->znajdzWiele($ips);
        } finally {
            Events::removeAllListeners('DBQuery');
        }

        $this->assertCount(250, $mapa);
        $this->assertSame(1, $liczba, '250 unikalnych IP mieści się w jednej partii 500');

        // 1200 unikalnych IP (poza wstawionymi) → 3 zapytania (500 + 500 + 200)
        $duze = [];
        for ($i = 0; $i < 1200; $i++) {
            $duze[] = '91.231.' . (200 + intdiv($i, 250)) . '.' . ($i % 250);
        }
        $liczba = 0;
        Events::on('DBQuery', static function () use (&$liczba): void {
            $liczba++;
        });
        try {
            $pusta = $model->znajdzWiele($duze);
        } finally {
            Events::removeAllListeners('DBQuery');
        }
        $this->assertSame(3, $liczba, 'chunkowanie po 500: ceil(1200/500) = 3 SELECTy');
        $this->assertSame([], $pusta, 'żadne z tych IP nie jest w tabeli');
    }

    public function testZnajdzWielePusteWejscieBezZapytania(): void
    {
        $model  = model(EnrichmentLmsModel::class);
        $liczba = 0;
        Events::on('DBQuery', static function () use (&$liczba): void {
            $liczba++;
        });
        try {
            $mapa = $model->znajdzWiele([]);
            $mapa2 = $model->znajdzWiele(['', '  ']);
        } finally {
            Events::removeAllListeners('DBQuery');
        }

        $this->assertSame([], $mapa);
        $this->assertSame([], $mapa2);
        $this->assertSame(0, $liczba, 'puste wejście → zero zapytań');
    }
}
