<?php

namespace McsPluginy\threatfox;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\WagaV1;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Przebieg pluginu threatfox: harmonogram → POST get_iocs → parsowanie → dopasowanie IP do prefiksów AS
 * → stan/IOC/trafienia/historia → incydenty.
 *
 * Zasada: wynik „czysto” zapisujemy tylko po kompletnym pobraniu i sparsowaniu odpowiedzi
 * (query_status ok z danymi albo no_results). Błąd pobrania/parsowania nie zmienia wyniku
 * ani trafień — tylko licznik błędów i backoff.
 */
class Serwis
{
    public const ZRODLO = 'threatfox';

    /** Fallback kategorii dla nieznanego threat_type — każdy IOC ThreatFox to zgłoszenie abuse. */
    public const KATEGORIA_DOMYSLNA = 'abuse';

    /**
     * Mapowanie threat_type ThreatFox → kategoria MCS (z dozwolonych przez EtykietyUi/WagaV1:
     * malware, amplification, vulnerability, abuse, reputation, exposure, other, spam, hijack).
     *
     * @var array<string, string>
     */
    public const MAPA_KATEGORII = [
        'malware'             => 'malware',
        'botnet_c2'           => 'malware',
        'command_and_control' => 'malware',
        'botnet'              => 'malware',
        'dropper'             => 'malware',
        'loader'              => 'malware',
        'ransomware'          => 'malware',
        'trojan'              => 'malware',
        'phishing'            => 'abuse',
        'scam'                => 'abuse',
        'spam'                => 'spam',
        'brute_force'         => 'abuse',
        'bruteforce'          => 'abuse',
        'scanner'             => 'abuse',
        'ids_alert'           => 'abuse',
        'torrent'             => 'abuse',
        'exploit'             => 'vulnerability',
        'vulnerability'       => 'vulnerability',
        'proxy'               => 'reputation',
        'vpn'                 => 'reputation',
        'dyn_node'            => 'reputation',
    ];

    private BaseConnection $db;
    private Klient $klient;
    private Parser $parser;
    private IncydentZapis $zapis;
    private ImportKoperta $koperta;
    private WagaV1 $waga;

    /** @var callable(): int */
    private $zegar;

    /** @var callable(): list<string> */
    private $naszeCidry;

    /** @var callable(): int */
    private $losowaMinuta;

    private int $interwalGodzin;

    /**
     * @param (callable(): list<string>)|null $naszeCidry
     */
    public function __construct(
        ?BaseConnection $db = null,
        ?Klient $klient = null,
        ?IncydentZapis $zapis = null,
        ?ImportKoperta $koperta = null,
        ?callable $zegar = null,
        ?callable $naszeCidry = null,
        ?int $interwalGodzin = null,
        ?callable $losowaMinuta = null,
    ) {
        $this->db             = $db ?? Database::connect();
        $this->klient         = $klient ?? Klient::fromOpcje();
        $this->parser         = new Parser();
        $this->zapis          = $zapis ?? new IncydentZapis($this->db);
        $this->koperta        = $koperta ?? new ImportKoperta(null, $this->db);
        $this->waga           = new WagaV1();
        $this->zegar          = $zegar ?? static fn (): int => time();
        $this->naszeCidry     = $naszeCidry ?? static fn (): array => (new \App\Libraries\Siec\As198050Matcher())->cidry();
        $this->interwalGodzin = $interwalGodzin ?? \App\Libraries\OpcjeMcs::int('mcs.threatfox.interwalGodzin');
        $this->losowaMinuta   = $losowaMinuta ?? static fn (): int => random_int(0, 59);
    }

