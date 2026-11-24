<?php

namespace McsPluginy\shadowserver;

use App\Libraries\ImportKoperta;
use App\Libraries\ImportLimity;
use App\Libraries\IncydentZapis;
use App\Libraries\Imap\ImapKlientFabryka;
use App\Libraries\Imap\ImapKlientInterface;
use App\Libraries\Imap\PluginImapBaza;
use App\Libraries\PrzekroczonyLimitImportu;
use App\Libraries\WagaV1;
use Config\Database;
use RuntimeException;

/**
 * Plugin źródła Shadowserver.
 *
 * Tryby pobierz() (wspólny szkielet IMAP: PluginImapBaza, issue #463):
 * 1) plik fixture — gdy ustawione mcs.shadowserver.fixturePath (dev / test)
 * 2) IMAP — gdy .env ma host/user/pass (wymaga php-imap)
 *
 * Duże raporty CSV czytamy strumieniowo (issue #453): importujPlik/załączniki → fgetcsv
 * w pętli (Parser::parsujCsvStrumien), zapis paczkami 500 — pamięć nie rośnie z rozmiarem.
 * Limity mcs.import.maxPlikMb / mcs.import.maxWierszy: przekroczenie = status `blad`.
 *
 * klucz_dedupu: ip|tag|port|protokol (stabilny, udokumentowany).
 */
class Plugin extends PluginImapBaza
{
    /** Maks. rozmiar CSV po rozpakowaniu ZIP (bajty), ochrona przed zip bomb. */
    private const MAX_CSV_B = 100 * 1024 * 1024;

    public function __construct(
        ?Parser $parser = null,
        ?ImportKoperta $koperta = null,
        ?IncydentZapis $zapis = null,
        ?WagaV1 $waga = null,
        ?ImapKlientFabryka $imapFabryka = null,
    ) {
        parent::__construct($parser ?? new Parser(), $koperta, $zapis, $waga, $imapFabryka);
    }

    public function zrodlo(): string
    {
        return 'shadowserver';
    }

    protected function importujFixture(string $sciezka): int
    {
        return $this->importujPlik($sciezka, null);
    }

    protected function komunikatFixture(int $liczba): string
    {
        return $liczba > 0
            ? "import fixture OK ({$liczba} wierszy)"
            : 'brak nowych danych (duplikat checksum/id albo puste CSV)';
    }

    /**
     * @param array<string, int|bool> $stats
     */
    protected function komunikatImapOk(array $stats): string
    {
        return sprintf(
            'import IMAP OK (wiadomosci=%d, nowe_importy=%d, wiersze=%d%s)',
            $stats['wiadomosci'],
            $stats['importy'],
            $stats['wiersze'],
            $stats['fallback_ostatnie'] ? ', tryb=ostatnie_SEEN' : '',
        );
    }

    /**
     * Opis gotowości .env gdy jeszcze nie było przebiegu importu.
     */
    protected function opisKonfiguracji(): string
    {
        if ($this->sciezkaFixture() !== '') {
            return 'konfiguracja: tryb fixturePath (jeszcze bez przebiegu)';
        }
        $host = trim((string) env('mcs.shadowserver.imapHost', ''));
        $user = trim((string) env('mcs.shadowserver.imapUser', ''));
        if ($host !== '' && $user !== '') {
            $limit = max(1, \App\Libraries\OpcjeMcs::int('mcs.shadowserver.imapRecentLimit'));

            return "konfiguracja: IMAP gotowy (imapRecentLimit={$limit}, jeszcze bez przebiegu)";
        }

        return 'brak fixturePath i IMAP (uzupelnij .env)';
    }

