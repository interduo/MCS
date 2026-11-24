<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Zapis indeksu: upsert incydentu + nowa obserwacja.
 *
 * zapiszWiele() — zbiorczy zapis paczkowy (issue #442): jedna transakcja na paczkę
 * (domyślnie 500 wierszy), jeden SELECT dedup na paczkę (whereIn), upsert
 * INSERT … ON CONFLICT (zrodlo, klucz_dedupu) DO UPDATE (bez wyścigu check-then-insert)
 * oraz zbiorczy INSERT obserwacji. zapisz() zostaje jako cienki wrapper (kompatybilność).
 */
class IncydentZapis
{
    /** Maksymalna liczba wierszy w jednej paczce (transakcja + whereIn per paczka). */
    public const ROZMIAR_PACZKI = 500;

    private const POLA_WYMAGANE = ['zrodlo', 'klucz_dedupu', 'podmiot', 'zaobserwowano_at', 'waga', 'kategoria', 'tytul'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array{
     *   zrodlo: string,
     *   klucz_dedupu: string,
     *   podmiot_typ?: string,
     *   podmiot: string,
     *   zaobserwowano_at: string,
     *   waga: string,
     *   wersja_wagi?: string,
     *   kategoria: string,
     *   tytul: string,
     *   port?: int|null,
     *   protokol?: string|null,
     *   ukryty_w_ui?: bool,
     *   import_id?: int|null,
     *   id_w_zrodle?: string|null,
     *   payload?: array<string, mixed>|null
     * } $dane
     *
     * @return array{incydent_id: int, obserwacja_id: int, utworzono_incydent: bool}
     */
    public function zapisz(array $dane): array
    {
        return $this->zapiszWiele([$dane])[0];
    }

    /**
     * Zapis zbiorczy wielu wierszy — batch upsert zamiast pętli per wiersz (issue #442).
     *
     * Per paczka (domyślnie 500 wierszy, jedna transakcja): jeden SELECT dedup
     * (whereIn na klucz_dedupu per zrodlo), jeden multi-row upsert
     * INSERT … ON CONFLICT (zrodlo, klucz_dedupu) DO UPDATE … RETURNING id oraz
     * jeden zbiorczy INSERT obserwacji … RETURNING id.
     * Powtórzony klucz (w paczce albo z innym procesem) nie rzuca wyjątku — ON CONFLICT.
     *
     * @param list<array<string, mixed>> $wiersze wiersze jak w zapisz()
     * @param int                        $rozmiarPaczki rozmiar paczki (transakcja per paczka)
     *
     * @return list<array{incydent_id: int, obserwacja_id: int, utworzono_incydent: bool}>
     *         wyniki w kolejności wejściowych $wiersze
     */
    public function zapiszWiele(array $wiersze, int $rozmiarPaczki = self::ROZMIAR_PACZKI): array
    {
        foreach ($wiersze as $dane) {
            if (! is_array($dane)) {
                throw new RuntimeException('Brak wymaganego pola: zrodlo');
            }
            foreach (self::POLA_WYMAGANE as $pole) {
                if (! isset($dane[$pole]) || $dane[$pole] === '') {
                    throw new RuntimeException('Brak wymaganego pola: ' . $pole);
                }
            }
        }

        $wyniki = [];
        foreach (array_chunk($wiersze, max(1, $rozmiarPaczki), true) as $paczka) {
            foreach ($this->zapiszPaczke($paczka) as $i => $w) {
                $wyniki[$i] = $w;
            }
        }
        ksort($wyniki);

        return array_values($wyniki);
    }

    /**
     * Jedna paczka: transakcja + SELECT dedup + upsert incydentów + zbiorczy INSERT obserwacji.
     *
     * @param array<int, array<string, mixed>> $paczka indeksy oryginalne (preserve keys)
     *
     * @return array<int, array{incydent_id: int, obserwacja_id: int, utworzono_incydent: bool}>
     */
    private function zapiszPaczke(array $paczka): array
    {
        // Dedup w paczce po (zrodlo, klucz_dedupu) — przy upsert ostatni wiersz wygrywa
        // (w pętli per wiersz późniejszy zapisz() nadpisywałby wcześniejszy).
        // PostgreSQL/SQLite odrzucają duplikat klucza w jednym INSERT … ON CONFLICT.
        $poKluczu = [];
        $pierwszyKlucz = [];
        foreach ($paczka as $i => $dane) {
            $k = $this->klucz($dane);
            // ostatni wiersz wygrywa przy upsert (jak późniejszy zapisz() w pętli per wiersz),
            // ale pierwsze_widziane bierze z pierwszego wystąpienia (insert pierwszego wiersza)
            $pierwszyKlucz[$k] ??= $dane;
            $poKluczu[$k] = $dane;
        }

        $this->db->transException(true)->transStart();

        try {
            // Jeden SELECT dedup na paczkę (per zrodlo): istniejące incydenty.
            $istniejace = [];
            $kluczePerZrodlo = [];
            foreach ($poKluczu as $k => $dane) {
                $kluczePerZrodlo[$dane['zrodlo']][] = $dane['klucz_dedupu'];
            }
            foreach ($kluczePerZrodlo as $zrodlo => $klucze) {
                $wiersze = $this->db->table('mcs_incydenty')
                    ->select('id, zrodlo, klucz_dedupu, podmiot_typ, podmiot, pierwsze_widziane, waga, wersja_wagi, kategoria, tytul, port, protokol')
                    ->where('zrodlo', $zrodlo)
                    ->whereIn('klucz_dedupu', array_unique($klucze))
                    ->get()
                    ->getResultArray();
                foreach ($wiersze as $w) {
                    $istniejace[$w['zrodlo'] . "\x1f" . $w['klucz_dedupu']] = $w;
                }
            }

            // Upsert: dla istniejącego klucza mergujemy update w PHP (identycznie jak zapisz()),
            // dla nowego — pełny wiersz insertu. DO UPDATE nie rusza pierwsze_widziane.
            $wierszeUpsert = [];
            foreach ($poKluczu as $k => $dane) {
                $wierszeUpsert[] = $this->wierszUpsert($dane, $istniejace[$k] ?? null, $pierwszyKlucz[$k]);
            }
            ZapisBatch::upsert(
                $this->db,
                'mcs_incydenty',
                $wierszeUpsert,
                '(zrodlo, klucz_dedupu)',
                [
                    'podmiot_typ = excluded.podmiot_typ',
                    'podmiot = excluded.podmiot',
                    'ostatnio_widziane = excluded.ostatnio_widziane',
                    'waga = excluded.waga',
                    'wersja_wagi = excluded.wersja_wagi',
                    'kategoria = excluded.kategoria',
                    'tytul = excluded.tytul',
                    'port = excluded.port',
                    'protokol = excluded.protokol',
                ],
            );

            // Id incydentów po upsertcie — jeden SELECT per zrodlo (unikalny klucz zrodlo+klucz_dedupu).
            $idPoKluczu = [];
            foreach ($kluczePerZrodlo as $zrodlo => $klucze) {
                $wiersze = $this->db->table('mcs_incydenty')
                    ->select('id, zrodlo, klucz_dedupu')
                    ->where('zrodlo', $zrodlo)
                    ->whereIn('klucz_dedupu', array_unique($klucze))
                    ->get()
                    ->getResultArray();
                foreach ($wiersze as $w) {
                    $idPoKluczu[$w['zrodlo'] . "\x1f" . $w['klucz_dedupu']] = (int) $w['id'];
                }
            }

            // Obserwacje: jedna na wiersz wejściowy (tak jak zapisz() per wiersz), zbiorczy INSERT.
            $wierszeObs = [];
            foreach ($paczka as $dane) {
                $payload    = $dane['payload'] ?? null;
                $wierszeObs[] = [
                    'incydent_id'      => $idPoKluczu[$this->klucz($dane)],
                    'import_id'        => $dane['import_id'] ?? null,
                    'zaobserwowano_at' => (string) $dane['zaobserwowano_at'],
                    'payload_json'     => is_array($payload)
                        ? json_encode($payload, JSON_UNESCAPED_UNICODE)
                        : null,
                    'id_w_zrodle'      => $dane['id_w_zrodle'] ?? null,
                ];
            }
            $obserwacje = ZapisBatch::insertZwrocId($this->db, 'mcs_obserwacje', $wierszeObs);

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Transakcja zapisu incydentów/obserwacji nieudana');
            }
        } catch (\Throwable $e) {
            if ($this->db->transStatus() !== false) {
                $this->db->transRollback();
            }
            // Przy błędzie zapytania CI4 sam cofa transakcję, ale (transStrict) zostawia
            // transStatus=false. Bez resetu każdy kolejny zapis na tym połączeniu pada.
            $this->db->resetTransStatus();
            throw $e;
        }

        // Wyniki w kolejności wejściowej paczki.
        $wyniki         = [];
        $utworzonoKlucz = [];
        $pozycja        = 0;
        foreach ($paczka as $i => $dane) {
            $k          = $this->klucz($dane);
            $utworzono  = ! isset($istniejace[$k]) && ! isset($utworzonoKlucz[$k]);
            $utworzonoKlucz[$k] = true;
            $wyniki[$i] = [
                'incydent_id'        => $idPoKluczu[$k],
                'obserwacja_id'      => $obserwacje[$pozycja],
                'utworzono_incydent' => $utworzono,
            ];
            $pozycja++;
        }

        return $wyniki;
    }

    /**
     * Pełny wiersz INSERT (13 kolumn) dla upsert. Dla istniejącego incydentu wartości
     * kolumn aktualizowanych są mergowane z bazą — tak jak update w zapisz().
     *
     * @param array<string, mixed>      $dane
     * @param array<string, mixed>|null $istniejacy
     * @param array<string, mixed>      $pierwszy wiersz paczki z tym samym kluczem (pierwsze_widziane)
     *
     * @return array<string, mixed>
     */
    private function wierszUpsert(array $dane, ?array $istniejacy, array $pierwszy): array
    {
        $czas   = (string) $dane['zaobserwowano_at'];
        $wiersz = [
            'zrodlo'            => (string) $dane['zrodlo'],
            'klucz_dedupu'      => (string) $dane['klucz_dedupu'],
            'podmiot_typ'       => (string) ($dane['podmiot_typ'] ?? 'ip'),
            'podmiot'           => (string) $dane['podmiot'],
            'pierwsze_widziane' => $czas,
            'ostatnio_widziane' => $czas,
            'waga'              => (string) $dane['waga'],
            'wersja_wagi'       => $dane['wersja_wagi'] ?? null,
            'kategoria'         => (string) $dane['kategoria'],
            'tytul'             => (string) $dane['tytul'],
            'port'              => $dane['port'] ?? null,
            'protokol'          => $dane['protokol'] ?? null,
            'ukryty_w_ui'       => (bool) ($dane['ukryty_w_ui'] ?? false),
        ];

        if ($istniejacy !== null) {
            // Ścieżka update (jak w zapisz()): pierwsze_widziane bez zmian, pola opcjonalne
            // nadpisywane tylko gdy podane w danych.
            $wiersz['pierwsze_widziane'] = (string) $istniejacy['pierwsze_widziane'];
        } else {
            // Nowy klucz, ale powtórzony w paczce: pierwsze_widziane z pierwszego wystąpienia.
            $wiersz['pierwsze_widziane'] = (string) $pierwszy['zaobserwowano_at'];
        }
        if ($istniejacy !== null) {
            $wiersz['ostatnio_widziane'] = $czas;
            $wiersz['waga']              = (string) $dane['waga'];
            $wiersz['kategoria']         = (string) $dane['kategoria'];
            $wiersz['tytul']             = (string) $dane['tytul'];
            if (array_key_exists('wersja_wagi', $dane)) {
                $wiersz['wersja_wagi'] = $dane['wersja_wagi'];
            }
            if (array_key_exists('port', $dane)) {
                $wiersz['port'] = $dane['port'];
            }
            if (array_key_exists('protokol', $dane)) {
                $wiersz['protokol'] = $dane['protokol'];
            }
            if (array_key_exists('podmiot_typ', $dane) && $dane['podmiot_typ'] !== '') {
                $wiersz['podmiot_typ'] = (string) $dane['podmiot_typ'];
            }
            if (array_key_exists('podmiot', $dane) && $dane['podmiot'] !== '') {
                $wiersz['podmiot'] = (string) $dane['podmiot'];
            }
        }

        return $wiersz;
    }

    /**
     * @param array<string, mixed> $dane
     */
    private function klucz(array $dane): string
    {
        return (string) $dane['zrodlo'] . "\x1f" . (string) $dane['klucz_dedupu'];
    }
}
