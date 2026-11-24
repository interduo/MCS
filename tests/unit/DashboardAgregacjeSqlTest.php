<?php

use App\Libraries\Siec\CidrMatcher;
use App\Models\IncydentModel;
use App\Models\ObserwacjaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Regresja issue #440/#441: KPI / top IP / trendy liczone w SQL dają identyczne wyniki
 * jak dawna agregacja w PHP na tych samych wierszach (fixture + referencyjna implementacja).
 *
 * @internal
 */
final class DashboardAgregacjeSqlTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private const AS_CIDRY = ['91.231.70.0/23'];

    protected function setUp(): void
    {
        parent::setUp();

        $pluginy    = $this->db->prefixTable('mcs_pluginy');
        $incydenty  = $this->db->prefixTable('mcs_incydenty');
        $obserwacje = $this->db->prefixTable('mcs_obserwacje');

        foreach ([$obserwacje, $incydenty, $pluginy] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->db->query(
            "CREATE TABLE {$pluginy} (
                zrodlo VARCHAR(50) PRIMARY KEY,
                nazwa VARCHAR(100) NOT NULL,
                import_wstrzymany BOOLEAN NOT NULL DEFAULT 1,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0
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

        $this->db->table('mcs_pluginy')->insertBatch([
            ['zrodlo' => 'shadowserver', 'nazwa' => 'Shadowserver', 'import_wstrzymany' => 1, 'ukryty_w_ui' => 0],
            ['zrodlo' => 'play', 'nazwa' => 'Play', 'import_wstrzymany' => 1, 'ukryty_w_ui' => 0],
            ['zrodlo' => 'snds', 'nazwa' => 'SNDS', 'import_wstrzymany' => 1, 'ukryty_w_ui' => 1],
        ]);

        $teraz = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dzien = static fn (int $odejmi, string $godz = '08:00:00'): string => $teraz->modify("-{$odejmi} days")->format('Y-m-d') . " {$godz}";

        // ipA (w AS) — 3 widoczne incydenty z 2 źródeł + 1 ukryte źródło + 1 ukryty wiersz
        $this->wstawIncydent('shadowserver', 'a1', '91.231.70.5', 'high', $dzien(1, '12:00:00'), $dzien(1, '08:00:00'), false, 'malware', 443);
        $this->wstawIncydent('play', 'a2', '91.231.70.5', 'critical', $dzien(2, '11:00:00'), $dzien(5), false, 'abuse');
        $this->wstawIncydent('play', 'a3', '91.231.70.5', 'low', $dzien(1, '09:30:00'), $dzien(1, '09:00:00'), false, 'malware');
        // bez obserwacji — liczy się tylko w KPI/top; kategoria malware prowadzi też przy minWaga=high (bez remisu)
        $this->wstawIncydent('play', 'a6', '91.231.70.5', 'high', $dzien(1, '11:30:00'), $dzien(1, '11:00:00'), false, 'malware');
        $this->wstawIncydent('snds', 'a4', '91.231.70.5', 'critical', $dzien(1, '09:00:00'), $dzien(1), false, 'abuse'); // ukryty plugin
        $this->wstawIncydent('play', 'a5', '91.231.70.5', 'high', $dzien(1, '10:00:00'), $dzien(1), true, 'malware');   // ukryty_w_ui
        // ipB (w AS) — 1 incydent
        $this->wstawIncydent('shadowserver', 'b1', '91.231.71.9', 'low', $dzien(3, '10:00:00'), $dzien(3, '08:00:00'), false, 'spam');
        // ipC (poza AS) — 2 incydenty z 2 źródeł
        $this->wstawIncydent('play', 'c1', '203.0.113.7', 'medium', $dzien(1, '07:00:00'), $dzien(3, '07:00:00'), false, 'malware', 22);
        $this->wstawIncydent('shadowserver', 'c2', '203.0.113.7', 'high', $dzien(4, '07:00:00'), $dzien(4), false, 'malware');
        // ipD (poza AS) — poza oknem 7 dni
        $this->wstawIncydent('shadowserver', 'd1', '8.8.8.8', 'critical', $dzien(30), $dzien(30), false, 'abuse');
        // ipF (poza AS, w osobnym CIDR 10.0.0.0/8) — 1 incydent
        $this->wstawIncydent('shadowserver', 'f1', '10.1.2.3', 'high', $dzien(1, '06:00:00'), $dzien(1, '06:00:00'), false, 'exposure');

        $id = [];
        foreach ($this->db->table('mcs_incydenty')->orderBy('id', 'ASC')->get()->getResultArray() as $w) {
            $id[$w['klucz_dedupu']] = (int) $w['id'];
        }

        $this->db->table('mcs_obserwacje')->insertBatch([
            ['incydent_id' => $id['a1'], 'zaobserwowano_at' => $dzien(1, '08:00:00')],
            ['incydent_id' => $id['a1'], 'zaobserwowano_at' => $dzien(2, '09:00:00')],
            ['incydent_id' => $id['a2'], 'zaobserwowano_at' => $dzien(2, '10:00:00')],
            ['incydent_id' => $id['a3'], 'zaobserwowano_at' => $dzien(1, '09:30:00')],
            ['incydent_id' => $id['a4'], 'zaobserwowano_at' => $dzien(1, '09:00:00')], // ukryty plugin
            ['incydent_id' => $id['a5'], 'zaobserwowano_at' => $dzien(1, '10:00:00')], // ukryty_w_ui
            ['incydent_id' => $id['b1'], 'zaobserwowano_at' => $dzien(3, '08:00:00')],
            ['incydent_id' => $id['c1'], 'zaobserwowano_at' => $dzien(1, '07:00:00')],
            ['incydent_id' => $id['c2'], 'zaobserwowano_at' => $dzien(4, '07:00:00')],
            ['incydent_id' => $id['d1'], 'zaobserwowano_at' => $dzien(30)],
            ['incydent_id' => $id['f1'], 'zaobserwowano_at' => $dzien(1, '06:00:00')],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['mcs_obserwacje', 'mcs_incydenty', 'mcs_pluginy'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testKpiIdentyczneJakStaraAgregacjaPhp(): void
    {
        $model = model(IncydentModel::class);

        // bez filtra
        $this->assertEquals($this->kpiRef(7, null, null, null, null, null), $model->kpiPrzegladu(7));
        // filtr AS (grupa CIDR)
        $this->assertEquals(
            $this->kpiRef(7, null, null, null, null, [self::AS_CIDRY]),
            $model->kpiPrzegladu(7, null, null, [self::AS_CIDRY]),
        );
        // filtr AS + własny CIDR (AND — część wspólna pusta)
        $this->assertEquals(
            $this->kpiRef(7, null, null, null, null, [self::AS_CIDRY, ['10.0.0.0/8']]),
            $model->kpiPrzegladu(7, null, null, [self::AS_CIDRY, ['10.0.0.0/8']]),
        );
        // tylko własny CIDR
        $this->assertEquals(
            $this->kpiRef(7, null, null, null, null, [['10.0.0.0/8']]),
            $model->kpiPrzegladu(7, null, null, [['10.0.0.0/8']]),
        );
        // filtry zrodlo/kategoria/port
        $this->assertEquals(
            $this->kpiRef(7, 'shadowserver', null, 'malware', null, null),
            $model->kpiPrzegladu(7, 'shadowserver', null, null, 'malware', null),
        );
        $this->assertEquals(
            $this->kpiRef(7, null, null, null, 443, null),
            $model->kpiPrzegladu(7, null, null, null, null, 443),
        );
        $this->assertEquals(
            $this->kpiRef(14, null, '91.231.70.5', null, null, [self::AS_CIDRY]),
            $model->kpiPrzegladu(14, null, '91.231.70.5', [self::AS_CIDRY]),
        );
    }

    public function testTopIdentyczneJakStaryRankingPhp(): void
    {
        $model = model(IncydentModel::class);

        $this->assertEquals(
            $this->topRef(7, null, null, null, 1, 50, null, null, null),
            $model->topProblematyczneIp(7, null, null, null, 1, 50),
        );
        $this->assertEquals(
            $this->topRef(7, null, null, null, 2, 50, null, null, null),
            $model->topProblematyczneIp(7, null, null, null, 2, 50),
        );
        $this->assertEquals(
            $this->topRef(7, null, null, 'high', 1, 50, null, null, null),
            $model->topProblematyczneIp(7, null, null, 'high', 1, 50),
        );
        $this->assertEquals(
            $this->topRef(7, null, null, null, 2, 50, [self::AS_CIDRY], null, null),
            $model->topProblematyczneIp(7, null, null, null, 2, 50, [self::AS_CIDRY]),
        );
        $this->assertEquals(
            $this->topRef(7, null, null, null, 1, 1, [['10.0.0.0/8']], 'exposure', null),
            $model->topProblematyczneIp(7, null, null, null, 1, 1, [['10.0.0.0/8']], 'exposure', null),
        );
        $this->assertEquals(
            $this->topRef(7, null, null, null, 1, 50, null, 'malware', 22),
            $model->topProblematyczneIp(7, null, null, null, 1, 50, null, 'malware', 22),
        );
    }

    public function testTrendyIdentyczneJakStaraAgregacjaPhp(): void
    {
        $model = model(ObserwacjaModel::class);

        $this->assertEquals($this->trendyRef(7, null, null, null, null, null), $model->trendyDzienne(7));
        $this->assertEquals(
            $this->trendyRef(7, null, null, null, null, [self::AS_CIDRY]),
            $model->trendyDzienne(7, null, null, null, null, [self::AS_CIDRY]),
        );
        $this->assertEquals(
            $this->trendyRef(7, null, null, null, null, [['10.0.0.0/8']]),
            $model->trendyDzienne(7, null, null, null, null, [['10.0.0.0/8']]),
        );
        $this->assertEquals(
            $this->trendyRef(7, 'play', null, null, null, null),
            $model->trendyDzienne(7, 'play'),
        );
        $this->assertEquals(
            $this->trendyRef(7, null, '203.0.113.7', 'malware', 22, null),
            $model->trendyDzienne(7, null, '203.0.113.7', 'malware', 22),
        );
        $this->assertEquals(
            $this->trendyRef(3, null, null, null, null, null),
            $model->trendyDzienne(3),
        );
    }

    // ------------------------------------------------------------------
    // Referencyjne implementacje = dawna logika PHP (przed issue #440/#441)
    // ------------------------------------------------------------------

    /**
     * @param list<list<string>>|null $grupyCidr
     *
     * @return array<string, mixed>
     */
    private function kpiRef(
        int $oknoDni,
        ?string $zrodlo,
        ?string $ip,
        ?string $kategoria,
        ?int $port,
        ?array $grupyCidr,
    ): array {
        $wiersze = $this->wierszeRef($oknoDni, $zrodlo, $ip, null, $kategoria, $port);
        $akceptujIp = $this->akceptujIpRef($grupyCidr);
        $poIp    = [];
        $ranga   = IncydentModel::rangaWag();
        $maxRanga = 0;
        $maxWaga  = null;
        $criticalHigh = 0;
        $liczbaIncydentow = 0;

        foreach ($wiersze as $wiersz) {
            $podmiot = (string) ($wiersz['podmiot'] ?? '');
            $z       = (string) ($wiersz['zrodlo'] ?? '');
            $waga    = (string) ($wiersz['waga'] ?? '');
            if ($podmiot === '') {
                continue;
            }
            if ($akceptujIp !== null && ! $akceptujIp($podmiot)) {
                continue;
            }
            $liczbaIncydentow++;
            if (! isset($poIp[$podmiot])) {
                $poIp[$podmiot] = [];
            }
            if ($z !== '') {
                $poIp[$podmiot][$z] = true;
            }
            $nr = $ranga[$waga] ?? 0;
            if ($nr > $maxRanga) {
                $maxRanga = $nr;
                $maxWaga  = $waga;
            }
            if ($nr >= 3) {
                $criticalHigh++;
            }
        }

        $wielozrodlowe = 0;
        foreach ($poIp as $zrodla) {
            if (count($zrodla) >= 2) {
                $wielozrodlowe++;
            }
        }

        return [
            'okno_dni'           => max(1, $oknoDni),
            'liczba_incydentow'  => $liczbaIncydentow,
            'liczba_ip'          => count($poIp),
            'ip_wielozrodlowe'   => $wielozrodlowe,
            'critical_high'      => $criticalHigh,
            'max_waga'           => $maxWaga,
        ];
    }

    /**
     * @param list<list<string>>|null $grupyCidr
     *
     * @return list<array<string, mixed>>
     */
    private function topRef(
        int $oknoDni,
        ?string $zrodlo,
        ?string $ip,
        ?string $minWaga,
        int $minZrodel,
        int $limit,
        ?array $grupyCidr,
        ?string $kategoria,
        ?int $port,
    ): array {
        $wiersze   = $this->wierszeRef($oknoDni, $zrodlo, $ip, $minWaga, $kategoria, $port);
        $akceptujIp = $this->akceptujIpRef($grupyCidr);
        $ranga     = IncydentModel::rangaWag();
        $minZrodel = max(1, $minZrodel);
        $limit     = max(1, min(1000, $limit));
        $poIp      = [];

        foreach ($wiersze as $wiersz) {
            $podmiot = (string) ($wiersz['podmiot'] ?? '');
            if ($podmiot === '') {
                continue;
            }
            if ($akceptujIp !== null && ! $akceptujIp($podmiot)) {
                continue;
            }
            if (! isset($poIp[$podmiot])) {
                $poIp[$podmiot] = [
                    'ip'                => $podmiot,
                    'liczba_incydentow' => 0,
                    'zrodla'            => [],
                    'kategorie'         => [],
                    'max_ranga'         => 0,
                    'max_waga'          => null,
                    'ostatnio_widziane' => null,
                ];
            }
            $poIp[$podmiot]['liczba_incydentow']++;
            $z = (string) ($wiersz['zrodlo'] ?? '');
            if ($z !== '') {
                $poIp[$podmiot]['zrodla'][$z] = true;
            }
            $kat = isset($wiersz['kategoria']) ? trim((string) $wiersz['kategoria']) : '';
            if ($kat !== '') {
                $poIp[$podmiot]['kategorie'][$kat] = ($poIp[$podmiot]['kategorie'][$kat] ?? 0) + 1;
            }
            $waga = (string) ($wiersz['waga'] ?? '');
            $nr   = $ranga[$waga] ?? 0;
            if ($nr > $poIp[$podmiot]['max_ranga']) {
                $poIp[$podmiot]['max_ranga'] = $nr;
                $poIp[$podmiot]['max_waga']  = $waga !== '' ? $waga : null;
            }
            $ostatnio = isset($wiersz['ostatnio_widziane']) ? (string) $wiersz['ostatnio_widziane'] : '';
            if ($ostatnio !== ''
                && ($poIp[$podmiot]['ostatnio_widziane'] === null
                    || $ostatnio > (string) $poIp[$podmiot]['ostatnio_widziane'])
            ) {
                $poIp[$podmiot]['ostatnio_widziane'] = $ostatnio;
            }
        }

        $lista = [];
        foreach ($poIp as $wiersz) {
            $zrodla = array_keys($wiersz['zrodla']);
            sort($zrodla);
            $liczbaZrodel = count($zrodla);
            if ($liczbaZrodel < $minZrodel) {
                continue;
            }
            $katCount = $wiersz['kategorie'];
            arsort($katCount, SORT_NUMERIC);
            $kategorie = array_slice(array_keys($katCount), 0, IncydentModel::BADGE_TOP_KATEGORII);
            $lista[] = [
                'ip'                => $wiersz['ip'],
                'liczba_incydentow' => $wiersz['liczba_incydentow'],
                'liczba_zrodel'     => $liczbaZrodel,
                'zrodla'            => $zrodla,
                'kategorie'         => array_values($kategorie),
                'max_waga'          => $wiersz['max_waga'],
                'ostatnio_widziane' => $wiersz['ostatnio_widziane'],
                '_max_ranga'        => $wiersz['max_ranga'],
            ];
        }

        usort($lista, static function (array $a, array $b): int {
            return [$b['liczba_zrodel'], $b['_max_ranga'], $b['liczba_incydentow'], (string) $b['ostatnio_widziane']]
                <=> [$a['liczba_zrodel'], $a['_max_ranga'], $a['liczba_incydentow'], (string) $a['ostatnio_widziane']];
        });

        $lista = array_slice($lista, 0, $limit);
        foreach ($lista as &$wiersz) {
            unset($wiersz['_max_ranga']);
        }
        unset($wiersz);

        return $lista;
    }

    /**
     * @param list<list<string>>|null $grupyCidr
     *
     * @return array<string, mixed>
     */
    private function trendyRef(
        int $oknoDni,
        ?string $zrodlo,
        ?string $ip,
        ?string $kategoria,
        ?int $port,
        ?array $grupyCidr,
    ): array {
        $oknoDni = max(1, $oknoDni);
        $od      = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify("-{$oknoDni} days")
            ->format('Y-m-d H:i:s');

        $builder = $this->db->table('mcs_obserwacje AS o')
            ->select('o.zaobserwowano_at, o.incydent_id, i.pierwsze_widziane, i.zrodlo, i.podmiot')
            ->join('mcs_incydenty AS i', 'i.id = o.incydent_id', 'inner')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.ukryty_w_ui', false)
            ->where('o.zaobserwowano_at >=', $od)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd();

        if ($zrodlo !== null && $zrodlo !== '') {
            $builder->where('i.zrodlo', $zrodlo);
        }
        if ($ip !== null && $ip !== '') {
            $builder->where('i.podmiot', $ip);
        }
        if ($kategoria !== null && $kategoria !== '') {
            $builder->where('i.kategoria', $kategoria);
        }
        if ($port !== null && $port > 0) {
            $builder->where('i.port', $port);
        }

        $wiersze = $builder->get()->getResultArray();
        if (! is_array($wiersze)) {
            $wiersze = [];
        }

        $akceptujIp = $this->akceptujIpRef($grupyCidr);

        $poDniu = [];
        $incydentDoZrodla = [];
        $incydentyWOknie = [];

        foreach ($wiersze as $wiersz) {
            $podmiot = (string) ($wiersz['podmiot'] ?? '');
            if ($podmiot === '') {
                continue;
            }
            if ($akceptujIp !== null && ! $akceptujIp($podmiot)) {
                continue;
            }
            $incydentId = (int) ($wiersz['incydent_id'] ?? 0);
            if ($incydentId <= 0) {
                continue;
            }
            $dzienObs = $this->dataUtcRef((string) ($wiersz['zaobserwowano_at'] ?? ''));
            if ($dzienObs === null) {
                continue;
            }
            $dzienPierwsze = $this->dataUtcRef((string) ($wiersz['pierwsze_widziane'] ?? ''));
            $z             = trim((string) ($wiersz['zrodlo'] ?? ''));
            $nowe          = $dzienPierwsze !== null && $dzienPierwsze === $dzienObs;

            if (! isset($poDniu[$dzienObs])) {
                $poDniu[$dzienObs] = ['ids' => [], 'noweIds' => [], 'zrodlaIds' => []];
            }
            $poDniu[$dzienObs]['ids'][$incydentId] = true;
            $incydentyWOknie[$incydentId]          = true;
            if ($nowe) {
                $poDniu[$dzienObs]['noweIds'][$incydentId] = true;
            }
            if ($z !== '') {
                $poDniu[$dzienObs]['zrodlaIds'][$z][$incydentId] = true;
                $incydentDoZrodla[$incydentId]                   = $z;
            }
        }

        $zrodlaSuma = [];
        foreach ($incydentDoZrodla as $z) {
            $zrodlaSuma[$z] = ($zrodlaSuma[$z] ?? 0) + 1;
        }

        $dzisiaj = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $dni     = [];
        $maxInc  = 0;
        for ($i = $oknoDni - 1; $i >= 0; $i--) {
            $dzien = $dzisiaj->modify("-{$i} days")->format('Y-m-d');
            $raw   = $poDniu[$dzien] ?? ['ids' => [], 'noweIds' => [], 'zrodlaIds' => []];
            $noweN = count($raw['noweIds']);
            $incN  = count($raw['ids']);
            $powtN = max(0, $incN - $noweN);
            $zrodlaDnia = [];
            foreach ($raw['zrodlaIds'] as $kod => $idSet) {
                $zrodlaDnia[$kod] = count($idSet);
            }
            ksort($zrodlaDnia);
            $maxInc = max($maxInc, $incN);
            $dni[]  = [
                'dzien'        => $dzien,
                'incydenty'    => $incN,
                'nowe'         => $noweN,
                'powtarzajace' => $powtN,
                'zrodla'       => $zrodlaDnia,
            ];
        }

        arsort($zrodlaSuma, SORT_NUMERIC);

        return [
            'okno_dni'        => $oknoDni,
            'dni'             => $dni,
            'zrodla_suma'     => $zrodlaSuma,
            'max_incydenty'   => $maxInc,
            'suma_incydentow' => count($incydentyWOknie),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wierszeRef(
        int $oknoDni,
        ?string $zrodlo,
        ?string $ip,
        ?string $minWaga,
        ?string $kategoria,
        ?int $port,
    ): array {
        $od = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-'.max(1, $oknoDni).' days')
            ->format('Y-m-d H:i:s');

        $builder = $this->db->table('mcs_incydenty AS i')
            ->select('i.podmiot, i.zrodlo, i.waga, i.kategoria, i.ostatnio_widziane')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.ukryty_w_ui', false)
            ->where('i.ostatnio_widziane >=', $od)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd();

        if ($zrodlo !== null && $zrodlo !== '') {
            $builder->where('i.zrodlo', $zrodlo);
        }
        if ($ip !== null && $ip !== '') {
            $builder->where('i.podmiot', $ip);
        }
        if ($kategoria !== null && $kategoria !== '') {
            $builder->where('i.kategoria', $kategoria);
        }
        if ($port !== null && $port > 0) {
            $builder->where('i.port', $port);
        }
        if ($minWaga !== null && $minWaga !== '') {
            $ranga = IncydentModel::rangaWag();
            $dozwolone = [];
            foreach ($ranga as $waga => $nr) {
                if ($nr >= $ranga[$minWaga]) {
                    $dozwolone[] = $waga;
                }
            }
            if ($dozwolone !== []) {
                $builder->whereIn('i.waga', $dozwolone);
            }
        }

        $wiersze = $builder->get()->getResultArray();

        return is_array($wiersze) ? $wiersze : [];
    }

    /**
     * Callable równoważny grupom CIDR (AND między grupami, OR w grupie) — jak filtrAkceptujIp.
     *
     * @param list<list<string>>|null $grupyCidr
     *
     * @return (callable(string): bool)|null
     */
    private function akceptujIpRef(?array $grupyCidr): ?callable
    {
        if ($grupyCidr === null || $grupyCidr === []) {
            return null;
        }
        $matcher = new CidrMatcher();

        return static function (string $ip) use ($grupyCidr, $matcher): bool {
            foreach ($grupyCidr as $grupa) {
                if (! $matcher->ipWLiscie($ip, $grupa)) {
                    return false;
                }
            }

            return true;
        };
    }

    private function dataUtcRef(string $timestamp): ?string
    {
        $timestamp = trim($timestamp);
        if ($timestamp === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($timestamp);

            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        } catch (Exception) {
            return null;
        }
    }

    private function wstawIncydent(
        string $zrodlo,
        string $klucz,
        string $ip,
        string $waga,
        string $ostatnio,
        string $pierwsze,
        bool $ukrytyWUi = false,
        string $kategoria = 'other',
        ?int $port = null,
    ): void {
        $this->db->table('mcs_incydenty')->insert([
            'zrodlo'            => $zrodlo,
            'klucz_dedupu'      => $klucz,
            'podmiot_typ'       => 'ip',
            'podmiot'           => $ip,
            'pierwsze_widziane' => $pierwsze,
            'ostatnio_widziane' => $ostatnio,
            'waga'              => $waga,
            'kategoria'         => $kategoria,
            'tytul'             => "Test {$klucz}",
            'port'              => $port,
            'ukryty_w_ui'       => $ukrytyWUi ? 1 : 0,
        ]);
    }
}
