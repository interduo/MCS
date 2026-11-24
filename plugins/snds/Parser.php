<?php

namespace McsPluginy\snds;

use App\Libraries\ImportLimity;
use App\Libraries\PrzekroczonyLimitImportu;
use RuntimeException;

/**
 * Parser CSV SNDS (Microsoft Smart Network Data Services).
 *
 * Tryby:
 * 1) DataKey / LMS — CSV **bez nagłówka**, 13 lub 14 kolumn pozycyjnych
 *    (jak `lms-snds.php` / portal Automated Data Access).
 * 2) Eksport z nagłówkami — mapowanie po nazwach (stara fixture / IP Status).
 *
 * klucz_dedupu: ip|filtr|data_aktywnosci (dzień UTC) — semantyka MCS.
 */
final class Parser
{
    /** Nazwy pól raw dla układu pozycyjnego (zgodnie z LMS alfa_snds). */
    public const KOLUMNY_POZYCYJNE = [
        'ip_address',
        'activity_period_start',
        'activity_period_end',
        'rcpt_commands',
        'data_commands',
        'message_recipients',
        'filter_result',
        'complaint_rate',
        'trap_message_period_start',
        'trap_message_period_end',
        'trap_hits',
        'sample_helo',
        'jmr_p1_sender',
        'comments',
    ];

    /**
     * @return list<array{
     *   ip: string,
     *   filtr: ?string,
     *   timestamp: string,
     *   klucz_dedupu: string,
     *   complaint_rate: ?string,
     *   trap_hits: ?int,
     *   raw: array<string, string>
     * }>
     */
    public function parsujCsv(string $tresc): array
    {
        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            return [];
        }
        fwrite($fp, $tresc);
        rewind($fp);
        $wynik = iterator_to_array($this->parsujCsvStrumien($fp), false);
        fclose($fp);

