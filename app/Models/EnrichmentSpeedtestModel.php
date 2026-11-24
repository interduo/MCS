<?php

namespace App\Models;

use App\Libraries\Enrichment\SpeedtestSerwis;
use App\Libraries\Siec\CidrMatcher;
use CodeIgniter\Model;

/**
 * Historia testów Speedtest — nie łączy się z mcs_incydenty.
 *
 * Agregaty (per CIDR / KPI / dzienne / anomalie) liczone w SQL (issue #448) — bez sztywnego
 * limitu 10 000 wierszy i bez agregacji w PHP. Mediana = dolna mediana jak dawne medianaInt
 * (element o randze (n+1)/2 rosnąco), przez ROW_NUMBER() … NULLS LAST.
 */
class EnrichmentSpeedtestModel extends Model
{
    protected $table            = 'mcs_enrichment_speedtest';
    protected $primaryKey       = 'test_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'test_id',
        'ip',
        'zaobserwowano_at',
        'download_kbps',
        'upload_kbps',
        'latency_ms',
        'isp',
        'platform',
        'server_name',
        'raw_json',
        'zrodlo_danych',
        'created_at',
    ];

    /** Regex IPv4 — strażnik przed cast ::inet (PostgreSQL rzuca błąd na wartościach nie-IP). */
    private const REGEX_IPV4 = '^((25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])$';

    /** Regex IPv6 (także forma skompresowana z jednym „::”). */
    private const REGEX_IPV6 = '^(([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:))$';

    private bool $udfCidrZarejestrowany    = false;
    private bool $udfCidrZIpZarejestrowany = false;

    /**
     * @return array<string, mixed>|null
     */
    public function znajdzPoTestId(string $testId): ?array
    {
        $wiersz = $this->find($testId);

        return is_array($wiersz) ? $wiersz : null;
    }

    /**
     * Upsert po PK `test_id` — atomowy INSERT … ON CONFLICT (brak wyścigu check-then-insert).
     *
     * @param array<string, mixed> $dane
     */
    public function upsertPoTestId(string $testId, array $dane): void
    {
        $this->upsertWiele([array_merge($dane, ['test_id' => $testId])]);
    }

    /**
     * Batch upsert po `test_id` (issue #448): jedno INSERT … ON CONFLICT (test_id) DO UPDATE
     * na partię 500 wierszy (multi-row VALUES) zamiast upsertu per wiersz przy imporcie.
     * Przy konflikcie PK i created_at zostają bez zmian.
     *
     * @param list<array<string, mixed>> $wiersze  każdy z kluczem test_id i polami z $allowedFields
     */
    public function upsertWiele(array $wiersze): void
    {
        if ($wiersze === []) {
            return;
        }
        // dedup po test_id (ostatni wygrywa jak przy sekwencyjnym upsert) — duplikat w jednym
        // batch INSERT … ON CONFLICT rzuciłby błąd „cannot affect row a second time”
        $poKluczu = [];
        foreach ($wiersze as $w) {
            if (isset($w['test_id'])) {
                $poKluczu[(string) $w['test_id']] = $w;
            } else {
                $poKluczu[] = $w;
            }
        }
        $wiersze = array_values($poKluczu);
        $t       = $this->db->prefixTable($this->table);
        foreach (array_chunk($wiersze, 500) as $partia) {
            // tylko pola z $allowedFields (jak insert()/update()); kolumny = unia kluczy partii
            $payloads = [];
            $kolumny  = [];
            foreach ($partia as $w) {
                $payload  = array_merge($w, ['created_at' => $w['created_at'] ?? gmdate('Y-m-d H:i:sP')]);
                $payload  = array_intersect_key($payload, array_flip($this->allowedFields));
                $payloads[] = $payload;
                foreach (array_keys($payload) as $k) {
                    $kolumny[$k] = true;
                }
            }
            $kolumny      = array_keys($kolumny);
            $aktualizacje = [];
            foreach ($kolumny as $kolumna) {
                // przy konflikcie PK i created_at zostają bez zmian
                if ($kolumna === 'test_id' || $kolumna === 'created_at') {
                    continue;
                }
                $aktualizacje[] = $kolumna . ' = EXCLUDED.' . $kolumna;
            }
            $values      = [];
            $params      = [];
            $placeholder = '(' . implode(', ', array_fill(0, count($kolumny), '?')) . ')';
            foreach ($payloads as $payload) {
                $values[] = $placeholder;
                foreach ($kolumny as $kolumna) {
                    $params[] = $payload[$kolumna] ?? null;
                }
            }
            $this->db->query(
                'INSERT INTO ' . $t . ' (' . implode(', ', $kolumny) . ') VALUES ' . implode(', ', $values)
                . ' ON CONFLICT (test_id) DO UPDATE SET ' . implode(', ', $aktualizacje),
                $params,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dlaIp(string $ip, int $limit = 20): array
    {
        $limit = max(1, $limit);
        $lista = $this->where('ip', $ip)
            ->orderBy('zaobserwowano_at', 'DESC')
            ->findAll($limit);

        return is_array($lista) ? $lista : [];
    }

    /**
     * Prefiks IPv4 /24 (np. „91.231.80.”) — zapytanie zakresowe na istniejącym indeksie ip
     * (issue #450): LIKE 'prefiks%' nie używa btree w Postgre. Zakres tekstowy
     * [prefiks, prefiks z ostatnim znakiem +1) jest równoważny LIKE 'prefiks%'.
     *
     * @return list<array<string, mixed>>
     */
    public function dlaPrefiksu24(string $prefiks24, int $limit = 50): array
    {
        $prefiks24 = trim($prefiks24);
        if (! preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.$/', $prefiks24)) {
            return [];
        }
        // „91.231.80.” → [„91.231.80.”, „91.231.80/”) — każdy IP z prefiksem mieści się w zakresie
        $gora  = substr($prefiks24, 0, -1) . chr(ord('.') + 1);
        $limit = max(1, $limit);
        $lista = $this->builder()
            ->where('ip >=', $prefiks24)
            ->where('ip <', $gora)
            ->orderBy('zaobserwowano_at', 'DESC')
            ->get($limit)
            ->getResultArray();

        return is_array($lista) ? $lista : [];
    }

    /**
     * Historia testów dokładnie w podanym CIDR (IPv6 /48 — dla IPv4 /24 jest dlaPrefiksu24).
     *
     * @return list<array<string, mixed>>
     */
    public function dlaCidr(string $cidr, int $limit = 50): array
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return [];
        }
        $limit = max(1, $limit);
        $expr  = $this->sqlCidrZIp('ip');
        $lista = $this->builder()
            ->where($expr . ' = ' . $this->db->escape($cidr), null, false)
            ->orderBy('zaobserwowano_at', 'DESC')
            ->get($limit)
            ->getResultArray();

        return is_array($lista) ? $lista : [];
    }

    /**
     * Liczba testów dokładnie w podanym CIDR (bez pobierania wierszy).
     */
    public function liczbaDlaCidr(string $cidr): int
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return 0;
        }
        $expr = $this->sqlCidrZIp('ip');

        return (int) $this->builder()
            ->where($expr . ' = ' . $this->db->escape($cidr), null, false)
            ->countAllResults();
    }

    /**
     * Wszystkie wiersze (opcjonalnie od daty ISO), sortowane malejąco po czasie.
     *
     * @return list<array<string, mixed>>
     */
    public function wszystkieOd(?string $odIso = null, int $limit = 10000): array
    {
        $limit = max(1, $limit);
        $builder = $this->builder();
        if ($odIso !== null && $odIso !== '') {
            $builder->where('zaobserwowano_at >=', $odIso);
        }
        $lista = $builder->orderBy('zaobserwowano_at', 'DESC')->get($limit)->getResultArray();

        return is_array($lista) ? $lista : [];
    }

    /**
     * Agregaty per CIDR (/24 IPv4, /48 IPv6) w SQL (issue #448): GROUP BY cidr + AVG/MAX,
     * mediana przez ROW_NUMBER (dolna mediana = element (n+1)/2 rosnąco, jak dawne medianaInt).
     * Bez limitu wierszy — filtr daty i AS w WHERE.
     *
     * @param list<list<string>>|null $grupyCidr  filtr AS: AND między grupami, OR w grupie; null = bez filtra; pusta grupa = nic nie pasuje
     *
     * @return list<array<string, mixed>>
     */
    public function agregatyPerCidr(?string $odIso = null, ?array $grupyCidr = null, string $cidrContains = ''): array
    {
        $t      = $this->db->prefixTable($this->table);
        $cidr   = $this->sqlCidrZIp('ip');
        $params = [];
        $where  = $this->sqlWhere($odIso, $grupyCidr, $cidrContains, $params);
        $sql    = "SELECT cidr,
                COUNT(*) AS liczba_testow,
                AVG(download_kbps) AS avg_download_kbps,
                MAX(download_kbps) AS max_download_kbps,
                AVG(upload_kbps) AS avg_upload_kbps,
                MAX(upload_kbps) AS max_upload_kbps,
                AVG(latency_ms) AS avg_latency_ms,
                MAX(zaobserwowano_at) AS ostatni_test_at,
                MAX(CASE WHEN rn_d = (cnt_d + 1) / 2 THEN download_kbps END) AS p50_download_kbps,
                MAX(CASE WHEN rn_u = (cnt_u + 1) / 2 THEN upload_kbps END) AS p50_upload_kbps,
                MAX(CASE WHEN rn_l = (cnt_l + 1) / 2 THEN latency_ms END) AS p50_latency_ms
            FROM (
                SELECT {$cidr} AS cidr, download_kbps, upload_kbps, latency_ms, zaobserwowano_at,
                    ROW_NUMBER() OVER (PARTITION BY {$cidr} ORDER BY download_kbps NULLS LAST) AS rn_d,
                    COUNT(download_kbps) OVER (PARTITION BY {$cidr}) AS cnt_d,
                    ROW_NUMBER() OVER (PARTITION BY {$cidr} ORDER BY upload_kbps NULLS LAST) AS rn_u,
                    COUNT(upload_kbps) OVER (PARTITION BY {$cidr}) AS cnt_u,
                    ROW_NUMBER() OVER (PARTITION BY {$cidr} ORDER BY latency_ms NULLS LAST) AS rn_l,
                    COUNT(latency_ms) OVER (PARTITION BY {$cidr}) AS cnt_l
                FROM {$t}{$where}
            ) AS pod
            WHERE cidr IS NOT NULL
            GROUP BY cidr";

        $wynik = $this->db->query($sql, $params)->getResultArray();

        return is_array($wynik) ? $wynik : [];
    }

    /**
     * KPI globalne w oknie w SQL (issue #448). liczba_testow liczy wszystkie wiersze filtra,
     * liczba_cidr — odrębne niepuste CIDR (jak dawna agregacja w PHP).
     *
     * @param list<list<string>>|null $grupyCidr
     *
     * @return array<string, mixed>
     */
    public function kpiWOkie(?string $odIso = null, ?array $grupyCidr = null, string $cidrContains = ''): array
    {
        $t      = $this->db->prefixTable($this->table);
        $cidr   = $this->sqlCidrZIp('ip');
        $params = [];
        $where  = $this->sqlWhere($odIso, $grupyCidr, $cidrContains, $params);
        $sql    = "SELECT COUNT(*) AS liczba_testow,
                COUNT(DISTINCT cidr) AS liczba_cidr,
                AVG(download_kbps) AS avg_download_kbps,
                AVG(upload_kbps) AS avg_upload_kbps,
                AVG(latency_ms) AS avg_latency_ms,
                MAX(CASE WHEN rn_d = (cnt_d + 1) / 2 THEN download_kbps END) AS p50_download_kbps,
                MAX(CASE WHEN rn_u = (cnt_u + 1) / 2 THEN upload_kbps END) AS p50_upload_kbps,
                MAX(CASE WHEN rn_l = (cnt_l + 1) / 2 THEN latency_ms END) AS p50_latency_ms
            FROM (
                SELECT {$cidr} AS cidr, download_kbps, upload_kbps, latency_ms,
                    ROW_NUMBER() OVER (ORDER BY download_kbps NULLS LAST) AS rn_d,
                    COUNT(download_kbps) OVER () AS cnt_d,
                    ROW_NUMBER() OVER (ORDER BY upload_kbps NULLS LAST) AS rn_u,
                    COUNT(upload_kbps) OVER () AS cnt_u,
                    ROW_NUMBER() OVER (ORDER BY latency_ms NULLS LAST) AS rn_l,
                    COUNT(latency_ms) OVER () AS cnt_l
                FROM {$t}{$where}
            ) AS pod";

        $row = $this->db->query($sql, $params)->getRowArray();

        return is_array($row) ? $row : [];
    }

    /**
     * Agregaty dzienne w SQL (issue #448), najnowszy dzień pierwszy (jak dawne krsort w PHP).
     *
     * @param list<list<string>>|null $grupyCidr
     *
     * @return list<array<string, mixed>>
     */
    public function agregatyDzienneSql(?string $odIso = null, ?array $grupyCidr = null, string $cidrContains = ''): array
    {
        $t      = $this->db->prefixTable($this->table);
        $dzien  = $this->sqlDzien('zaobserwowano_at');
        $params = [];
        $where  = $this->sqlWhere($odIso, $grupyCidr, $cidrContains, $params);
        $sql    = "SELECT dzien,
                COUNT(*) AS liczba_testow,
                AVG(download_kbps) AS avg_download_kbps,
                AVG(upload_kbps) AS avg_upload_kbps,
                AVG(latency_ms) AS avg_latency_ms
            FROM (
                SELECT {$dzien} AS dzien, download_kbps, upload_kbps, latency_ms
                FROM {$t}{$where}
            ) AS pod
            WHERE dzien IS NOT NULL AND dzien <> ''
            GROUP BY dzien
            ORDER BY dzien DESC";

        $wynik = $this->db->query($sql, $params)->getResultArray();

        return is_array($wynik) ? $wynik : [];
    }

    /**
     * Statystyki per CIDR dla anomalii w SQL (issue #448): liczba_powyzej = liczba testów
     * spełniających którykolwiek próg Mb/s (OR, jak czyPowyzejProgu; oba progi 0 = nigdy).
     *
     * @param list<list<string>>|null $grupyCidr
     *
     * @return list<array<string, mixed>>
     */
    public function anomaliePerCidr(?string $odIso = null, ?array $grupyCidr = null, string $cidrContains = '', int $minDownKbps = 0, int $minUpKbps = 0): array
    {
        $t      = $this->db->prefixTable($this->table);
        $cidr   = $this->sqlCidrZIp('ip');
        // kolejność parametrów = kolejność ? w tekście SQL: najpierw progi (SELECT zewnętrzny), potem WHERE podzapytania
        $paramsPowyzej = [];
        $powyzej = $this->sqlPowyzejProgu($minDownKbps, $minUpKbps, $paramsPowyzej);
        $paramsWhere = [];
        $where  = $this->sqlWhere($odIso, $grupyCidr, $cidrContains, $paramsWhere);
        $params = array_merge($paramsPowyzej, $paramsWhere);
        $sql    = "SELECT cidr,
                COUNT(*) AS liczba_testow,
                MAX(download_kbps) AS max_download_kbps,
                MAX(upload_kbps) AS max_upload_kbps,
                AVG(download_kbps) AS avg_download_kbps,
                AVG(upload_kbps) AS avg_upload_kbps,
                MAX(zaobserwowano_at) AS ostatni_test_at,
                SUM(CASE WHEN {$powyzej} THEN 1 ELSE 0 END) AS liczba_powyzej
            FROM (
                SELECT {$cidr} AS cidr, download_kbps, upload_kbps, zaobserwowano_at
                FROM {$t}{$where}
            ) AS pod
            WHERE cidr IS NOT NULL
            GROUP BY cidr";

        $wynik = $this->db->query($sql, $params)->getResultArray();

        return is_array($wynik) ? $wynik : [];
    }

    /**
     * Top-N surowych testów w SQL (issue #448): filtr progów tylko gdy nie „wszystkie”,
     * sort jak sortujTesty (COALESCE null → 0, potem zaobserwowano_at DESC).
     *
     * @param list<list<string>>|null $grupyCidr
     *
     * @return list<array<string, mixed>>
     */
    public function topTestySql(?string $odIso = null, ?array $grupyCidr = null, string $cidrContains = '', int $minDownKbps = 0, int $minUpKbps = 0, bool $wszystkie = false, string $sort = 'download', int $limit = 20): array
    {
        $t       = $this->db->prefixTable($this->table);
        $cidr    = $this->sqlCidrZIp('ip');
        $params  = [];
        $where   = $this->sqlWhere($odIso, $grupyCidr, $cidrContains, $params);
        $extra   = '';
        if (! $wszystkie) {
            $extra = ' AND ' . $this->sqlPowyzejProgu($minDownKbps, $minUpKbps, $params);
        }
        $orderBy = match ($sort) {
            'upload' => 'COALESCE(upload_kbps, 0) DESC, zaobserwowano_at DESC',
            'data'   => 'zaobserwowano_at DESC, COALESCE(download_kbps, 0) DESC',
            default  => 'COALESCE(download_kbps, 0) DESC, zaobserwowano_at DESC',
        };
        $limit  = max(1, (int) $limit);
        $sql    = "SELECT test_id, cidr, download_kbps, upload_kbps, latency_ms, zaobserwowano_at
            FROM (
                SELECT {$cidr} AS cidr, test_id, download_kbps, upload_kbps, latency_ms, zaobserwowano_at
                FROM {$t}{$where}
            ) AS pod
            WHERE cidr IS NOT NULL{$extra}
            ORDER BY {$orderBy}
            LIMIT ?";
        $params[] = $limit;

        $wynik = $this->db->query($sql, $params)->getResultArray();

        return is_array($wynik) ? $wynik : [];
    }

    /**
     * Warunek SQL „powyżej progu” (odpowiednik czyPowyzejProgu): OR progów > 0; oba 0 = nigdy.
     *
     * @param list<mixed> $params
     */
    private function sqlPowyzejProgu(int $minDownKbps, int $minUpKbps, array &$params): string
    {
        $czesci = [];
        if ($minDownKbps > 0) {
            $czesci[] = 'download_kbps >= ?';
            $params[] = $minDownKbps;
        }
        if ($minUpKbps > 0) {
            $czesci[] = 'upload_kbps >= ?';
            $params[] = $minUpKbps;
        }
        if ($czesci === []) {
            return '0 = 1';
        }

        return '(' . implode(' OR ', $czesci) . ')';
    }

    /**
     * Wspólny WHERE dla agregatów: data, filtr AS (grupy CIDR) i „zawiera” CIDR/IP.
     *
     * @param list<list<string>>|null $grupyCidr
     * @param list<mixed>             $params
     */
    private function sqlWhere(?string $odIso, ?array $grupyCidr, string $cidrContains, array &$params): string
    {
        $where = [];
        if ($odIso !== null && $odIso !== '') {
            $where[]  = 'zaobserwowano_at >= ?';
            $params[] = $odIso;
        }
        if ($grupyCidr !== null) {
            $grupy = [];
            foreach ($grupyCidr as $grupa) {
                $cidry = [];
                foreach ((array) $grupa as $c) {
                    if (is_string($c) && trim($c) !== '') {
                        $cidry[] = trim($c);
                    }
                }
                if ($cidry === []) {
                    // pusta grupa (np. AS wyłączone przy tylko_as) — nic nie pasuje
                    $grupy[] = '1 = 0';
                    continue;
                }
                $or = [];
                foreach ($cidry as $c) {
                    $or[] = $this->sqlIpWCidr('ip', $c);
                }
                $grupy[] = '(' . implode(' OR ', $or) . ')';
            }
            if ($grupy !== []) {
                $where[] = '(' . implode(' AND ', $grupy) . ')';
            }
        }
        $cidrContains = trim($cidrContains);
        if ($cidrContains !== '') {
            $cidr    = $this->sqlCidrZIp('ip');
            $where[] = '(' . $cidr . ' LIKE ? OR ip LIKE ?)';
            $params[] = '%' . $cidrContains . '%';
            $params[] = '%' . $cidrContains . '%';
        }

        return $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    }

    /**
     * Wyrażenie SQL CIDR z IP (IPv4 → /24, IPv6 → /48) — odpowiednik SpeedtestSerwis::cidrZIp.
     * PostgreSQL: natywne split_part/set_masklen; SQLite (testy): UDF z logiką cidrZIp.
     */
    private function sqlCidrZIp(string $kolumna): string
    {
        if ($this->db->getPlatform() === 'Postgre') {
            return "CASE WHEN {$kolumna} ~ '" . self::REGEX_IPV4 . "' THEN "
                . "split_part({$kolumna}, '.', 1) || '.' || split_part({$kolumna}, '.', 2) || '.' || split_part({$kolumna}, '.', 3) || '.0/24'"
                . " WHEN {$kolumna} ~ '" . self::REGEX_IPV6 . "' THEN lower(host(set_masklen({$kolumna}::inet, 48))) || '/48'"
                . ' ELSE NULL END';
        }
        $this->zarejestrujUdfCidrZIp();

        return "mcs_cidr_z_ip({$kolumna})";
    }

    /**
     * Wyrażenie SQL „adres z kolumny należy do CIDR” (jak IncydentModel::sqlIpWCidr).
     * PostgreSQL: natywne operatory inet/cidr; SQLite (testy): UDF z logiką CidrMatcher.
     */
    private function sqlIpWCidr(string $kolumna, string $cidr): string
    {
        $esc = $this->db->escape($cidr);
        if ($this->db->getPlatform() === 'Postgre') {
            // Strażnik regex: cast ::inet na śmieciach kończy się błędem PG; CidrMatcher takie IP odrzucał.
            return "CASE WHEN {$kolumna} ~ '" . self::REGEX_IPV4 . "' OR {$kolumna} ~ '" . self::REGEX_IPV6 . "'"
                . " THEN {$kolumna}::inet <<= {$esc}::cidr ELSE FALSE END";
        }
        $this->zarejestrujUdfCidr();

        return "mcs_ip_w_cidr({$kolumna}, {$esc})";
    }

    /**
     * Wyrażenie SQL dnia z kolumny timestamp (jak ObserwacjaModel::sqlDzien).
     */
    private function sqlDzien(string $kolumna): string
    {
        if ($this->db->getPlatform() === 'Postgre') {
            return "date_trunc('day', {$kolumna})::date";
        }

        return "substr(CAST({$kolumna} AS TEXT), 1, 10)";
    }

    /**
     * SQLite (tylko testy jednostkowe) nie zna operatorów inet — rejestrujemy UDF z logiką CidrMatcher.
     */
    private function zarejestrujUdfCidr(): void
    {
        if ($this->udfCidrZarejestrowany || $this->db->getPlatform() !== 'SQLite3') {
            return;
        }
        $conn = $this->db->connID;
        if ($conn instanceof \SQLite3) {
            $matcher = new CidrMatcher();
            $conn->createFunction(
                'mcs_ip_w_cidr',
                static fn ($ip, $cidr): bool => is_string($ip) && is_string($cidr) && $matcher->ipWCidr($ip, $cidr),
                2,
            );
        }
        $this->udfCidrZarejestrowany = true;
    }

    /**
     * SQLite (tylko testy) nie zna wyrażenia CIDR z IP — UDF z logiką SpeedtestSerwis::cidrZIp.
     */
    private function zarejestrujUdfCidrZIp(): void
    {
        if ($this->udfCidrZIpZarejestrowany || $this->db->getPlatform() !== 'SQLite3') {
            return;
        }
        $conn = $this->db->connID;
        if ($conn instanceof \SQLite3) {
            $conn->createFunction(
                'mcs_cidr_z_ip',
                static fn ($ip): ?string => is_string($ip) ? SpeedtestSerwis::cidrZIp($ip) : null,
                1,
            );
        }
        $this->udfCidrZIpZarejestrowany = true;
    }
}
