<?php

namespace App\Libraries\Enrichment;

use App\Libraries\PluginOpisPolaczenia;
use App\Models\EnrichmentIsmaliciousModel;
use App\Models\PluginModel;

/**
 * Enrichment isMalicious: cache TTL + lookup na żądanie.
 * Świadomie NIE zapisuje do mcs_incydenty / mcs_obserwacje / badge.
 *
 * Szkielet sprawdz() (walidacja IP → cache → fixture → API → upsert)
 * jest w {@see EnrichmentSerwisBaza}; tu tylko klient, konfiguracja
 * credentials i mapowanie odpowiedzi.
 */
class IsmaliciousSerwis extends EnrichmentSerwisBaza
{
    private IsmaliciousClient $client;

    public function __construct(
        ?IsmaliciousClient $client = null,
        ?EnrichmentIsmaliciousModel $model = null,
        ?int $cacheTtl = null,
        ?string $fixturePath = null,
    ) {
        $this->client = $client ?? IsmaliciousClient::fromEnv();
        $this->ustawCache(
            $model ?? model(EnrichmentIsmaliciousModel::class),
            $cacheTtl,
            $fixturePath,
            'mcs.ismalicious.cacheTtl',
            'mcs.ismalicious.fixturePath',
        );
    }

    public function hasCredentials(): bool
    {
        return $this->client->hasCredentials();
    }

    protected function maZrodlo(): bool
    {
        return $this->hasCredentials();
    }

    protected function brakZrodlaKomunikat(): string
    {
        return $this->client->brakCredentialsKomunikat();
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
        return PluginOpisPolaczenia::plikIKanal(
            $this->fixturePath,
            'HTTP isMalicious',
            $this->client->getBaseUrl() . '/check',
            $this->hasCredentials(),
            'http',
            'HTTP',
            [
                PluginOpisPolaczenia::pozycja(
                    'Klucz API',
                    PluginOpisPolaczenia::sekret($this->hasCredentials()),
                    false,
                    $this->hasCredentials(),
                ),
                PluginOpisPolaczenia::pozycja('Poziom enrichment', $this->client->enrichment(), false),
            ],
        );
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
        if (! is_array($json)) {
            return $this->odpowiedz(false, false, $this->trybFixture(), 'Fixture isMalicious nie jest poprawnym JSON.', $ip, null);
        }

        $wiersz = $this->znormalizujIZapisz($ip, $json, $this->trybFixture());

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
            return $this->odpowiedz(false, false, $this->trybZrodla(), $resp['error'] !== '' ? $resp['error'] : 'Błąd API isMalicious', $ip, null);
        }

        $wiersz = $this->znormalizujIZapisz($ip, $resp['data'], $this->trybZrodla());

        return $this->odpowiedz(true, false, $this->trybZrodla(), null, $ip, $this->wierszDoWyniku($wiersz));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function znormalizujIZapisz(string $ip, array $data, string $zrodlo): array
    {
        $classification = $data['classification'] ?? null;
        $classStr       = null;
        if (is_string($classification) && $classification !== '') {
            $classStr = $classification;
        } elseif (is_array($classification)) {
            $classStr = (string) ($classification['category']
                ?? $classification['label']
                ?? $classification['name']
                ?? json_encode($classification, JSON_UNESCAPED_UNICODE));
            if (strlen($classStr) > 128) {
                $classStr = substr($classStr, 0, 125) . '…';
            }
        }

        $malicious = $data['malicious'] ?? null;
        if (is_string($malicious)) {
            $malicious = PluginModel::jakoBool($malicious);
        } elseif (! is_bool($malicious)) {
            $malicious = null;
        }

        $listed = $data['blocklistListed'] ?? null;
        if (is_string($listed)) {
            $listed = PluginModel::jakoBool($listed);
        } elseif (! is_bool($listed)) {
            $listed = null;
        }

        $wiersz = [
            'malicious'         => $malicious,
            'risk_score'        => isset($data['riskScore']) && is_numeric($data['riskScore'])
                ? (float) $data['riskScore'] : null,
            'confidence'        => isset($data['confidence']) && is_numeric($data['confidence'])
                ? (float) $data['confidence'] : null,
            'blocklist_hits'    => isset($data['blocklistHits']) && is_numeric($data['blocklistHits'])
                ? (int) $data['blocklistHits'] : null,
            'blocklist_listed'  => $listed,
            'classification'    => $classStr,
            'raw_json'          => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'zrodlo_danych'     => $zrodlo,
            'checked_at'        => gmdate('Y-m-d H:i:sP'),
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
        $malicious = $wiersz['malicious'] ?? null;
        if (is_string($malicious)) {
            $malicious = PluginModel::jakoBool($malicious);
        }
        $listed = $wiersz['blocklist_listed'] ?? null;
        if (is_string($listed)) {
            $listed = PluginModel::jakoBool($listed);
        }

        return [
            'ip'                => (string) ($wiersz['ip'] ?? ''),
            'malicious'         => $malicious,
            'risk_score'        => $wiersz['risk_score'] ?? null,
            'confidence'        => $wiersz['confidence'] ?? null,
            'blocklist_hits'    => $wiersz['blocklist_hits'] ?? null,
            'blocklist_listed'  => $listed,
            'classification'    => $wiersz['classification'] ?? null,
            'zrodlo_danych'     => $wiersz['zrodlo_danych'] ?? null,
            'checked_at'        => $wiersz['checked_at'] ?? null,
        ];
    }
}