        return $wynik;
    }

    /**
     * Strumieniowy parser CSV (issue #453): fgetcsv w pętli — pamięć nie rośnie
     * z rozmiarem pliku. Przekroczenie limitu wierszy (mcs.import.maxWierszy)
     * rzuca PrzekroczonyLimitImportu, a import dostaje status `blad`.
     *
     * @param resource $fp otwarty strumień CSV (kursor na początku)
     *
     * @return \Generator<int, array{
     *   ip: string,
     *   filtr: ?string,
     *   timestamp: string,
     *   klucz_dedupu: string,
     *   complaint_rate: ?string,
     *   trap_hits: ?int,
     *   raw: array<string, string>
     * }>
     */
    public function parsujCsvStrumien($fp): \Generator
    {
        $maxWierszy = ImportLimity::maxWierszy();

        $pierwszy = fgetcsv($fp, null, ',', '"', '\\');
        if (! is_array($pierwszy) || $pierwszy === [null]) {
            return;
        }
        $pierwszy[0] = preg_replace("/^\xEF\xBB\xBF/", '', (string) ($pierwszy[0] ?? ''));

        if ($this->poleWygladaNaIpDataKey((string) $pierwszy[0])) {
            // tryb pozycyjny (DataKey, bez nagłówka): pierwszy rekord to już dane
            foreach ($this->czytajWierszeCsv($fp, $pierwszy, $maxWierszy) as $pola) {
                $wiersz = $this->zlozWierszPozycyjny($pola);
                if ($wiersz !== null) {
                    yield $wiersz;
                }
            }

            return;
        }

        $naglowki = array_map(static fn ($h) => strtolower(trim((string) $h)), $pierwszy);
        foreach ($this->czytajWierszeCsv($fp, null, $maxWierszy) as $pola) {
            $wiersz = $this->zlozWierszPoNaglowkach($naglowki, $pola);
            if ($wiersz !== null) {
                yield $wiersz;
            }
        }
    }

    /**
     * Czyta surowe wiersze CSV ze strumienia z egzekwowanym limitem liczby rekordów.
     *
     * @param resource                          $fp
     * @param array<int, string|null>|null       $pierwszy pierwszy rekord, gdy caller już go odczytał
     *
     * @return \Generator<int, array<int, string|null>>
     */
    private function czytajWierszeCsv($fp, ?array $pierwszy, int $maxWierszy): \Generator
    {
        $licznik = 0;
        $oczekujace = $pierwszy !== null ? [$pierwszy] : [];
        while (true) {
            if ($oczekujace === []) {
                $pola = fgetcsv($fp, null, ',', '"', '\\');
                if ($pola === false) {
                    return;
                }
                $oczekujace = [$pola];
            }
            foreach ($oczekujace as $pola) {
                if ($pola === [null]) {
                    continue; // pusta linia
                }
                $licznik++;
                if ($licznik > $maxWierszy) {
                    throw new PrzekroczonyLimitImportu(ImportLimity::komunikatWiersze('SNDS', $maxWierszy));
                }
                yield $pola;
            }
            $oczekujace = [];
        }
    }

    private function poleWygladaNaIpDataKey(string $pole): bool
    {
        return (bool) preg_match('/^\d+\.\d+\.\d+\.\d+$/', trim($pole));
    }

    /**
     * @param array<int, string|null> $data
     *
     * @return array{
     *   ip: string,
     *   filtr: ?string,
     *   timestamp: string,
     *   klucz_dedupu: string,
     *   complaint_rate: ?string,
     *   trap_hits: ?int,
     *   raw: array<string, string>
     * }|null
     */
    private function zlozWierszPozycyjny(array $data): ?array
    {
        $liczba = count($data);
        if ($liczba !== 13 && $liczba !== 14) {
            throw new RuntimeException(
                'SNDS: nieprawidlowa liczba kolumn (oczekiwano 13 lub 14, jest '
                . $liczba . ').',
            );
        }
        if ($liczba === 13) {
            $data[] = '';
        }

        $raw = [];
        foreach (self::KOLUMNY_POZYCYJNE as $i => $nazwa) {
            $raw[$nazwa] = trim((string) ($data[$i] ?? ''));
        }

        return $this->zlozWierszZRaw($raw, $raw['ip_address'], $raw['filter_result'], $raw['activity_period_start'], $raw['complaint_rate'], $raw['trap_hits']);
    }

    /**
     * @param list<string>            $naglowki
     * @param array<int, string|null> $pola
     *
     * @return array{
     *   ip: string,
     *   filtr: ?string,
     *   timestamp: string,
     *   klucz_dedupu: string,
     *   complaint_rate: ?string,
     *   trap_hits: ?int,
     *   raw: array<string, string>
     * }|null
     */
    private function zlozWierszPoNaglowkach(array $naglowki, array $pola): ?array
    {
        $raw = [];
        foreach ($naglowki as $i => $nazwa) {
            $raw[$nazwa] = isset($pola[$i]) ? trim((string) $pola[$i]) : '';
        }

        $ip = $this->wartosc($raw, [
            'ip address',
            'ip',
            'ip_address',
            'ipaddress',
        ]);
        $filtr = $this->wartosc($raw, [
            'filter result',
            'filter_result',
            'filterresult',
            'filtr',
        ]);
        $timestamp = $this->wartosc($raw, [
            'activity period start',
            'activity start',
            'activity_period_start',
            'date',
            'timestamp',
        ]);
        $complaint = $this->wartosc($raw, [
            'complaint rate',
            'complaint_rate',
            'complaintrate',
        ]);
        $trapRaw = $this->wartosc($raw, [
            'trap hits',
            'trap_hits',
            'traphits',
        ]);

        return $this->zlozWierszZRaw($raw, $ip, $filtr, $timestamp, $complaint, $trapRaw);
    }

    /**
     * SNDS = reputacja nadawcy u Microsoftu.
     *
     * @param array<string, mixed> $wiersz
     */
    public function kategoriaDlaWiersza(array $wiersz): string
    {
        return 'reputation';
    }

    public function liniaWygladaNaWierszDataKey(string $linia): bool
    {
        return (bool) preg_match('/^\d+\.\d+\.\d+\.\d+,/', $linia);
    }

    /**
     * @param array<string, string> $raw
     *
     * @return array{
     *   ip: string,
     *   filtr: ?string,
     *   timestamp: string,
     *   klucz_dedupu: string,
     *   complaint_rate: ?string,
     *   trap_hits: ?int,
     *   raw: array<string, string>
     * }|null
     */
    private function zlozWierszZRaw(
        array $raw,
        string $ip,
        string $filtr,
        string $timestamp,
        string $complaint,
        string $trapRaw,
    ): ?array {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $filtrNorm = $filtr !== '' ? strtoupper($filtr) : null;
        if ($timestamp === '') {
            $timestamp = gmdate('Y-m-d H:i:s');
        }

        $complaintNorm = $complaint !== '' ? $complaint : null;
        $trapHits      = null;
        if ($trapRaw !== '' && ctype_digit($trapRaw)) {
            $trapHits = (int) $trapRaw;
        }

        $dzien = $this->dzienZTimestamp($timestamp);
        $klucz = strtolower($ip) . '|' . strtolower((string) ($filtrNorm ?? '')) . '|' . $dzien;

        return [
            'ip'             => $ip,
            'filtr'          => $filtrNorm,
            'timestamp'      => $timestamp,
            'klucz_dedupu'   => $klucz,
            'complaint_rate' => $complaintNorm,
            'trap_hits'      => $trapHits,
            'raw'            => $raw,
        ];
    }

    /**
     * @param array<string, string> $raw
     * @param list<string>          $kandydaci
     */
    private function wartosc(array $raw, array $kandydaci): string
    {
        foreach ($kandydaci as $k) {
            if (isset($raw[$k]) && $raw[$k] !== '') {
                return $raw[$k];
            }
        }

        return '';
    }

    private function dzienZTimestamp(string $timestamp): string
    {
        $dt = $this->parsujCzas($timestamp);
        if ($dt !== null) {
            return $dt->format('Y-m-d');
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $timestamp, $m) === 1) {
            return $m[1];
        }

        return gmdate('Y-m-d');
    }

    public function parsujCzas(string $timestamp): ?\DateTimeImmutable
    {
        $formaty = [
            'm/d/Y g:i A',
            'm/d/Y h:i A',
            'm/d/Y G:i',
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s',
        ];
        foreach ($formaty as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $timestamp, new \DateTimeZone('UTC'));
            if ($dt instanceof \DateTimeImmutable) {
                $bledy = \DateTimeImmutable::getLastErrors();
                if (is_array($bledy) && (($bledy['warning_count'] ?? 0) > 0 || ($bledy['error_count'] ?? 0) > 0)) {
                    continue;
                }

                return $dt;
            }
        }

        try {
            return new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
