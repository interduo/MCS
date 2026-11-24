<?php

namespace McsPluginy\mojecert;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV1;
use App\Models\PluginModel;
use Config\Database;
use RuntimeException;

/**
 * Plugin źródła MojeCert (moje.cert.pl) — wyniki skanów.
 *
 * Tryby pobierz():
 * 1) plik fixture — gdy ustawione mcs.mojecert.fixturePath (dev / test)
 * 2) REST API — mcs.mojecert.apiToken (+ opcjonalnie baseUrl)
 *
 * klucz_dedupu: sha256 jak w LMSMojeCertPlugin (scan + org + subnet + ip + ts + target + type).
 */
class Plugin implements SourcePluginInterface
{
    private Parser $parser;
    private ImportKoperta $koperta;
    private IncydentZapis $zapis;
    private WagaV1 $waga;
    private ?ApiClient $apiClient;

    public function __construct(
        ?Parser $parser = null,
        ?ImportKoperta $koperta = null,
        ?IncydentZapis $zapis = null,
        ?WagaV1 $waga = null,
        ?ApiClient $apiClient = null,
    ) {
        $this->parser    = $parser ?? new Parser();
        $this->koperta   = $koperta ?? new ImportKoperta();
        $this->zapis     = $zapis ?? new IncydentZapis();
        $this->waga      = $waga ?? new WagaV1();
        $this->apiClient = $apiClient;
    }