    /**
     * @return array{status: string, msg: string, trafienia?: int, rekordy?: int, nowe?: int, zdjete?: int}
     *         status: pominieto | ok | blad
     */
    public function uruchom(): array
    {
        $teraz = ($this->zegar)();
        $stan  = $this->stan();

        $nastepne = self::ts($stan['nastepne_po'] ?? null);
        $proba    = self::ts($stan['ostatnia_proba_at'] ?? null);
        if (! Harmonogram::czyPora($teraz, $nastepne, $proba)) {
            $po = max((int) $nastepne, (int) $proba + Harmonogram::MIN_ODSTEP_S);

            return [
                'status' => 'pominieto',
                'msg'    => 'pominięto (następne po ' . gmdate('Y-m-d H:i', $po) . ' UTC)',
            ];
        }

        $this->zapiszStan(['ostatnia_proba_at' => self::fmt($teraz)]);
        $importId = $this->koperta->rozpocznij(self::ZRODLO, null, null, ['opis' => 'ThreatFox IOC (get_iocs, dni=' . $this->klient->dni() . ')']);

        $retry = 0;
        try {
            $res = $this->klient->pobierz();
            if (! $res['ok']) {
                $retry = (int) ($res['retry_after'] ?? 0);
                throw new RuntimeException(
                    'ThreatFox: ' . ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['http_code'])
                );
            }
            $sha     = hash('sha256', $res['body']);
            $rekordy = $this->parser->parsuj($res['body']);

            // ThreatFox zwraca ten sam ioc wielokrotnie (różne threat_type/id) — UNIQUE(ioc): dedup, pierwszy wygrywa.
            $unikalne = [];
            foreach ($rekordy as $r) {
                $unikalne[(string) $r['ioc']] ??= $r;
            }
            $rekordy = array_values($unikalne);

            $nasze = array_values(array_filter(
                array_map(static fn ($c) => Dopasowanie::normalizujCidr((string) $c), ($this->naszeCidry)()),
                static fn ($c) => $c !== null,
            ));
            if ($nasze === []) {
                throw new RuntimeException('ThreatFox: brak prefiksów w mcs_ustawienia.as198050_cidry — nie można ocenić');
            }

            $trafienia = Dopasowanie::dopasujIps($rekordy, $nasze);
            $wynik     = $this->zapiszWynik($teraz, $rekordy, $trafienia, $sha);
            $incydenty = $this->zapiszIncydenty($teraz, $importId);

            $zIp = count(array_filter($rekordy, static fn (array $r): bool => is_string($r['ip'] ?? null) && $r['ip'] !== ''));
            $msg = sprintf(
                'ThreatFox %s: ioc=%d (z ip=%d), trafienia=%d (nowe=%d, zdjęte=%d), prefiksów=%d, dni=%d',
                $trafienia === [] ? 'czysto' : 'TRAFIENIE',
                count($rekordy),
                $zIp,
                count($trafienia),
                $wynik['nowe'],
                $wynik['zdjete'],
                count($nasze),
                $this->klient->dni(),
            );
            if ($importId !== null) {
                $this->koperta->zakonczOk($importId, [
                    'rekordy'   => count($rekordy),
                    'trafienia' => count($trafienia),
                    'incydenty' => $incydenty,
                    'sha256'    => $sha,
                ]);
            }

            return [
                'status'    => 'ok',
                'msg'       => $msg,
                'trafienia' => count($trafienia),
                'rekordy'   => count($rekordy),
                'nowe'      => $wynik['nowe'],
                'zdjete'    => $wynik['zdjete'],
            ];
        } catch (\Throwable $e) {
            $bledy = (int) ($stan['bledy_z_rzedu'] ?? 0) + 1;
            $po    = Harmonogram::poBledzie($teraz, $bledy, $retry);
            $this->zapiszStan([
                'bledy_z_rzedu'   => $bledy,
                'ostatni_blad'    => mb_substr($e->getMessage(), 0, 1000),
                'ostatni_blad_at' => self::fmt($teraz),
                'nastepne_po'     => self::fmt($po),
            ]);
            $this->historia($teraz, 'blad', null, null, $e->getMessage() . ' (ponowna próba po ' . gmdate('Y-m-d H:i', $po) . ' UTC)');
            if ($importId !== null) {
                $this->koperta->zakonczBlad($importId, $e->getMessage());
            }

            return [
                'status' => 'blad',
                'msg'    => $e->getMessage() . ' — wynik bez zmian, ponowna próba po ' . gmdate('Y-m-d H:i', $po) . ' UTC',
            ];
        }
    }

    /**
     * threat_type ThreatFox → kategoria MCS (fallback: KATEGORIA_DOMYSLNA).
     */
    public static function kategoriaDla(string $threatType): string
    {
        $t = strtolower(trim($threatType));

        return self::MAPA_KATEGORII[$t] ?? self::KATEGORIA_DOMYSLNA;
    }

    /**
     * confidence_level (0–100) → etykieta wagi dla WagaV1 (max z bazą kategorii); null = bez sygnału.
     */
    public static function etykietaUfnosci(?int $confidence): ?string
    {
        if ($confidence === null) {
            return null;
        }
        if ($confidence >= 90) {
            return 'critical';
        }
        if ($confidence >= 60) {
            return 'high';
        }
        if ($confidence >= 30) {
            return 'medium';
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>>   $rekordy
     * @param array<string, array<string, mixed>> $trafienia
     *
     * @return array{nowe: int, zdjete: int}
     */
    private function zapiszWynik(int $teraz, array $rekordy, array $trafienia, string $sha): array
    {
        $czas  = self::fmt($teraz);
        $nowe  = 0;
        $zdjete = 0;

        $this->db->transException(true)->transStart();

        try {
            // --- IOC (wszystkie rekordy jako referencja; UNIQUE ioc) ---
            $istniejaceIoc = [];
            foreach ($this->db->table('mcs_threatfox_ioc')->get()->getResultArray() as $w) {
                $istniejaceIoc[(string) $w['ioc']] = $w;
            }
            $widzianeIoc = [];
            foreach ($rekordy as $r) {
                $ioc         = (string) $r['ioc'];
                $widzianeIoc[$ioc] = true;
                $wiersz      = [
                    'ioc_type'          => (string) $r['ioc_type'],
                    'ip'                => $r['ip'] ?? null,
                    'port'              => $r['port'] ?? null,
                    'threat_type'       => (string) $r['threat_type'],
                    'threat_type_desc'  => mb_substr((string) $r['threat_type_desc'], 0, 255),
                    'malware'           => mb_substr((string) $r['malware'], 0, 255),
                    'malware_printable' => mb_substr((string) $r['malware_printable'], 0, 255),
                    'confidence_level'  => $r['confidence_level'] ?? null,
                    'first_seen'        => (string) $r['first_seen'],
                    'last_seen'         => (string) $r['last_seen'],
                    'reporter'          => mb_substr((string) $r['reporter'], 0, 255),
                    'reference'         => (string) $r['reference'],
                    'tags'              => mb_substr(implode(',', $r['tags'] ?? []), 0, 255),
                    'threatfox_id'      => (string) $r['id'],
                    'aktywne'           => true,
                    'zdjete_at'         => null,
                    'ostatni_import_at' => $czas,
                ];
                $stary = $istniejaceIoc[$ioc] ?? null;
                if ($stary === null) {
                    $this->db->table('mcs_threatfox_ioc')->insert($wiersz + [
                        'ioc'               => $ioc,
                        'pierwszy_import_at' => $czas,
                    ]);
                } else {
                    $this->db->table('mcs_threatfox_ioc')->where('id', $stary['id'])->update($wiersz);
                }
            }
            $iocZdjete = 0;
            foreach ($istniejaceIoc as $ioc => $stary) {
                if (isset($widzianeIoc[$ioc]) || ! \App\Models\PluginModel::jakoBool($stary['aktywne'])) {
                    continue;
                }
                $this->db->table('mcs_threatfox_ioc')->where('id', $stary['id'])->update([
                    'aktywne'   => false,
                    'zdjete_at' => $czas,
                ]);
                $iocZdjete++;
            }

            // --- trafienia (UNIQUE ioc + nasz_prefiks) ---
            $istniejace = [];
            foreach ($this->db->table('mcs_threatfox_trafienia')->get()->getResultArray() as $w) {
                $istniejace[(string) $w['ioc'] . '|' . (string) $w['nasz_prefiks']] = $w;
            }

            foreach ($trafienia as $klucz => $t) {
                $payload = json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $stary   = $istniejace[$klucz] ?? null;
                $wiersz  = [
                    'ioc'          => (string) $t['ioc'],
                    'nasz_prefiks' => (string) $t['nasz_prefiks'],
                    'ip'           => (string) $t['ip'],
                    'port'         => $t['port'] ?? null,
                    'threat_type'  => (string) $t['threat_type'],
                    'kategoria'    => self::kategoriaDla((string) $t['threat_type']),
                    'aktywne'      => true,
                    'ostatnie_at'  => $czas,
                    'zdjete_at'    => null,
                    'payload_json' => $payload,
                ];
                if ($stary === null) {
                    $this->db->table('mcs_threatfox_trafienia')->insert($wiersz + ['pierwsze_at' => $czas]);
                    $nowe++;
                    $this->historia($teraz, 'nowe', (string) $t['ioc'], (string) $t['nasz_prefiks'], $this->opisTrafienia((string) $t['ioc'], (string) ($t['threat_type_desc'] ?? ''), (string) ($t['threat_type'] ?? ''), (string) $t['nasz_prefiks']));
                    continue;
                }
                $bylAktywny = \App\Models\PluginModel::jakoBool($stary['aktywne']);
                $this->db->table('mcs_threatfox_trafienia')->where('id', $stary['id'])->update($wiersz);
                if (! $bylAktywny) {
                    $nowe++;
                    $this->historia($teraz, 'nowe', (string) $t['ioc'], (string) $t['nasz_prefiks'], 'ponownie: ' . $this->opisTrafienia((string) $t['ioc'], (string) ($t['threat_type_desc'] ?? ''), (string) ($t['threat_type'] ?? ''), (string) $t['nasz_prefiks']));
                }
            }

            foreach ($istniejace as $klucz => $stary) {
                if (isset($trafienia[$klucz]) || ! \App\Models\PluginModel::jakoBool($stary['aktywne'])) {
                    continue;
                }
                $this->db->table('mcs_threatfox_trafienia')->where('id', $stary['id'])->update([
                    'aktywne'   => false,
                    'zdjete_at' => $czas,
                ]);
                $zdjete++;
                $this->historia($teraz, 'zdjete', (string) $stary['ioc'], (string) $stary['nasz_prefiks'], 'IOC zniknął z odpowiedzi ThreatFox');
            }

            $zIp = count(array_filter($rekordy, static fn (array $r): bool => is_string($r['ip'] ?? null) && $r['ip'] !== ''));
            $this->historia($teraz, 'pobrano', null, null, sprintf(
                'pobrano OK: ioc=%d (z ip=%d), trafienia=%d, nowe=%d, zdjęte=%d, ioc zdjęte=%d, sha256=%s',
                count($rekordy),
                $zIp,
                count($trafienia),
                $nowe,
                $zdjete,
                $iocZdjete,
                substr($sha, 0, 16),
            ));

            $this->zapiszStan([
                'ostatnie_ok_at'    => $czas,
                'nastepne_po'       => self::fmt(Harmonogram::poSukcesie($teraz, $this->interwalGodzin, ($this->losowaMinuta)())),
                'bledy_z_rzedu'     => 0,
                'wynik'             => $trafienia === [] ? 'czysto' : 'trafienie',
                'wynik_at'          => $czas,
                'aktywne_trafienia' => count($trafienia),
                'rekordy'           => count($rekordy),
            ]);

            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        return ['nowe' => $nowe, 'zdjete' => $zdjete];
    }

    /**
     * Incydent na każde aktywne trafienie (dedup: zrodlo + klucz_dedupu w IncydentZapis).
     *
     * @return int liczba zapisanych incydentów
     */
    private function zapiszIncydenty(int $teraz, ?int $importId): int
    {
        $n       = 0;
        $aktywne = $this->db->table('mcs_threatfox_trafienia')->where('aktywne', true)->get()->getResultArray();
        foreach ($aktywne as $t) {
            $rek  = is_string($t['payload_json'] ?? null) ? (json_decode($t['payload_json'], true) ?: []) : [];
            $kat  = self::kategoriaDla((string) $t['threat_type']);
            $conf = $rek['confidence_level'] ?? null;

            $sygnaly  = [];
            $etykieta = self::etykietaUfnosci(is_numeric($conf) ? (int) $conf : null);
            if ($etykieta !== null) {
                $sygnaly['severity'] = $etykieta;
            }
            $waga  = $this->waga->wylicz($kat, $sygnaly);
            $wynik = $this->zapis->zapisz([
                'zrodlo'           => self::ZRODLO,
                'klucz_dedupu'     => 'threatfox|' . $t['ioc'] . '|' . $t['nasz_prefiks'],
                'podmiot_typ'      => 'ip',
                'podmiot'          => (string) $t['ip'],
                'zaobserwowano_at' => self::fmt($teraz),
                'waga'             => $waga['waga'],
                'wersja_wagi'      => $waga['wersja'],
                'kategoria'        => $kat,
                'tytul'            => mb_substr($this->opisTrafienia((string) $t['ioc'], (string) ($rek['threat_type_desc'] ?? ''), (string) $t['threat_type'], (string) $t['nasz_prefiks']), 0, 255),
                'port'             => $t['port'] !== null && $t['port'] !== '' ? (int) $t['port'] : null,
                'protokol'         => null,
                'import_id'        => $importId,
                'id_w_zrodle'      => (string) $t['id'],
                'payload'          => [
                    'ioc'               => $t['ioc'],
                    'ioc_type'          => $rek['ioc_type'] ?? null,
                    'ip'                => $t['ip'],
                    'port'              => $t['port'],
                    'nasz_prefiks'      => $t['nasz_prefiks'],
                    'threat_type'       => $t['threat_type'],
                    'threat_type_desc'  => $rek['threat_type_desc'] ?? null,
                    'malware'           => $rek['malware'] ?? null,
                    'malware_printable' => $rek['malware_printable'] ?? null,
                    'confidence_level'  => $conf,
                    'first_seen'        => $rek['first_seen'] ?? null,
                    'last_seen'         => $rek['last_seen'] ?? null,
                    'reporter'          => $rek['reporter'] ?? null,
                    'reference'         => $rek['reference'] ?? null,
                    'tags'              => $rek['tags'] ?? [],
                    'kategoria'         => $kat,
                    'waga_meta'         => $waga,
                ],
            ]);
            $this->db->table('mcs_threatfox_trafienia')->where('id', $t['id'])->update(['incydent_id' => $wynik['incydent_id']]);
            $n++;
        }

        return $n;
    }

    private function opisTrafienia(string $ioc, string $threatTypeDesc, string $threatType, string $naszPrefiks): string
    {
        $co = $threatTypeDesc !== '' ? $threatTypeDesc : ($threatType !== '' ? $threatType : 'IOC');

        return 'ThreatFox: ' . $ioc . ' (' . $co . ') w naszym prefiksie ' . $naszPrefiks;
    }

    /**
     * @return array<string, mixed>
     */
    public function stan(): array
    {
        $w = $this->db->table('mcs_threatfox_stan')->where('id', 1)->get()->getRowArray();
        if (! is_array($w)) {
            $this->db->table('mcs_threatfox_stan')->insert(['id' => 1, 'wynik' => 'brak', 'bledy_z_rzedu' => 0, 'aktywne_trafienia' => 0, 'rekordy' => 0]);

            return ['id' => 1, 'wynik' => 'brak', 'bledy_z_rzedu' => 0];
        }

        return $w;
    }

    /**
     * @param array<string, mixed> $dane
     */
    private function zapiszStan(array $dane): void
    {
        $this->stan();
        $this->db->table('mcs_threatfox_stan')->where('id', 1)->update($dane);
    }

    private function historia(int $teraz, string $zdarzenie, ?string $ioc, ?string $prefiks, string $opis): void
    {
        $this->db->table('mcs_threatfox_historia')->insert([
            'at'           => self::fmt($teraz),
            'zdarzenie'    => $zdarzenie,
            'ioc'          => $ioc,
            'nasz_prefiks' => $prefiks,
            'opis'         => mb_substr($opis, 0, 2000),
        ]);
    }

    public static function fmt(int $ts): string
    {
        return gmdate('Y-m-d H:i:sP', $ts);
    }

    public static function ts(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $t = strtotime((string) $v);

        return $t === false ? null : $t;
    }
}
