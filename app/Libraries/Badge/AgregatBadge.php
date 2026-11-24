<?php

namespace App\Libraries\Badge;

use App\Models\IncydentModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Agregat badge (okno ostatnio_widziane, bez źródeł/incydentów ukrytych w UI) dla API LMS
 * (App\Controllers\Api\Badge) — wydzielony z IncydentModel (issue #464); model deleguje.
 *
 * Pojedyncze IP i batch (jedno whereIn); IP bez wierszy w oknie dostają zerowy agregat.
 */
final class AgregatBadge
{
    /** Limit kategorii w odpowiedzi badge (najczęstsze pierwsze). */
    public const TOP_KATEGORII = 5;

    /** Limit przykładowych tytułów w odpowiedzi badge. */
    public const PRZYKLADOW = 3;

    private const TABELA = 'mcs_incydenty';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Agregat badge dla pojedynczego IP.
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
    public function dlaIp(string $ip, int $oknoDni = 7): array
    {
        $mapa = $this->dlaIpLista([$ip], $oknoDni);

        return $mapa[$ip] ?? $this->pusty(max(1, $oknoDni));
    }

    /**
     * Batch agregat badge (jedno whereIn) — klucz = IP z listy wejściowej (kolejność zachowana).
     * IP bez wierszy w oknie dostają zerowy agregat.
     *
     * @param list<string> $ips
     *
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
    public function dlaIpLista(array $ips, int $oknoDni = 7): array
    {
        $oknoDni = max(1, $oknoDni);
        $puste   = $this->pusty($oknoDni);

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

        $builder = $this->db->table(self::TABELA . ' AS i')
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

        $ranga = IncydentModel::rangaWag();
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
            $kategorieLista = array_slice(array_keys($katCount), 0, self::TOP_KATEGORII);

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
                if (count($przyklady) >= self::PRZYKLADOW) {
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
     * Zerowy agregat badge dla IP bez wierszy w oknie.
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
    public function pusty(int $oknoDni): array
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
     * Początek okna (UTC) jako datetime dla SQL — jak IncydentModel::odDatyOkna.
     */
    private function odDatyOkna(int $oknoDni): string
    {
        $oknoDni = max(1, $oknoDni);

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$oknoDni} days")
            ->format('Y-m-d H:i:s');
    }
}
