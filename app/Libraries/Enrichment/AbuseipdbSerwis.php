<?php

namespace App\Libraries\Enrichment;

use App\Models\EnrichmentAbuseipdbModel;

/**
 * Enrichment AbuseIPDB: cache TTL + lookup na żądanie.
 * Świadomie NIE zapisuje do mcs_incydenty / mcs_obserwacje / badge.
 *
 * Szkielet sprawdz() (walidacja IP → cache → fixture → API → upsert)
 * jest w {@see EnrichmentSerwisBaza}; tu tylko klient, konfiguracja
 * klucza i mapowanie odpowiedzi.
 */
class AbuseipdbSerwis extends EnrichmentSerwisBaza
{
    private AbuseipdbClient $client;

    public function __construct(
        ?AbuseipdbClient $client = null,
        ?EnrichmentAbuseipdbModel $model = null,
        ?int $cacheTtl = null,
        ?string $fixturePath = null,
    ) {
        $this->client = $client ?? AbuseipdbClient::fromEnv();
        $this->ustawCache(
            $model ?? model(EnrichmentAbuseipdbModel::class),
            $cacheTtl,
            $fixturePath,
            'mcs.abuseipdb.cacheTtl',
            'mcs.abuseipdb.fixturePath',
        );
    }

    public function hasApiKey(): bool
    {
        return $this->client->hasApiKey();
    }

    protected function maZrodlo(): bool
    {
        return $this->hasApiKey();
    }

    protected function brakZrodlaKomunikat(): string
    {
        return 'Brak mcs.abuseipdb.apiKey — ustaw w .env albo mcs.abuseipdb.fixturePath do dry-run.';
    }

    /**
     * @param array<string, mixed> $kontekst
     *
     * @return array{ok:bool,z_cache:bool,tryb:string,blad:?string,ip:string,wynik:?array<string,mixed>}
     */
    protected function zFixture(string $ip, array $kontekst): array
    {
        $raw = $this->wczytajFixtureRaw();
        if ($raw === null) {
            return $this->odpowiedz(false, false, $this->trybFixture(), 'Nie można odczytać fixturePath.', $ip, null);
        }

        $json = json_decode($raw, true);
        $data = is_array($json) ? ($json['data'] ?? $json) : null;
        if (! is_array($data)) {
            return $this->odpowiedz(false, false, $this->trybFixture(), 'Fixture JSON nie zawiera obiektu data.', $ip, null);
        }

        // Dry-run: nadpisz IP z żądania, żeby cache był spójny z argumentem CLI/API.
        $data['ipAddress'] = $ip;
        $wiersz            = $this->znormalizujIZapisz($ip, $data, $this->trybFixture());

        return $this->odpowiedz(true, false, $this->trybFixture(), null, $ip, $this->wierszDoWyniku($wiersz));
    }

    /**
     * @param array<string, mixed> $kontekst
     *
     * @return array{ok:bool,z_cache:bool,tryb:string,blad:?string,ip:string,wynik:?array<string,mixed>}
     */
    protected function zZrodla(string $ip, array $kontekst): array
    {
        $resp = $this->client->checkIp($ip);
        if (! $resp['ok'] || ! is_array($resp['data'])) {
            return $this->odpowiedz(false, false, $this->trybZrodla(), $resp['error'] !== '' ? $resp['error'] : 'Błąd API AbuseIPDB', $ip, null);
        }

        $data = $resp['data']['data'] ?? null;
        if (! is_array($data)) {
            return $this->odpowiedz(false, false, $this->trybZrodla(), 'Nieoczekiwana odpowiedź API (brak data.data).', $ip, null);
        }

        $wiersz = $this->znormalizujIZapisz($ip, $data, $this->trybZrodla());

        return $this->odpowiedz(true, false, $this->trybZrodla(), null, $ip, $this->wierszDoWyniku($wiersz));
    }

    /**
     * @param array<string, mixed> $data pole `data` z odpowiedzi AbuseIPDB
     *
     * @return array<string, mixed>
     */
    private function znormalizujIZapisz(string $ip, array $data, string $zrodlo): array
    {
        $teraz  = gmdate('Y-m-d H:i:sP');
        $last   = $data['lastReportedAt'] ?? null;
        $lastAt = null;
        if (is_string($last) && $last !== '') {
            $ts     = strtotime($last);
            $lastAt = $ts !== false ? gmdate('Y-m-d H:i:sP', $ts) : null;
        }

        $wiersz = [
            'abuse_confidence_score' => isset($data['abuseConfidenceScore']) ? (int) $data['abuseConfidenceScore'] : null,
            'total_reports'          => isset($data['totalReports']) ? (int) $data['totalReports'] : null,
            'last_reported_at'       => $lastAt,
            'country_code'           => isset($data['countryCode']) ? (string) $data['countryCode'] : null,
            'isp'                    => isset($data['isp']) ? (string) $data['isp'] : null,
            'usage_type'             => isset($data['usageType']) ? (string) $data['usageType'] : null,
            'is_whitelisted'         => array_key_exists('isWhitelisted', $data)
                ? filter_var($data['isWhitelisted'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null,
            'raw_json'               => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'zrodlo_danych'          => $zrodlo,
            'checked_at'             => $teraz,
        ];

        return $this->zapiszWiersz($ip, $wiersz);
    }

    /**
     * @param array<string, mixed> $wiersz
     *
     * @return array<string, mixed>
     */
    protected function wierszDoWyniku(array $wiersz): array
    {
        $raw = $wiersz['raw_json'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : $raw;
        }

        return [
            'ip'                     => $wiersz['ip'] ?? null,
            'abuse_confidence_score' => $wiersz['abuse_confidence_score'] ?? null,
            'total_reports'          => $wiersz['total_reports'] ?? null,
            'last_reported_at'       => $wiersz['last_reported_at'] ?? null,
            'country_code'           => $wiersz['country_code'] ?? null,
            'isp'                    => $wiersz['isp'] ?? null,
            'usage_type'             => $wiersz['usage_type'] ?? null,
            'is_whitelisted'         => $wiersz['is_whitelisted'] ?? null,
            'zrodlo_danych'          => $wiersz['zrodlo_danych'] ?? null,
            'checked_at'             => $wiersz['checked_at'] ?? null,
            'raw'                    => $raw,
        ];
    }
}
