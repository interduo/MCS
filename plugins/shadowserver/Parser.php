<?php

namespace McsPluginy\shadowserver;

use App\Libraries\ImportLimity;
use App\Libraries\PrzekroczonyLimitImportu;

/**
 * Parser CSV Shadowserver — mapowanie po nazwach nagłówków (nie po pozycji kolumn).
 *
 * Fixture / próbki syntetyczne do czasu prawdziwych raportów z Waszej skrzynki.
 * Dedup (logiczny): ip + tag + port + protokol.
 *
 * Duże raporty (setki tysięcy wierszy) czytamy strumieniowo: parsujCsvStrumien()
 * (fgetcsv w pętli, issue #453) — bez wczytywania całego pliku do pamięci.
 */
final class Parser
{
    /**
     * @return list<array{
     *   ip: string,
     *   port: ?int,
     *   protokol: ?string,
     *   tag: ?string,
     *   severity: ?string,
     *   timestamp: string,
     *   klucz_dedupu: string,
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
     * z rozmiarem raportu. Przekroczenie limitu wierszy (mcs.import.maxWierszy)
     * rzuca PrzekroczonyLimitImportu, a import dostaje status `blad`.
     *
     * @param resource $fp otwarty strumień CSV (kursor na początku)
     *
     * @return \Generator<int, array{
     *   ip: string,
     *   port: ?int,
     *   protokol: ?string,
     *   tag: ?string,
     *   severity: ?string,
     *   timestamp: string,
     *   klucz_dedupu: string,
     *   raw: array<string, string>
     * }>
     */
    public function parsujCsvStrumien($fp): \Generator
    {
        $maxWierszy = ImportLimity::maxWierszy();

        $naglowki = fgetcsv($fp, null, ',', '"', '\\');
        if (! is_array($naglowki) || $naglowki === [null]) {
            return;
        }
        $naglowki[0] = preg_replace("/^\xEF\xBB\xBF/", '', (string) $naglowki[0]);
        $naglowki    = array_map(static fn ($h) => strtolower(trim((string) $h)), $naglowki);

        $licznik = 0;
        while (($pola = fgetcsv($fp, null, ',', '"', '\\')) !== false) {
            if ($pola === [null]) {
                continue; // pusta linia
            }
            $licznik++;
            if ($licznik > $maxWierszy) {
                throw new PrzekroczonyLimitImportu(ImportLimity::komunikatWiersze('Shadowserver', $maxWierszy));
            }

            $raw = [];
            foreach ($naglowki as $i => $nazwa) {
                $raw[$nazwa] = isset($pola[$i]) ? trim((string) $pola[$i]) : '';
            }

            $ip = $raw['ip'] ?? $raw['src_ip'] ?? $raw['dst_ip'] ?? '';
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $port = null;
            if (isset($raw['port']) && $raw['port'] !== '' && ctype_digit($raw['port'])) {
                $port = (int) $raw['port'];
            }

            $protokol = $raw['protocol'] ?? $raw['proto'] ?? null;
            if ($protokol === '') {
                $protokol = null;
            }

            $tag = $raw['tag'] ?? $raw['type'] ?? $raw['category'] ?? null;
            if ($tag === '') {
                $tag = null;
            }

            $severity = $raw['severity'] ?? null;
            if ($severity === '') {
                $severity = null;
            }

            $timestamp = $raw['timestamp'] ?? $raw['time'] ?? $raw['date'] ?? '';
            if ($timestamp === '') {
                $timestamp = gmdate('Y-m-d H:i:s');
            }

            $klucz = strtolower($ip) . '|' . strtolower((string) ($tag ?? ''))
                . '|' . ($port ?? '') . '|' . strtolower((string) ($protokol ?? ''));

            yield [
                'ip'            => $ip,
                'port'          => $port,
                'protokol'      => $protokol !== null ? strtolower($protokol) : null,
                'tag'           => $tag,
                'severity'      => $severity !== null ? strtolower($severity) : null,
                'timestamp'     => $timestamp,
                'klucz_dedupu'  => $klucz,
                'raw'           => $raw,
            ];
        }
    }

    /**
     * Mapowanie tag/severity → kategoria MCS pod WagaV1 (tag też jako sygnał wagi).
     */
    public function kategoriaDlaWiersza(array $wiersz): string
    {
        $tag = strtolower((string) ($wiersz['tag'] ?? ''));
        if (str_contains($tag, 'amplif') || str_contains($tag, 'dns-open') || str_contains($tag, 'ntp')) {
            return 'amplification';
        }
        if (str_contains($tag, 'malware') || str_contains($tag, 'botnet') || str_contains($tag, 'c2')) {
            return 'malware';
        }
        if (str_contains($tag, 'vuln') || str_contains($tag, 'exploit')) {
            return 'vulnerability';
        }
        if (str_contains($tag, 'scan') || str_contains($tag, 'bruteforce') || str_contains($tag, 'spam')) {
            return 'abuse';
        }
        if (str_contains($tag, 'open') || str_contains($tag, 'accessible') || str_contains($tag, 'exposed')) {
            return 'exposure';
        }

        return 'other';
    }
}
