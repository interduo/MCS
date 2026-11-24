<?php

namespace App\Models;

use App\Libraries\Siec\CidrMatcher;
use CodeIgniter\Model;

class IncydentModel extends Model
{
    protected $table            = 'mcs_incydenty';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'zrodlo',
        'klucz_dedupu',
        'podmiot_typ',
        'podmiot',
        'pierwsze_widziane',
        'ostatnio_widziane',
        'waga',
        'wersja_wagi',
        'kategoria',
        'tytul',
        'port',
        'protokol',
        'ukryty_w_ui',
    ];
    protected $useTimestamps = false;

    /**
     * Lista skrótów incydentów dla podmiotu IP (strona /ip/{ip}).
     *
     * Pomija źródła z mcs_pluginy.ukryty_w_ui oraz własne ukryty_w_ui incydentu.
     * Sort: waga (critical→low), potem ostatnio_widziane malejąco.
     *
     * @return list<array<string, mixed>>
     */
    public function dlaPodmiotuIp(string $ip, ?string $zrodlo = null): array
    {
        $builder = $this->db->table($this->table . ' AS i')
            ->select('i.*')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.podmiot', $ip)
            ->where('i.ukryty_w_ui', false)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd();

        if ($zrodlo !== null && $zrodlo !== '') {
            $builder->where('i.zrodlo', $zrodlo);
        }

        $builder->orderBy(
            "CASE i.waga
                WHEN 'critical' THEN 4
                WHEN 'high' THEN 3
                WHEN 'medium' THEN 2
                WHEN 'low' THEN 1
                ELSE 0
            END",
            'DESC',
            false,
        );
        $builder->orderBy('i.ostatnio_widziane', 'DESC');

        $wiersze = $builder->get()->getResultArray();

        return is_array($wiersze) ? $wiersze : [];
    }

    /**
     * IP z listy, które pierwszy raz pojawiły się w MCS w ostatnich $dni dniach.
     *
     * Data = najwcześniejsze pierwsze_widziane po wszystkich źródłach (bez filtra ukrycia).
     *
     * @param list<string> $ips
     *
     * @return array<string, string> ip => najwcześniejsze pierwsze_widziane
     */
    public function ipNowe(array $ips, int $dni): array
    {
        $ips = array_values(array_unique(array_filter($ips, static fn (string $ip): bool => $ip !== '')));
        if ($ips === []) {
            return [];
        }

        $wynik = [];
        foreach (array_chunk($ips, 500) as $paczka) {
            $wiersze = $this->db->table($this->table)
                ->select('podmiot, MIN(pierwsze_widziane) AS pierwsze', false)
                ->where('podmiot_typ', 'ip')
                ->whereIn('podmiot', $paczka)
                ->groupBy('podmiot')
                ->having('MIN(pierwsze_widziane) >= ' . $this->db->escape($this->odDatyOkna($dni)), null, false)
                ->get()
                ->getResultArray();

            foreach ($wiersze as $w) {
                $wynik[(string) $w['podmiot']] = (string) $w['pierwsze'];
            }
        }

        return $wynik;
    }

    /**
     * Distinct źródła widoczne dla IP (bez filtra zrodlo) — do listy filtrów UI.
     *
     * @return list<string>
     */
    public function zrodlaDlaPodmiotuIp(string $ip): array
    {
        $builder = $this->db->table($this->table . ' AS i')
            ->select('i.zrodlo')
            ->distinct()
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.podmiot', $ip)
            ->where('i.ukryty_w_ui', false)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd()
            ->orderBy('i.zrodlo', 'ASC');

        $wiersze = $builder->get()->getResultArray();
        $lista   = [];

        foreach ($wiersze as $wiersz) {
            if (isset($wiersz['zrodlo']) && is_string($wiersz['zrodlo'])) {
                $lista[] = $wiersz['zrodlo'];
            }
        }

        return $lista;
    }

    /** Limit kategorii w odpowiedzi badge (najczęstsze pierwsze). */
    public const BADGE_TOP_KATEGORII = 5;

    /** Limit przykładowych tytułów w odpowiedzi badge. */
    public const BADGE_PRZYKLADOW = 3;

    /**
     * Agregat pod API badge: okno ostatnio_widziane, bez źródeł/incydentów ukrytych w UI.
     *
     * @return array{
     *   okno_dni: int,
     *   liczba: int,
     *   max_waga: ?string,
     *   liczba_zrodel: int,
     *   zrodla: list<string>,
     *   kategorie: list<string>,
     *   przyklady: list<string>
     * }
     */
    public function agregatBadgeDlaIp(string $ip, int $oknoDni = 7): array
    {
        $mapa = $this->agregatBadgeDlaIpLista([$ip], $oknoDni);

        return $mapa[$ip] ?? $this->pustyAgregatBadge(max(1, $oknoDni));
    }

    /**
     * Batch agregat badge (jedno whereIn) — klucz = IP z listy wejściowej (kolejność zachowana).
     * IP bez wierszy w oknie dostają zerowy agregat.
     *
     * @param list<string> $ips
     * @return array<string, array{
     *   okno_dni: int,
     *   liczba: int,
     *   max_waga: ?string,
     *   liczba_zrodel: int,
     *   zrodla: list<string>,
     *   kategorie: list<string>,
     *   przyklady: list<string>
     * }>
     */
    public function agregatBadgeDlaIpLista(array $ips, int $oknoDni = 7): array
    {
        $oknoDni = max(1, $oknoDni);
        $puste   = $this->pustyAgregatBadge($oknoDni);

        $unikalne = [];
        foreach ($ips as $ip) {
            if (! is_string($ip)) {
                continue;
            }
            $ip = trim($ip);
            if ($ip === '' || isset($unikalne[$ip])) {
                continue;
            }
            $unikalne[$ip] = $puste;
        }

        if ($unikalne === []) {
            return [];
        }

        $od = $this->odDatyOkna($oknoDni);

        $builder = $this->db->table($this->table . ' AS i')
            ->select('i.podmiot, i.waga, i.zrodlo, i.kategoria, i.tytul')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->whereIn('i.podmiot', array_keys($unikalne))
            ->where('i.ukryty_w_ui', false)
            ->where('i.ostatnio_widziane >=', $od)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd();

        $wiersze = $builder->get()->getResultArray();
        if (! is_array($wiersze)) {
            $wiersze = [];
        }

        $ranga = self::rangaWag();
        /**
         * @var array<string, array{
         *   liczba: int,
         *   max_ranga: int,
         *   max_waga: ?string,
         *   zrodla: array<string, true>,
         *   kategorie: array<string, int>,
         *   przyklady_kand: list<array{0: int, 1: string}>
         * }> $agg
         */
        $agg = [];

        foreach ($wiersze as $wiersz) {
            $podmiot = isset($wiersz['podmiot']) && is_string($wiersz['podmiot']) ? $wiersz['podmiot'] : '';
            if ($podmiot === '' || ! isset($unikalne[$podmiot])) {
                continue;
            }
            if (! isset($agg[$podmiot])) {
                $agg[$podmiot] = [
                    'liczba'          => 0,
                    'max_ranga'       => 0,
                    'max_waga'        => null,
                    'zrodla'          => [],
                    'kategorie'       => [],
                    'przyklady_kand'  => [],
                ];
            }
            $agg[$podmiot]['liczba']++;
            $waga = isset($wiersz['waga']) && is_string($wiersz['waga']) ? $wiersz['waga'] : '';
            $nr   = $ranga[$waga] ?? 0;
            if ($nr > $agg[$podmiot]['max_ranga']) {
                $agg[$podmiot]['max_ranga'] = $nr;
                $agg[$podmiot]['max_waga']  = $waga !== '' ? $waga : null;
            }
            if (isset($wiersz['zrodlo']) && is_string($wiersz['zrodlo']) && $wiersz['zrodlo'] !== '') {
                $agg[$podmiot]['zrodla'][$wiersz['zrodlo']] = true;
            }
            $kat = isset($wiersz['kategoria']) && is_string($wiersz['kategoria'])
                ? trim($wiersz['kategoria'])
                : '';
            if ($kat !== '') {
                $agg[$podmiot]['kategorie'][$kat] = ($agg[$podmiot]['kategorie'][$kat] ?? 0) + 1;
            }
            $tytul = isset($wiersz['tytul']) && is_string($wiersz['tytul'])
                ? trim($wiersz['tytul'])
                : '';
            if ($tytul !== '' && count($agg[$podmiot]['przyklady_kand']) < 40) {
                $agg[$podmiot]['przyklady_kand'][] = [$nr, $tytul];
            }
        }

        foreach ($agg as $ip => $dane) {
            $zrodlaLista = array_keys($dane['zrodla']);
            sort($zrodlaLista, SORT_STRING);

            $katCount = $dane['kategorie'];
            arsort($katCount, SORT_NUMERIC);
            $kategorieLista = array_slice(array_keys($katCount), 0, self::BADGE_TOP_KATEGORII);

            $kandydaci = $dane['przyklady_kand'];
            usort(
                $kandydaci,
                static fn (array $a, array $b): int => $b[0] <=> $a[0],
            );
            $przyklady = [];
            $widziane  = [];
            foreach ($kandydaci as $kandydat) {
                $tytul = $kandydat[1];
                if (isset($widziane[$tytul])) {
                    continue;
                }
                $widziane[$tytul] = true;
                $przyklady[]     = $tytul;
                if (count($przyklady) >= self::BADGE_PRZYKLADOW) {
                    break;
                }
            }

            $unikalne[$ip] = [
                'okno_dni'      => $oknoDni,
                'liczba'        => $dane['liczba'],
                'max_waga'      => $dane['max_waga'],
                'liczba_zrodel' => count($zrodlaLista),
                'zrodla'        => array_values($zrodlaLista),
                'kategorie'     => array_values($kategorieLista),
                'przyklady'     => $przyklady,
            ];
        }

        return $unikalne;
    }

    /**
     * @return array{
     *   okno_dni: int,
     *   liczba: int,
     *   max_waga: ?string,
     *   liczba_zrodel: int,
     *   zrodla: list<string>,
     *   kategorie: list<string>,
     *   przyklady: list<string>
     * }
     */
    private function pustyAgregatBadge(int $oknoDni): array
    {
        return [
            'okno_dni'      => $oknoDni,
            'liczba'        => 0,
            'max_waga'      => null,
            'liczba_zrodel' => 0,
            'zrodla'        => [],
            'kategorie'     => [],
            'przyklady'     => [],
        ];
    }

    /**
     * Widoczne źródła obecne w indeksie (do filtrów dashboardu).
     *
     * @return list<string>
     */
    public function widoczneZrodla(): array
    {
        $builder = $this->builderWidocznych()
            ->select('i.zrodlo')
            ->distinct()
            ->orderBy('i.zrodlo', 'ASC');

        $wiersze = $builder->get()->getResultArray();
        $lista   = [];

        foreach ($wiersze as $wiersz) {
            if (isset($wiersz['zrodlo']) && is_string($wiersz['zrodlo']) && $wiersz['zrodlo'] !== '') {
                $lista[] = $wiersz['zrodlo'];
            }
        }

        return $lista;
    }

    /**
     * Distinct kategorie widoczne w indeksie (do filtrów dashboardu).
     *
     * @return list<string>
     */
    public function widoczneKategorie(): array
    {
        $builder = $this->builderWidocznych()
            ->select('i.kategoria')
            ->distinct()
            ->orderBy('i.kategoria', 'ASC');

        $wiersze = $builder->get()->getResultArray();
        $lista   = [];

        foreach ($wiersze as $wiersz) {
            $kat = isset($wiersz['kategoria']) ? trim((string) $wiersz['kategoria']) : '';
            if ($kat !== '') {
                $lista[] = $kat;
            }
        }

        return $lista;
    }

    /**
     * Oznacza pojedynczy incydent jako ukryty w UI (triage FP). Nie usuwa obserwacji.
     */
    public function ukryjWUi(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        return $this->update($id, ['ukryty_w_ui' => true]) !== false;
    }

    /**
     * KPI przeglądu: okno czasowe + opcjonalny filtr źródła / IP / kategorii / portu.
     *
     * Agregacje w SQL (jedno zapytanie KPI + jedno GROUP BY dla IP wielozródłowych),
     * bez pobierania wierszy incydentów do PHP (issue #440).
     *
     * @param list<list<string>>|null $grupyCidr  Grupy CIDR — AND między grupami, OR w grupie (filtr AS/CIDR jako warunek SQL).
     *
     * @return array{
     *   okno_dni: int,
     *   liczba_incydentow: int,
     *   liczba_ip: int,
     *   ip_wielozrodlowe: int,
     *   critical_high: int,
     *   max_waga: ?string
     * }
     */
    public function kpiPrzegladu(
        int $oknoDni = 7,
        ?string $zrodlo = null,
        ?string $ip = null,
        ?array $grupyCidr = null,
        ?string $kategoria = null,
        ?int $port = null,
    ): array {
        $oknoDni = max(1, $oknoDni);

        $wiersz = $this->builderWidocznychWOknie($oknoDni, $zrodlo, $ip, null, $kategoria, $port, $grupyCidr)
            ->select(
                "COUNT(*) AS liczba_incydentow, COUNT(DISTINCT i.podmiot) AS liczba_ip, "
                . "COUNT(*) FILTER (WHERE i.waga IN ('high', 'critical')) AS critical_high, "
                . 'MAX(' . self::sqlRangaWagi('i.waga') . ') AS max_ranga',
                false,
            )
            ->where('i.podmiot !=', '')
            ->get()
            ->getRowArray();
        if (! is_array($wiersz)) {
            $wiersz = [];
        }

        // IP wielozródłowe: jedna kolumna agregująca per podmiot (HAVING w SQL).
        $wielozrodlowe = $this->builderWidocznychWOknie($oknoDni, $zrodlo, $ip, null, $kategoria, $port, $grupyCidr)
            ->select("COUNT(DISTINCT NULLIF(i.zrodlo, '')) AS liczba_zrodel", false)
            ->where('i.podmiot !=', '')
            ->groupBy('i.podmiot')
            ->having("COUNT(DISTINCT NULLIF(i.zrodlo, '')) >= 2", null, false)
            ->get()
            ->getResultArray();

        $maxRanga = (int) ($wiersz['max_ranga'] ?? 0);

        return [
            'okno_dni'           => $oknoDni,
            'liczba_incydentow'  => (int) ($wiersz['liczba_incydentow'] ?? 0),
            'liczba_ip'          => (int) ($wiersz['liczba_ip'] ?? 0),
            'ip_wielozrodlowe'   => is_array($wielozrodlowe) ? count($wielozrodlowe) : 0,
            'critical_high'      => (int) ($wiersz['critical_high'] ?? 0),
            'max_waga'           => $maxRanga > 0 ? self::wagaZRangi($maxRanga) : null,
        ];
    }

    /**
     * Ranking problematycznych IP (korelacja źródeł w oknie).
     *
     * Agregacja, HAVING, sortowanie i LIMIT w SQL (GROUP BY podmiot); do PHP trafia tylko
     * wiersz per IP z rankingu + detale (źródła/kategorie) dla zwróconych IP (issue #440).
     *
     * Sort: liczba_zrodel DESC, max_waga DESC, liczba_incydentow DESC, ostatnio DESC.
     *
     * @param list<list<string>>|null $grupyCidr  Grupy CIDR — AND między grupami, OR w grupie (filtr AS/CIDR jako warunek SQL).
     *
     * @return list<array{
     *   ip: string,
     *   liczba_incydentow: int,
     *   liczba_zrodel: int,
     *   zrodla: list<string>,
     *   kategorie: list<string>,
     *   max_waga: ?string,
     *   ostatnio_widziane: ?string
     * }>
     */
    public function topProblematyczneIp(
        int $oknoDni = 7,
        ?string $zrodlo = null,
        ?string $ip = null,
        ?string $minWaga = null,
        int $minZrodel = 1,
        int $limit = 50,
        ?array $grupyCidr = null,
        ?string $kategoria = null,
        ?int $port = null,
    ): array {
        $minZrodel = max(1, $minZrodel);
        $limit     = max(1, min(1000, $limit));

        $ranking = $this->builderWidocznychWOknie($oknoDni, $zrodlo, $ip, $minWaga, $kategoria, $port, $grupyCidr)
            ->select(
                'i.podmiot, COUNT(*) AS liczba_incydentow, COUNT(DISTINCT NULLIF(i.zrodlo, \'\')) AS liczba_zrodel, '
                . 'MAX(' . self::sqlRangaWagi('i.waga') . ') AS max_ranga, MAX(i.ostatnio_widziane) AS ostatnio_widziane',
                false,
            )
            ->where('i.podmiot !=', '')
            ->groupBy('i.podmiot')
            ->having(sprintf("COUNT(DISTINCT NULLIF(i.zrodlo, '')) >= %d", $minZrodel), null, false)
            ->orderBy('liczba_zrodel DESC, max_ranga DESC, liczba_incydentow DESC, ostatnio_widziane DESC, i.podmiot ASC', '', false)
            ->limit($limit)
            ->get()
            ->getResultArray();
        if (! is_array($ranking)) {
            $ranking = [];
        }

        // Detale (źródła / kategorie) tylko dla IP z rankingu — WHERE podmiot IN (lista top).
        $zrodlaMapa    = [];
        $kategorieMapa = [];
        $ipy           = [];
        foreach ($ranking as $w) {
            $ipy[] = (string) ($w['podmiot'] ?? '');
        }
        foreach (array_chunk($ipy, 500) as $paczka) {
            if ($paczka === []) {
                continue;
            }
            $detale = $this->builderWidocznychWOknie($oknoDni, $zrodlo, $ip, $minWaga, $kategoria, $port, $grupyCidr)
                ->select('i.podmiot, i.zrodlo, i.kategoria, COUNT(*) AS liczba', false)
                ->where('i.podmiot !=', '')
                ->whereIn('i.podmiot', $paczka)
                ->groupBy('i.podmiot')
                ->groupBy('i.zrodlo')
                ->groupBy('i.kategoria')
                ->get()
                ->getResultArray();
            if (! is_array($detale)) {
                continue;
            }
            foreach ($detale as $d) {
                $podmiot = (string) ($d['podmiot'] ?? '');
                $z       = (string) ($d['zrodlo'] ?? '');
                if ($z !== '') {
                    $zrodlaMapa[$podmiot][$z] = true;
                }
                $kat = trim((string) ($d['kategoria'] ?? ''));
                if ($kat !== '') {
                    $kategorieMapa[$podmiot][$kat] = ($kategorieMapa[$podmiot][$kat] ?? 0) + (int) ($d['liczba'] ?? 0);
                }
            }
        }

        $lista = [];
        foreach ($ranking as $w) {
            $podmiot   = (string) ($w['podmiot'] ?? '');
            $zrodla    = array_keys($zrodlaMapa[$podmiot] ?? []);
            sort($zrodla);
            $katCount  = $kategorieMapa[$podmiot] ?? [];
            arsort($katCount, SORT_NUMERIC);
            $kategorie = array_slice(array_keys($katCount), 0, self::BADGE_TOP_KATEGORII);
            $maxRanga  = (int) ($w['max_ranga'] ?? 0);
            $ostatnio  = $w['ostatnio_widziane'] ?? null;
            $lista[]   = [
                'ip'                => $podmiot,
                'liczba_incydentow' => (int) ($w['liczba_incydentow'] ?? 0),
                'liczba_zrodel'     => (int) ($w['liczba_zrodel'] ?? 0),
                'zrodla'            => array_values($zrodla),
                'kategorie'         => array_values($kategorie),
                'max_waga'          => $maxRanga > 0 ? self::wagaZRangi($maxRanga) : null,
                'ostatnio_widziane' => (is_string($ostatnio) && $ostatnio !== '') ? $ostatnio : null,
            ];
        }

        return $lista;
    }

    /**
     * Ostatnie skróty incydentów (przegląd chronologiczny).
     *
     * @return list<array<string, mixed>>
     */
    /**
     * @param (callable(string): bool)|null $akceptujIp
     *
     * @return list<array<string, mixed>>
     */
    public function ostatnieIncydenty(
        int $oknoDni = 7,
        ?string $zrodlo = null,
        ?string $ip = null,
        ?string $minWaga = null,
        int $limit = 40,
        ?callable $akceptujIp = null,
        ?string $kategoria = null,
        ?int $port = null,
    ): array {
        $limit   = max(1, min(1000, $limit));
        $builder = $this->builderWidocznych()
            ->select('i.*')
            ->where('i.ostatnio_widziane >=', $this->odDatyOkna($oknoDni));

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
        $this->dolaczFiltrMinWagi($builder, $minWaga);

        $builder->orderBy('i.ostatnio_widziane', 'DESC');
        $builder->orderBy(
            "CASE i.waga
                WHEN 'critical' THEN 4
                WHEN 'high' THEN 3
                WHEN 'medium' THEN 2
                WHEN 'low' THEN 1
                ELSE 0
            END",
            'DESC',
            false,
        );

        // Przy filtrze AS pobierz większą próbkę, potem obetnij do limitu.
        $builder->limit($akceptujIp !== null ? min(2000, $limit * 25) : $limit);

        $wiersze = $builder->get()->getResultArray();
        if (! is_array($wiersze)) {
            return [];
        }

        if ($akceptujIp !== null) {
            $wiersze = array_values(array_filter(
                $wiersze,
                static fn (array $w): bool => $akceptujIp((string) ($w['podmiot'] ?? '')),
            ));
            $wiersze = array_slice($wiersze, 0, $limit);
        }

        return $wiersze;
    }

    /**
     * @return array{low: int, medium: int, high: int, critical: int}
     */
    public static function rangaWag(): array
    {
        return [
            'low'      => 1,
            'medium'   => 2,
            'high'     => 3,
            'critical' => 4,
        ];
    }

    /**
     * Builder widocznych incydentów IP (bez ukrytych źródeł / wierszy).
     */
    protected function builderWidocznych()
    {
        return $this->db->table($this->table . ' AS i')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.ukryty_w_ui', false)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd();
    }

    /**
     * Builder widocznych incydentów IP w oknie z filtrami (źródło / IP / min. waga / kategoria / port / CIDR).
     *
     * @param list<list<string>>|null $grupyCidr
     *
     * @return object Query Builder (świeża instancja per wywołanie)
     */
    protected function builderWidocznychWOknie(
        int $oknoDni,
        ?string $zrodlo,
        ?string $ip,
        ?string $minWaga,
        ?string $kategoria = null,
        ?int $port = null,
        ?array $grupyCidr = null,
    ) {
        $builder = $this->builderWidocznych()
            ->where('i.ostatnio_widziane >=', $this->odDatyOkna($oknoDni));

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
        $this->dolaczFiltrMinWagi($builder, $minWaga);
        $this->dolaczFiltrCidrSql($builder, 'i.podmiot', $grupyCidr);

        return $builder;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function wierszeWidoczneWOknie(
        int $oknoDni,
        ?string $zrodlo,
        ?string $ip,
        ?string $minWaga,
        ?string $kategoria = null,
        ?int $port = null,
    ): array {
        $wiersze = $this->builderWidocznychWOknie($oknoDni, $zrodlo, $ip, $minWaga, $kategoria, $port)
            ->select('i.podmiot, i.zrodlo, i.waga, i.kategoria, i.ostatnio_widziane')
            ->get()
            ->getResultArray();

        return is_array($wiersze) ? $wiersze : [];
    }

    protected function odDatyOkna(int $oknoDni): string
    {
        $oknoDni = max(1, $oknoDni);

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$oknoDni} days")
            ->format('Y-m-d H:i:s');
    }

    /** Regex IPv4 — strażnik przed cast ::inet (PostgreSQL rzuca błąd na wartościach nie-IP). */
    private const REGEX_IPV4 = '^((25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])$';

    /** Regex IPv6 (także forma skompresowana z jednym „::”). */
    private const REGEX_IPV6 = '^(([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:))$';

    private bool $udfCidrZarejestrowany = false;

    /**
     * Filtr AS/CIDR jako warunek SQL (ip <<= cidr), nie callable per wiersz (issue #440).
     *
     * @param object                  $builder Query Builder
     * @param list<list<string>>|null $grupyCidr  AND między grupami, OR w grupie (semantyka jak filtrAkceptujIp: AS + CIDR).
     */
    protected function dolaczFiltrCidrSql($builder, string $kolumna, ?array $grupyCidr): void
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

    /**
     * Wyrażenie SQL rangi wagi (critical=4 … low=1) — odpowiednik rangaWag() po stronie SQL.
     */
    private static function sqlRangaWagi(string $kolumna): string
    {
        return "CASE {$kolumna} WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END";
    }

    private static function wagaZRangi(int $ranga): ?string
    {
        $waga = array_search($ranga, self::rangaWag(), true);

        return is_string($waga) ? $waga : null;
    }

    /**
     * @param object $builder Query Builder
     */
    protected function dolaczFiltrMinWagi($builder, ?string $minWaga): void
    {
        if ($minWaga === null || $minWaga === '') {
            return;
        }
        $ranga = self::rangaWag();
        if (! isset($ranga[$minWaga])) {
            return;
        }
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
}
