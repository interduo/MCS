<?php

use App\Libraries\Enrichment\EnrichmentSerwisBaza;
use App\Models\EnrichmentCacheModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Test szkieletu sprawdz() ze wspólnej klasy bazowej (issue #467):
 * kolejność kroków walidacja IP → cache → fixture → źródło oraz hooki.
 *
 * @internal
 */
final class EnrichmentSerwisBazaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private function serwis(?string $fixture = null, bool $maZrodlo = true): TestowyEnrichmentSerwis
    {
        return new TestowyEnrichmentSerwis(new TestowyEnrichmentModel($this->db), 3600, $fixture, $maZrodlo);
    }

    public function testNiepoprawnyIpZwracaBladBezWywolaniaZrodla(): void
    {
        $s = $this->serwis();

        $wynik = $s->sprawdz('nie-ip');

        $this->assertFalse($wynik['ok']);
        $this->assertFalse($wynik['z_cache']);
        $this->assertSame('Wymagany prawidłowy adres IP.', $wynik['blad']);
        $this->assertSame('nie-ip', $wynik['ip']);
        $this->assertNull($wynik['wynik']);
        $this->assertSame([], $s->wywolania);
    }

    public function testHookWalidujIpBlokujePrzedCacheIZrodlem(): void
    {
        $s           = $this->serwis();
        $s->bladIp   = 'test: tylko IPv4';
        $s->modelTestowy()->wiersze['1.2.3.4'] = ['ip' => '1.2.3.4', 'checked_at' => gmdate('Y-m-d H:i:sP')];

        $wynik = $s->sprawdz('1.2.3.4');

        $this->assertFalse($wynik['ok']);
        $this->assertSame('test: tylko IPv4', $wynik['blad']);
        $this->assertSame([], $s->wywolania);
    }

    public function testCacheHitPomijaFixtureIZrodlo(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'mcs-fixture-');
        file_put_contents($fixture, '{}');
        $s = $this->serwis($fixture);
        $s->modelTestowy()->wiersze['1.2.3.4'] = ['ip' => '1.2.3.4', 'checked_at' => gmdate('Y-m-d H:i:sP')];

        $wynik = $s->sprawdz('1.2.3.4');

        $this->assertTrue($wynik['ok']);
        $this->assertTrue($wynik['z_cache']);
        $this->assertSame('cache', $wynik['tryb']);
        $this->assertSame([], $s->wywolania);
        unlink($fixture);
    }

    public function testForcePomijaCache(): void
    {
        $s = $this->serwis();
        $s->modelTestowy()->wiersze['1.2.3.4'] = ['ip' => '1.2.3.4', 'checked_at' => gmdate('Y-m-d H:i:sP')];

        $wynik = $s->sprawdz('1.2.3.4', true);

        $this->assertTrue($wynik['ok']);
        $this->assertFalse($wynik['z_cache']);
        $this->assertSame('api', $wynik['tryb']);
        $this->assertSame(['api'], $s->wywolania);
    }

    public function testFixtureMaPierwszenstwoNadZrodlem(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'mcs-fixture-');
        file_put_contents($fixture, '{}');
        $s = $this->serwis($fixture);

        $wynik = $s->sprawdz('1.2.3.4', true);

        $this->assertTrue($wynik['ok']);
        $this->assertSame('fixture', $wynik['tryb']);
        $this->assertSame(['fixture'], $s->wywolania);
        unlink($fixture);
    }

    public function testBrakZrodlaZwracaTrybBrakIKomunikat(): void
    {
        $s = $this->serwis(null, false);

        $this->assertSame('brak_klucza', $s->tryb());
        $wynik = $s->sprawdz('1.2.3.4', true);

        $this->assertFalse($wynik['ok']);
        $this->assertSame('brak_klucza', $wynik['tryb']);
        $this->assertSame('brak testowego źródła', $wynik['blad']);
        $this->assertSame([], $s->wywolania);
    }

    public function testTrybZFixtureIZrodlem(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'mcs-fixture-');
        file_put_contents($fixture, '{}');

        $this->assertSame('fixture', $this->serwis($fixture)->tryb());
        $this->assertSame('api', $this->serwis()->tryb());

        unlink($fixture);
    }

    public function testPodgladCache(): void
    {
        $s = $this->serwis();

        $this->assertNull($s->podgladCache('nie-ip'));
        $this->assertNull($s->podgladCache('1.2.3.4'));

        $s->modelTestowy()->wiersze['1.2.3.4'] = ['ip' => '1.2.3.4', 'checked_at' => gmdate('Y-m-d H:i:sP'), 'x' => 1];
        $this->assertSame(['ip' => '1.2.3.4', 'checked_at' => $s->modelTestowy()->wiersze['1.2.3.4']['checked_at'], 'x' => 1], $s->podgladCache('1.2.3.4'));

        // stary cache (poza TTL) nie jest ważny
        $s->modelTestowy()->wiersze['1.2.3.4'] = ['ip' => '1.2.3.4', 'checked_at' => gmdate('Y-m-d H:i:sP', time() - 7200)];
        $this->assertNull($s->podgladCache('1.2.3.4'));
    }
}

/**
 * Model-double bez tabeli: cache trzymany w pamięci.
 */
final class TestowyEnrichmentModel extends EnrichmentCacheModel
{
    protected $table         = 'mcs_enrichment_testowy';
    protected $allowedFields = ['ip', 'checked_at'];

    /** @var array<string, array<string, mixed>> */
    public array $wiersze = [];

    public function znajdzPoIp(string $ip): ?array
    {
        return $this->wiersze[$ip] ?? null;
    }

    public function upsertDlaIp(string $ip, array $dane): void
    {
        $this->wiersze[$ip] = array_merge(['ip' => $ip], $dane);
    }
}

/**
 * Serwis-double: rejestruje kolejność wywołań zFixture/zZrodla.
 */
final class TestowyEnrichmentSerwis extends EnrichmentSerwisBaza
{
    /** @var list<string> */
    public array $wywolania = [];

    public ?string $bladIp = null;

    private bool $maZrodlo;

    public function __construct(TestowyEnrichmentModel $model, ?int $ttl, ?string $fixture, bool $maZrodlo)
    {
        $this->ustawCache($model, $ttl, $fixture, 'mcs.testowy.cacheTtl', 'mcs.testowy.fixturePath');
        $this->maZrodlo = $maZrodlo;
    }

    protected function maZrodlo(): bool
    {
        return $this->maZrodlo;
    }

    public function modelTestowy(): TestowyEnrichmentModel
    {
        /** @var TestowyEnrichmentModel $m */
        $m = $this->model;

        return $m;
    }

    protected function brakZrodlaKomunikat(): string
    {
        return 'brak testowego źródła';
    }

    protected function walidujIp(string $ip): ?string
    {
        return $this->bladIp;
    }

    protected function zFixture(string $ip, array $kontekst): array
    {
        $this->wywolania[] = 'fixture';

        return $this->odpowiedz(true, false, $this->trybFixture(), null, $ip, ['fixture' => true]);
    }

    protected function zZrodla(string $ip, array $kontekst): array
    {
        $this->wywolania[] = 'api';
        $wiersz            = $this->zapiszWiersz($ip, ['checked_at' => gmdate('Y-m-d H:i:sP')]);

        return $this->odpowiedz(true, false, $this->trybZrodla(), null, $ip, $this->wierszDoWyniku($wiersz));
    }

    protected function wierszDoWyniku(array $wiersz): array
    {
        return $wiersz;
    }
}