    /**
     * Import jednego CSV z pliku (fixture) — strumieniowo, z limitem rozmiaru (issue #453).
     */
    public function importujPlik(string $sciezka, ?string $idWiadomosci): int
    {
        if (! is_readable($sciezka)) {
            throw new RuntimeException('Shadowserver: nie można odczytać pliku ' . $sciezka);
        }

        try {
            ImportLimity::sprawdzPlik($sciezka, 'Shadowserver');
        } catch (PrzekroczonyLimitImportu $e) {
            $this->koperta->oznaczBlad(
                $this->zrodlo(),
                hash_file('sha256', $sciezka) ?: null,
                $idWiadomosci,
                ['tryb' => 'plik', 'plik' => basename($sciezka)],
                $e->getMessage(),
            );
            throw $e;
        }

        $checksum = (string) hash_file('sha256', $sciezka);
        $fp       = fopen($sciezka, 'rb');
        if ($fp === false) {
            throw new RuntimeException('Shadowserver: nie można otworzyć pliku ' . $sciezka);
        }

        try {
            return $this->importujStrumienCsv($fp, $checksum, $idWiadomosci, [
                'tryb'  => 'plik',
                'plik'  => basename($sciezka),
            ]);
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param array<string, int|bool> $stats
     *
     * @return array{wiadomosci: int, importy: int, wiersze: int, fallback_ostatnie: bool}
     */
    protected function importujZImap(ImapKlientInterface $klient): array
    {
        $wybor    = $this->uidyDoPobrania($klient);
        $uidy     = $wybor['uidy'];
        $fallback = $wybor['fallback'];

        $sumaWierszy  = 0;
        $sumaImportow = 0;

        foreach ($uidy as $uid) {
            $wiadomosc     = $klient->pobierzWiadomosc($uid);
            $zaimportowano = false;
            foreach ($wiadomosc['zalaczniki'] as $zal) {
                // Limit rozmiaru załącznika (issue #453): za duży = import `blad`, nie ciche pominięcie.
                if (strlen($zal['tresc']) > ImportLimity::maxPlikB()) {
                    $this->koperta->oznaczBlad(
                        $this->zrodlo(),
                        hash('sha256', $zal['tresc']),
                        $this->idZalacznika($wiadomosc, $zal['filename']),
                        [
                            'tryb'     => 'imap',
                            'uid'      => $uid,
                            'filename' => $zal['filename'],
                            'subject'  => $wiadomosc['subject'],
                        ],
                        ImportLimity::komunikatRozmiar('Shadowserver', $zal['filename'], strlen($zal['tresc']), ImportLimity::maxPlikB()),
                    );
                    continue;
                }
                $csv = $this->wyciagnijCsvZZalacznika($zal);
                if ($csv === null) {
                    continue;
                }
                $checksum = hash('sha256', $csv['tresc']);
                // Jeden mail może mieć kilka załączników: id musi być unikalne per załącznik,
                // bo koperta dedupuje po checksum LUB id_wiadomosci.
                $idZalacznika = $this->idZalacznika($wiadomosc, $csv['filename']);
                $liczba       = $this->importujTrescCsv(
                    $csv['tresc'],
                    $checksum,
                    $idZalacznika,
                    [
                        'tryb'     => 'imap',
                        'uid'      => $uid,
                        'filename' => $csv['filename'],
                        'zip'      => $csv['z_zip'] ? $zal['filename'] : null,
                        'subject'  => $wiadomosc['subject'],
                    ],
                );
                if ($liczba > 0) {
                    $sumaWierszy += $liczba;
                    $sumaImportow++;
                }
                $zaimportowano = true;
            }
            if ($zaimportowano) {
                $klient->oznaczPrzeczytane($uid);
            }
        }

        return [
            'wiadomosci'         => count($uidy),
            'importy'            => $sumaImportow,
            'wiersze'            => $sumaWierszy,
            'fallback_ostatnie'  => $fallback,
        ];
    }

    private function idZalacznika(array $wiadomosc, string $filename): ?string
    {
        return $wiadomosc['message_id'] !== null && $wiadomosc['message_id'] !== ''
            ? $wiadomosc['message_id'] . '#' . $filename
            : null;
    }

    /**
     * Akceptuje .csv albo .zip/.csv.zip z jednym (lub pierwszym) plikiem CSV w srodku.
     *
     * @param array{filename: string, mime: string, tresc: string} $zal
     *
     * @return array{filename: string, tresc: string, z_zip: bool}|null
     */
    private function wyciagnijCsvZZalacznika(array $zal): ?array
    {
        if (strlen($zal['tresc']) > ImportLimity::maxPlikB()) {
            return null; // backstop — główna oznaka `blad` jest w importujZImap
        }

        $nazwa = strtolower($zal['filename']);
        if (str_ends_with($nazwa, '.csv') && ! str_ends_with($nazwa, '.csv.zip')) {
            return [
                'filename' => $zal['filename'],
                'tresc'    => $zal['tresc'],
                'z_zip'    => false,
            ];
        }

        if (! str_ends_with($nazwa, '.zip')) {
            return null;
        }

        if (! class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Shadowserver: brak ZipArchive — apt install php-zip');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mcs_ss_');
        if ($tmp === false) {
            throw new RuntimeException('Shadowserver: nie mozna utworzyc pliku tymczasowego na ZIP');
        }

        try {
            file_put_contents($tmp, $zal['tresc']);
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return null;
            }

            $csvNazwa = null;
            $csvTresc = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if ($entry === false) {
                    continue;
                }
                if (str_ends_with(strtolower($entry), '.csv') && ! str_contains($entry, '..')) {
                    $stat = $zip->statIndex($i);
                    if ($stat === false || (int) $stat['size'] > self::MAX_CSV_B) {
                        continue;
                    }
                    $body = $zip->getFromIndex($i);
                    if ($body !== false) {
                        $csvNazwa = basename($entry);
                        $csvTresc = $body;
                        break;
                    }
                }
            }
            $zip->close();

            if ($csvNazwa === null || $csvTresc === null) {
                return null;
            }

            return [
                'filename' => $csvNazwa,
                'tresc'    => $csvTresc,
                'z_zip'    => true,
            ];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Import CSV z treści w pamięci (załącznik IMAP) — przez strumień php://temp (issue #453).
     *
     * @param array<string, mixed> $meta
     */
    private function importujTrescCsv(string $tresc, string $checksum, ?string $idWiadomosci, array $meta): int
    {
        try {
            ImportLimity::sprawdzTresc($tresc, 'Shadowserver', $meta['filename'] ?? '');
        } catch (PrzekroczonyLimitImportu $e) {
            $this->koperta->oznaczBlad($this->zrodlo(), $checksum, $idWiadomosci, $meta, $e->getMessage());
            throw $e;
        }

        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            throw new RuntimeException('Shadowserver: nie można otworzyć strumienia CSV');
        }
        fwrite($fp, $tresc);
        rewind($fp);

        try {
            return $this->importujStrumienCsv($fp, $checksum, $idWiadomosci, $meta);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Import CSV ze strumienia (issue #453): wiersze zapisywane paczkami po ROZMIAR_PACZKI
     * w trakcie czytania — pamięć nie rośnie z rozmiarem raportu. Limit wierszy
     * egzekwuje Parser::parsujCsvStrumien (PrzekroczonyLimitImportu → status `blad`).
     *
     * @param resource             $fp
     * @param array<string, mixed> $meta
     */
    private function importujStrumienCsv($fp, string $checksum, ?string $idWiadomosci, array $meta): int
    {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), $checksum, $idWiadomosci, $meta);
        if ($importId === null) {
            return 0;
        }

        $db = Database::connect();

        try {
            $licznik   = 0;
            $wierszeDb = [];
            $incydenty = [];
            foreach ($this->parser->parsujCsvStrumien($fp) as $w) {
                $kategoria = $this->parser->kategoriaDlaWiersza($w);
                $sygnaly   = [];
                if (! empty($w['severity'])) {
                    $sygnaly['severity'] = $w['severity'];
                }
                if (! empty($w['tag'])) {
                    $sygnaly['tag'] = $w['tag'];
                }
                $wynikWagi = $this->waga->wylicz($kategoria, $sygnaly);
                $czas      = $this->normalizujCzas($w['timestamp']);

                $wierszeDb[] = [
                    'import_id'        => $importId,
                    'zaobserwowano_at' => $czas,
                    'ip'               => $w['ip'],
                    'port'             => $w['port'],
                    'protokol'         => $w['protokol'],
                    'tag'              => $w['tag'],
                    'severity'       => $w['severity'],
                    'klucz_dedupu'     => $w['klucz_dedupu'],
                    'payload_json'     => json_encode($w['raw'], JSON_UNESCAPED_UNICODE),
                ];

                $tytul = 'Shadowserver';
                if (! empty($w['tag'])) {
                    $tytul .= ' — ' . $w['tag'];
                }

                $incydenty[] = [
                    'zrodlo'           => $this->zrodlo(),
                    'klucz_dedupu'     => $w['klucz_dedupu'],
                    'podmiot_typ'      => 'ip',
                    'podmiot'          => $w['ip'],
                    'zaobserwowano_at' => $czas,
                    'waga'             => $wynikWagi['waga'],
                    'wersja_wagi'      => $wynikWagi['wersja'],
                    'kategoria'        => $kategoria,
                    'tytul'            => $tytul,
                    'port'             => $w['port'],
                    'protokol'         => $w['protokol'],
                    'import_id'        => $importId,
                    'id_w_zrodle'      => null, // uzupełnione w zapiszPaczke po zbiorczym INSERT wierszy źródła
                    'payload'          => [
                        'tag'       => $w['tag'],
                        'severity'  => $w['severity'],
                        'waga_meta' => $wynikWagi,
                    ],
                ];

                if (count($wierszeDb) >= \App\Libraries\IncydentZapis::ROZMIAR_PACZKI) {
                    $licznik   += $this->zapiszPaczke($db, $wierszeDb, $incydenty);
                    $wierszeDb = [];
                    $incydenty = [];
                }
            }
            if ($wierszeDb !== []) {
                $licznik += $this->zapiszPaczke($db, $wierszeDb, $incydenty);
            }

            if ($licznik === 0) {
                $this->koperta->zakonczKwarantanna($importId, 'CSV bez poprawnych wierszy IP');

                return 0;
            }

            $this->koperta->zakonczOk($importId, ['wierszy' => $licznik]);

            return $licznik;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Jedna paczka strumienia (issue #442 + #453): zbiorczy INSERT wierszy źródła
     * (INSERT … RETURNING id) + zapiszWiele incydentów — i od razu flush, żeby
     * pamięć nie rosła z liczbą wierszy.
     *
     * @param \CodeIgniter\Database\BaseConnection $db
     * @param list<array<string, mixed>>            $wierszeDb
     * @param list<array<string, mixed>>            $incydenty
     */
    private function zapiszPaczke($db, array $wierszeDb, array $incydenty): int
    {
        $ids = \App\Libraries\ZapisBatch::insertZwrocId($db, 'mcs_shadowserver_wiersze', array_values($wierszeDb));
        foreach (array_keys($wierszeDb) as $j => $i) {
            $incydenty[$i]['id_w_zrodle'] = (string) $ids[$j];
        }
        $this->zapis->zapiszWiele($incydenty);

        return count($wierszeDb);
    }

    private function normalizujCzas(string $timestamp): string
    {
        try {
            $dt = new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));

            return $dt->format('Y-m-d H:i:sP');
        } catch (\Exception) {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
        }
    }
}
