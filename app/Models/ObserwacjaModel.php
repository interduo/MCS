<?php

namespace App\Models;

use App\Libraries\Siec\CidrMatcher;
use CodeIgniter\Model;

class ObserwacjaModel extends Model
{
    protected $table            = 'mcs_obserwacje';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'incydent_id',
        'import_id',
        'zaobserwowano_at',
        'payload_json',
        'id_w_zrodle',
    ];
    protected $useTimestamps = false;

    /**
     * Timeline obserwacji dla jednego incydentu (najnowsze pierwsze).
     *
     * @return list<array<string, mixed>>
     */
    public function dlaIncydentu(int $incydentId): array
    {
        if ($incydentId <= 0) {
            return [];
        }

        $wiersze = $this->where('incydent_id', $incydentId)
            ->orderBy('zaobserwowano_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll();

        if (! is_array($wiersze)) {
            return [];
        }

        $lista = [];
        foreach ($wiersze as $wiersz) {
            $payload = $wiersz['payload_json'] ?? null;
            if (is_string($payload) && $payload !== '') {
                $decoded = json_decode($payload, true);
                $wiersz['payload'] = is_array($decoded) ? $decoded : null;
            } else {
                $wiersz['payload'] = null;
            }
            $lista[] = $wiersz;
        }

        return $lista;
    }

    /**
     * Trendy dzienne: unikalne widoczne incydenty aktywne w dniu (po obserwacjach).
     *
     * „Nowe” = dzień pierwszego widzenia; „powtarzające” = aktywne, znane wcześniej.
     *
     * Agregacja w SQL: GROUP BY dzień (date_trunc) z COUNT(DISTINCT incydent_id) i FILTER —
     * bez pobierania wierszy obserwacji do PHP (issue #441).
     *
     * @param list<list<string>>|null $grupyCidr  Grupy CIDR — AND między grupami, OR w grupie (filtr AS/CIDR jako warunek SQL).
     *
     * @return array{
     *   okno_dni: int,
     *   dni: list<array{
     *     dzien: string,
     *     incydenty: int,
     *     nowe: int,
     *     powtarzajace: int,
     *     zrodla: array<string, int>
     *   }>,
     *   zrodla_suma: array<string, int>,
     *   max_incydenty: int,
     *   suma_incydentow: int
     * }
     */
    public function trendyDzienne(
        int $oknoDni = 7,
        ?string $zrodlo = null,
        ?string $ip = null,
        ?string $kategoria = null,
        ?int $port = null,
        ?array $grupyCidr = null,
    ): array {
        $oknoDni = max(1, $oknoDni);
        $od      = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$oknoDni} days")
            ->format('Y-m-d H:i:s');

        $dzienObs      = $this->sqlDzien('o.zaobserwowano_at');
        $dzienPierwsze = $this->sqlDzien('i.pierwsze_widziane');

        // Per dzień: unikalne incydenty aktywne + „nowe” (pierwsze_widziane w tym samym dniu).
        $poDniu = [];
        $wiersze = $this->builderTrendyBaza($od, $zrodlo, $ip, $kategoria, $port, $grupyCidr)
            ->select(
                "{$dzienObs} AS dzien, COUNT(DISTINCT o.incydent_id) AS incydenty, "
                . "COUNT(DISTINCT o.incydent_id) FILTER (WHERE {$dzienPierwsze} = {$dzienObs}) AS nowe",
                false,
            )
            ->groupBy('1', false)
            ->get()
            ->getResultArray();
        if (is_array($wiersze)) {
            foreach ($wiersze as $w) {
                $dzien = substr((string) ($w['dzien'] ?? ''), 0, 10);
                if ($dzien === '') {
                    continue;
                }
                $poDniu[$dzien] = [
                    'incydenty' => (int) ($w['incydenty'] ?? 0),
                    'nowe'      => (int) ($w['nowe'] ?? 0),
                    'zrodla'    => [],
                ];
            }
        }

        // Per dzień i źródło: unikalne incydenty.
        $wiersze = $this->builderTrendyBaza($od, $zrodlo, $ip, $kategoria, $port, $grupyCidr)
            ->select("{$dzienObs} AS dzien, i.zrodlo, COUNT(DISTINCT o.incydent_id) AS liczba", false)
            ->where('i.zrodlo !=', '')
            ->groupBy('1, 2', false)
            ->get()
            ->getResultArray();
        if (is_array($wiersze)) {
            foreach ($wiersze as $w) {
                $dzien = substr((string) ($w['dzien'] ?? ''), 0, 10);
                $z     = trim((string) ($w['zrodlo'] ?? ''));
                if ($dzien === '' || $z === '') {
                    continue;
                }
                $poDniu[$dzien]['zrodla'][$z] = (int) ($w['liczba'] ?? 0);
            }
        }

        // Suma per źródło w całym oknie (unikalne incydenty).
        $zrodlaSuma = [];
        $wiersze    = $this->builderTrendyBaza($od, $zrodlo, $ip, $kategoria, $port, $grupyCidr)
            ->select('i.zrodlo, COUNT(DISTINCT o.incydent_id) AS liczba', false)
            ->where('i.zrodlo !=', '')
            ->groupBy('i.zrodlo')
            ->get()
            ->getResultArray();
        if (is_array($wiersze)) {
            foreach ($wiersze as $w) {
                $z = trim((string) ($w['zrodlo'] ?? ''));
                if ($z !== '') {
                    $zrodlaSuma[$z] = (int) ($w['liczba'] ?? 0);
                }
            }
        }

        // Łącznie unikalnych incydentów w oknie.
        $wiersz = $this->builderTrendyBaza($od, $zrodlo, $ip, $kategoria, $port, $grupyCidr)
            ->select('COUNT(DISTINCT o.incydent_id) AS n', false)
            ->get()
            ->getRowArray();
        $sumaIncydentow = is_array($wiersz) ? (int) ($wiersz['n'] ?? 0) : 0;

        // Wypełnij ciągłe okno (UTC), także dni bez obserwacji — bez pustego „vanity chart”.
        $dzisiaj = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $dni     = [];
        $maxInc  = 0;
        for ($i = $oknoDni - 1; $i >= 0; $i--) {
            $dzien = $dzisiaj->modify("-{$i} days")->format('Y-m-d');
            $raw   = $poDniu[$dzien] ?? ['incydenty' => 0, 'nowe' => 0, 'zrodla' => []];
            $incN  = $raw['incydenty'];
            $noweN = $raw['nowe'];
            $powtN = max(0, $incN - $noweN);
            $zrodlaDnia = $raw['zrodla'];
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
            'suma_incydentow' => $sumaIncydentow,
        ];
    }

    /**
     * Builder bazowy dla trendów: obserwacje w oknie + join do widocznych incydentów IP + filtry.
     *
     * @param list<list<string>>|null $grupyCidr
     *
     * @return object Query Builder (świeża instancja per wywołanie)
     */
    private function builderTrendyBaza(
        string $od,
        ?string $zrodlo,
        ?string $ip,
        ?string $kategoria,
        ?int $port,
        ?array $grupyCidr,
    ) {
        $builder = $this->db->table($this->table . ' AS o')
            ->join('mcs_incydenty AS i', 'i.id = o.incydent_id', 'inner')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.podmiot !=', '')
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
        $this->dolaczFiltrCidrSql($builder, 'i.podmiot', $grupyCidr);

        return $builder;
    }

    /**
     * Wyrażenie SQL dnia (UTC) z kolumny timestamp.
     * PostgreSQL: date_trunc('day', …); SQLite (testy): substr tekstu (YYYY-MM-DD…).
     */
    private function sqlDzien(string $kolumna): string
    {
        if ($this->db->getPlatform() === 'Postgre') {
            return "date_trunc('day', {$kolumna})::date";
        }

        return "substr(CAST({$kolumna} AS TEXT), 1, 10)";
    }

    /** Regex IPv4 — strażnik przed cast ::inet (PostgreSQL rzuca błąd na wartościach nie-IP). */
    private const REGEX_IPV4 = '^((25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])$';

    /** Regex IPv6 (także forma skompresowana z jednym „::”). */
    private const REGEX_IPV6 = '^(([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:))$';

    private bool $udfCidrZarejestrowany = false;

    /**
     * Filtr AS/CIDR jako warunek SQL (ip <<= cidr), nie callable per wiersz (issue #440/#441).
     *
     * @param object                  $builder Query Builder
     * @param list<list<string>>|null $grupyCidr  AND między grupami, OR w grupie.
     */
    private function dolaczFiltrCidrSql($builder, string $kolumna, ?array $grupyCidr): void
    {
        if ($grupyCidr === null || $grupyCidr === []) {
            return;
        }
        foreach ($grupyCidr as $grupa) {
            if (! is_array($grupa)) {
                continue;
            }
            $cidry = [];
            foreach ($grupa as $cidr) {
                if (is_string($cidr) && trim($cidr) !== '') {
                    $cidry[] = trim($cidr);
                }
            }
            if ($cidry === []) {
                continue;
            }
            $builder->groupStart();
            foreach ($cidry as $cidr) {
                $builder->orWhere($this->sqlIpWCidr($kolumna, $cidr), null, false);
            }
            $builder->groupEnd();
        }
    }

    /**
     * Wyrażenie SQL „adres z kolumny należy do CIDR”.
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
}