    public function zrodlo(): string
    {
        return 'mojecert';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.mojecert.fixturePath', ''));
        if ($fixture !== '') {
            $liczba = $this->importujPlik($fixture);
            $this->aktualizujHealth(
                true,
                $liczba > 0
                    ? "import fixture OK ({$liczba} wierszy)"
                    : 'brak nowych danych (duplikat checksum albo puste results)',
            );

            return;
        }

        $token = trim((string) env('mcs.mojecert.apiToken', ''));
        if ($token === '') {
            $msg = 'MojeCert: ustaw mcs.mojecert.fixturePath (smoke) albo mcs.mojecert.apiToken w .env';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        try {
            $stats = $this->importujZApi();
            $this->aktualizujHealth(
                true,
                sprintf(
                    'import API OK (orgs=%d, subnets=%d, skany=%d)',
                    $stats['orgs'],
                    $stats['subnets'],
                    $stats['skany'],
                ),
            );
        } catch (\Throwable $e) {
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array{ok: bool, msg: string}
     */
    public function health(): array
    {
        $wiersz = model(PluginModel::class)->find($this->zrodlo());
        if (! is_array($wiersz)) {
            return ['ok' => false, 'msg' => 'brak w rejestrze mcs_pluginy'];
        }

        $ok  = array_key_exists('health_ok', $wiersz) ? $wiersz['health_ok'] : null;
        $msg = (string) ($wiersz['health_msg'] ?? '');

        if ($msg === '' && $ok === null) {
            $msg = $this->opisKonfiguracji();
        }

        return [
            'ok'  => (bool) ($ok ?? false),
            'msg' => $msg !== '' ? $msg : 'brak przebiegu',
        ];
    }

    /**
     * @return array{
     *   skrot: string,
     *   tryb_aktywny: string,
     *   tryb_etykieta: string,
     *   pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>
     * }
     */
    public function opisPolaczenia(): array
    {
        $plik   = trim((string) env('mcs.mojecert.fixturePath', ''));
        $base   = trim((string) env('mcs.mojecert.baseUrl', 'https://moje.cert.pl'));
        $client = $this->apiClient ?? ApiClient::fromEnv();
        $tokOk  = $client->hasToken();

        return PluginOpisPolaczenia::plikIKanal(
            $plik,
            'HTTP moje.cert.pl',
            $base !== '' ? $base : 'https://moje.cert.pl',
            $tokOk,
            'http',
            'HTTP',
            [
                PluginOpisPolaczenia::pozycja(
                    'Token API',
                    PluginOpisPolaczenia::sekret($tokOk),
                    false,
                    $tokOk,
                ),
            ],
        );
    }

    /**
     * Import jednego pliku fixture JSON.
     */
    public function importujPlik(string $sciezka): int
    {
        if (! is_readable($sciezka)) {
            throw new RuntimeException('MojeCert: nie mozna odczytac pliku ' . $sciezka);
        }

        try {
            ImportLimity::sprawdzPlik($sciezka, 'MojeCert');
        } catch (PrzekroczonyLimitImportu $e) {
            $this->koperta->oznaczBlad(
                $this->zrodlo(),
                hash_file('sha256', $sciezka) ?: null,
                null,
                ['tryb' => 'fixture', 'plik' => basename($sciezka)],
                $e->getMessage(),
            );
            throw $e;
        }

        $tresc = (string) file_get_contents($sciezka);
        $pakiet = $this->parser->parsujFixtureJson($tresc);

        return $this->importujWyniki(
            $pakiet['results'],
            $pakiet['org_slug'],
            $pakiet['org_name'],
            $pakiet['subnet_cidr'],
            hash('sha256', $tresc),
            'fixture:' . basename($sciezka),
            ['tryb' => 'fixture', 'plik' => basename($sciezka)],
            null,
        );
    }

    /**
     * @return array{orgs: int, subnets: int, skany: int}
     */
    public function importujZApi(): array
    {
        $client = $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasToken()) {
            throw new RuntimeException('MojeCert: mcs.mojecert.apiToken is not set');
        }

        $syncDaysBack = \App\Libraries\OpcjeMcs::int('mcs.mojecert.syncDaysBack');
        if ($syncDaysBack < 0) {
            $syncDaysBack = 0;
        }
        $maxPages = \App\Libraries\OpcjeMcs::int('mcs.mojecert.syncMaxPagesPerSubnet');
        if ($maxPages < 1) {
            $maxPages = 100;
        }
        $cutoffUnix = $syncDaysBack >= 1 ? (time() - ($syncDaysBack * 86400)) : null;

        $orgSlugs = [];
        $page     = 1;
        while ($page <= 500) {
            $r = $client->listOrganizations(['page' => $page]);
            if (! $r['ok']) {
                throw new RuntimeException(
                    'MojeCert: organizations list failed: ' . ($r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['http_code']),
                );
            }
            $data = $r['data'];
            if (! is_array($data) || empty($data['results'])) {
                break;
            }
            foreach ($data['results'] as $o) {
                if (is_array($o) && ! empty($o['slug'])) {
                    $orgSlugs[(string) $o['slug']] = true;
                }
            }
            if (empty($data['next'])) {
                break;
            }
            ++$page;
        }

        $stats = ['orgs' => count($orgSlugs), 'subnets' => 0, 'skany' => 0];

        // Jedna koperta na cały przebieg API (unikalny id — LMS też upsertuje przy każdym sync).
        $runId    = 'mojecert:api:' . gmdate('Y-m-d\TH:i:s') . ':' . bin2hex(random_bytes(4));
        $importId = $this->koperta->rozpocznij(
            $this->zrodlo(),
            hash('sha256', $runId),
            $runId,
            [
                'tryb'            => 'api',
                'sync_days_back'  => $syncDaysBack,
                'orgs'            => $stats['orgs'],
            ],
        );
        if ($importId === null) {
            return $stats;
        }

        $db    = Database::connect();
        $bledy = [];

        try {
            foreach (array_keys($orgSlugs) as $orgSlug) {
                $rg = $client->getOrganization($orgSlug);
                if (! $rg['ok'] || ! is_array($rg['data'])) {
                    $bledy[] = $orgSlug . ': ' . (string) ($rg['error'] ?? 'brak danych organizacji');
                    continue;
                }
                $detail  = $rg['data'];
                $orgName = isset($detail['name']) ? (string) $detail['name'] : '';
                $subnets = isset($detail['subnets']) && is_array($detail['subnets']) ? $detail['subnets'] : [];

                foreach ($subnets as $sn) {
                    if (! is_array($sn)) {
                        continue;
                    }
                    $subnetPath = SubnetHelper::apiPathFromSn($sn);
                    if ($subnetPath === null) {
                        continue;
                    }
                    $subnetStorage = SubnetHelper::storageLabelFromSn($sn) ?? $subnetPath;
                    if (array_key_exists('verified', $sn) && $sn['verified'] === false) {
                        continue;
                    }
                    ++$stats['subnets'];
                    $zebrane = [];

                    for ($p = 1; $p <= $maxPages; ++$p) {
                        $scanQuery = ['page' => $p];
                        if ($cutoffUnix !== null) {
                            $scanQuery['ordering'] = ['-timestamp'];
                        }
                        $rs = $client->listOrganizationSubnetScanResults($orgSlug, $subnetPath, $scanQuery);
                        if (! $rs['ok']) {
                            $bledy[] = $orgSlug . ' ' . $subnetStorage . ' str. ' . $p . ': ' . (string) ($rs['error'] ?? 'błąd API');
                            break;
                        }
                        $sd = $rs['data'];
                        if (! is_array($sd) || empty($sd['results']) || ! is_array($sd['results'])) {
                            break;
                        }

                        $pozaOknem = false;
                        foreach ($sd['results'] as $row) {
                            if (! is_array($row)) {
                                continue;
                            }
                            $tsRaw = isset($row['timestamp']) ? (string) $row['timestamp'] : '';
                            if ($cutoffUnix !== null && $tsRaw !== '') {
                                $rowUnix = strtotime($tsRaw);
                                if ($rowUnix !== false && $rowUnix < $cutoffUnix) {
                                    $pozaOknem = true;
                                    break;
                                }
                            }
                            // zbiorczo (issue #442): wiersze tylko przygotowane, zapis paczkowy po stronie
                            $z = $this->przygotujWiersz($row, $orgSlug, $orgName, $subnetStorage);
                            if ($z !== null) {
                                $zebrane[] = $z;
                            }
                        }

                        if ($pozaOknem || empty($sd['next'])) {
                            break;
                        }
                    }
                    $stats['skany'] += $this->zapiszZebrane($db, $importId, $zebrane);
                }
            }

            if ($bledy !== []) {
                // Niepełny import nie może wyglądać jak „czysto”: koperta = błąd, health = błąd.
                throw new \RuntimeException(
                    'MojeCert API: ' . count($bledy) . ' błędów (zapisano ' . $stats['skany'] . ' wierszy). '
                    . implode('; ', array_slice($bledy, 0, 3)),
                );
            }

            $this->koperta->zakonczOk($importId, [
                'orgs'    => $stats['orgs'],
                'subnets' => $stats['subnets'],
                'wierszy' => $stats['skany'],
            ]);

            return $stats;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed>       $meta
     * @param int|null                   $cutoffUnix
     */
    private function importujWyniki(
        array $results,
        string $orgSlug,
        string $orgName,
        string $subnetCidr,
        string $checksum,
        string $idWiadomosci,
        array $meta,
        ?int $cutoffUnix,
    ): int {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), $checksum, $idWiadomosci, $meta);
        if ($importId === null) {
            return 0;
        }

        $db = Database::connect();

        try {
            if ($results === []) {
                $this->koperta->zakonczKwarantanna($importId, 'MojeCert: puste results');

                return 0;
            }

            ImportLimity::sprawdzLiczbeWierszy('MojeCert', count($results));

            $zebrane = [];
            foreach ($results as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $tsRaw = isset($row['timestamp']) ? (string) $row['timestamp'] : '';
                if ($cutoffUnix !== null && $tsRaw !== '') {
                    $rowUnix = strtotime($tsRaw);
                    if ($rowUnix !== false && $rowUnix < $cutoffUnix) {
                        continue;
                    }
                }
                // zbiorczo (issue #442): wiersze tylko przygotowane, zapis paczkowy poniżej
                $z = $this->przygotujWiersz($row, $orgSlug, $orgName, $subnetCidr);
                if ($z !== null) {
                    $zebrane[] = $z;
                }
            }
            $licznik = $this->zapiszZebrane($db, $importId, $zebrane);

            $this->koperta->zakonczOk($importId, ['wierszy' => $licznik]);

            return $licznik;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mapuje wiersz API/fixture na parę (wiersz mcs_mojecert_skany, dane incydentu) — bez zapisu do DB.
     *
     * @param array<string, mixed> $row
     *
     * @return array{skany: array<string, mixed>, incydent: array<string, mixed>}|null
     */
    private function przygotujWiersz(
        array $row,
        string $orgSlug,
        string $orgName,
        string $subnetStorage,
    ): ?array {
        $mapped = $this->parser->mapujWiersz($row, $orgSlug, $subnetStorage);
        if ($mapped === null) {
            return null;
        }

        $sygnaly = [];
        if ($mapped['severity'] !== '') {
            $sygnaly['severity'] = $mapped['severity'];
        }
        if ($mapped['report_type'] !== '') {
            $sygnaly['report_type'] = $mapped['report_type'];
        }
        $wynikWagi = $this->waga->wylicz($mapped['kategoria'], $sygnaly);

        $tytul = 'MojeCert';
        if ($mapped['report_type'] !== '') {
            $tytul .= ' — ' . $mapped['report_type'];
        }

        return [
            'skany'    => [
                'import_id'        => 0, // uzupełnione w zapiszZebrane
                'zaobserwowano_at' => $mapped['zaobserwowano_at'],
                'ip'               => $mapped['ip'],
                'org_slug'         => $orgSlug,
                'org_name'         => $orgName,
                'subnet_cidr'      => $subnetStorage,
                'report_type'      => $mapped['report_type'],
                'severity'         => $mapped['severity'],
                'target'           => $mapped['target'],
                'klucz_dedupu'     => $mapped['klucz_dedupu'],
                'payload_json'     => json_encode([
                    'raw'            => $mapped['raw'],
                    'last_domain'    => $mapped['last_domain'],
                    'detail_excerpt' => $mapped['detail_excerpt'],
                    'name_title'     => $mapped['name_title'],
                ], JSON_UNESCAPED_UNICODE),
            ],
            'incydent' => [
                'zrodlo'           => $this->zrodlo(),
                'klucz_dedupu'     => $mapped['klucz_dedupu'],
                'podmiot_typ'      => 'ip',
                'podmiot'          => $mapped['ip'],
                'zaobserwowano_at' => $mapped['zaobserwowano_at'],
                'waga'             => $wynikWagi['waga'],
                'wersja_wagi'      => $wynikWagi['wersja'],
                'kategoria'        => $mapped['kategoria'],
                'tytul'            => $tytul,
                'import_id'        => 0, // uzupełnione w zapiszZebrane
                'payload'          => [
                    'org_slug'     => $orgSlug,
                    'subnet_cidr'  => $subnetStorage,
                    'report_type'  => $mapped['report_type'],
                    'severity'     => $mapped['severity'],
                    'target'       => $mapped['target'],
                    'last_domain'  => $mapped['last_domain'],
                    'waga_meta'    => $wynikWagi,
                ],
            ],
        ];
    }

    /**
     * Zbiorczy zapis przygotowanych wierszy (issue #442): jeden SELECT dedup po klucz_dedupu
     * (whereIn), INSERT … paczkami mcs_mojecert_skany (id w kolejności), incydenty jednym zapiszWiele.
     * Klucz już istniejący w tabeli skanów = duplikat → pomijamy (jak check-then-insert per wiersz).
     *
     * @param \CodeIgniter\Database\BaseConnection $db
     * @param list<array{skany: array<string, mixed>, incydent: array<string, mixed>}> $zebrane
     *
     * @return int liczba faktycznie zapisanych (nowych) skanów
     */
    private function zapiszZebrane($db, int $importId, array $zebrane): int
    {
        if ($zebrane === []) {
            return 0;
        }

        // Dedup w paczce po klucz_dedupu — pierwszy wygrywa (duplikat w źródle był pomijany).
        $poKluczu = [];
        foreach ($zebrane as $z) {
            $poKluczu[$z['skany']['klucz_dedupu']] ??= $z;
        }

        // Jeden SELECT: które klucze są już w mcs_mojecert_skany.
        $istniejace = $db->table('mcs_mojecert_skany')
            ->select('klucz_dedupu')
            ->whereIn('klucz_dedupu', array_keys($poKluczu))
            ->get()
            ->getResultArray();
        $maKlucze = array_column($istniejace, 'klucz_dedupu');

        $nowe = [];
        foreach ($poKluczu as $k => $z) {
            if (! in_array($k, $maKlucze, true)) {
                $nowe[] = $z;
            }
        }
        if ($nowe === []) {
            return 0;
        }

        // INSERT skanów paczkami — id w kolejności VALUES.
        $daneIncydenty = [];
        foreach (array_chunk($nowe, \App\Libraries\IncydentZapis::ROZMIAR_PACZKI, true) as $paczka) {
            $skany = [];
            foreach ($paczka as $z) {
                $z['skany']['import_id'] = $importId;
                $skany[]                 = $z['skany'];
            }
            $ids = \App\Libraries\ZapisBatch::insertZwrocId($db, 'mcs_mojecert_skany', $skany);
            foreach (array_keys($paczka) as $j => $i) {
                $z = $paczka[$i];
                $z['incydent']['import_id']   = $importId;
                $z['incydent']['id_w_zrodle'] = (string) $ids[$j];
                $daneIncydenty[]              = $z['incydent'];
            }
        }
        $this->zapis->zapiszWiele($daneIncydenty);

        return count($nowe);
    }

    private function opisKonfiguracji(): string
    {
        if (trim((string) env('mcs.mojecert.fixturePath', '')) !== '') {
            return 'konfiguracja: tryb fixturePath (jeszcze bez przebiegu)';
        }
        if (trim((string) env('mcs.mojecert.apiToken', '')) !== '') {
            return 'konfiguracja: API token gotowy (jeszcze bez przebiegu)';
        }

        return 'brak fixturePath i apiToken (uzupelnij .env)';
    }

    private function aktualizujHealth(bool $ok, string $msg): void
    {
        model(PluginModel::class)->update($this->zrodlo(), [
            'health_at'  => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP'),
            'health_ok'  => $ok,
            'health_msg' => mb_substr($msg, 0, 500),
        ]);
    }
}
