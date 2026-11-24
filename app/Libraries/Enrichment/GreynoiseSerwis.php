<?php

namespace App\Libraries\Enrichment;

use App\Libraries\PluginOpisPolaczenia;
use App\Models\EnrichmentGreynoiseModel;
use App\Models\PluginModel;

/**
 * Enrichment GreyNoise Community: cache TTL + lookup na żądanie.
 * Nie zapisuje do mcs_incydenty / badge. Cron nie woła API.
 *
 * Szkielet sprawdz() (walidacja IP → cache → fixture → API → upsert)
 * jest w {@see EnrichmentSerwisBaza}; tu tylko klient, konfiguracja
 * credentials i mapowanie odpowiedzi.
 */
class GreynoiseSerwis extends EnrichmentSerwisBaza
{
    private GreynoiseClient $client;

    public function __construct(
        ?GreynoiseClient $client = null,
        ?EnrichmentGreynoiseModel $model = null,
        ?int $cacheTtl = null,
        ?string $fixturePath = null,
    ) {
        $this->client = $client ?? GreynoiseClient::fromEnv();
        $this->ustawCache(
            $model ?? model(EnrichmentGreynoiseModel::class),
            $cacheTtl,
            $fixturePath,
            'mcs.greynoise.cacheTtl',
            'mcs.greynoise.fixturePath',
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

    protected function trybFixture(): string
    {
        return 'plik';
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
        $jest = $this->hasCredentials();

        return PluginOpisPolaczenia::zloz(
            $jest ? 'http' : 'brak',
            $jest ? 'HTTP' : 'brak klucza',
            'GET api.greynoise.io/v3/community/{ip} (poza badge, ręcznie z /ip/{ip})',
            [
                PluginOpisPolaczenia::pozycja('Klucz API', PluginOpisPolaczenia::sekret($jest), false, $jest),
                PluginOpisPolaczenia::pozycja('Limit', 'cron nie odpytuje API', false, true),
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
        $raw  = $this->wczytajFixtureRaw();
        $data = $raw !== null ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            return $this->odpowiedz(false, false, $this->trybFixture(), 'Nieczytelny plik GreyNoise.', $ip, null);
        }
        $wiersz = $this->zapisz($ip, 200, $data, $this->trybFixture());

        return $this->odpowiedz(true, false, $this->trybFixture(), null, $ip, $this->wierszDoWyniku($wiersz));
    }

    /**
     * @param array<string, mixed> $kontekst
     *
     * @return array{ok:bool,z_cache:bool,tryb:string,blad:?string,ip:string,wynik:?array<string,mixed>}
     */
    protected function zZrodla(string $ip, array $kontekst): array
    {
        $resp = $this->client->lookup($ip);
        if (! $resp['ok'] || ! is_array($resp['data'])) {
            return $this->odpowiedz(false, false, $this->trybZrodla(), $resp['error'] !== '' ? $resp['error'] : 'Błąd API GreyNoise', $ip, null);
        }

        $wiersz = $this->zapisz($ip, $resp['http_code'], $resp['data'], $this->trybZrodla());

        return $this->odpowiedz(true, false, $this->trybZrodla(), null, $ip, $this->wierszDoWyniku($wiersz));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function zapisz(string $ip, int $httpStatus, array $data, string $zrodlo): array
    {
        $wiersz = [
            'noise'          => $this->boolOrNull($data['noise'] ?? null),
            'riot'           => $this->boolOrNull($data['riot'] ?? null),
            'classification' => $this->tekst($data['classification'] ?? null, 64),
            'name'           => $this->tekst($data['name'] ?? null, 255),
            'link'           => $this->tekst($data['link'] ?? null, 512),
            'last_seen'      => $this->tekst($data['last_seen'] ?? null, 32),
            'message'        => $this->tekst($data['message'] ?? null, 2000),
            'http_status'    => $httpStatus,
            'raw_json'       => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'zrodlo_danych'  => $zrodlo,
            'checked_at'     => gmdate('Y-m-d H:i:sP'),
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
        return [
            'ip'             => (string) ($wiersz['ip'] ?? ''),
            'noise'          => $this->boolOrNull($wiersz['noise'] ?? null),
            'riot'           => $this->boolOrNull($wiersz['riot'] ?? null),
            'classification' => $wiersz['classification'] ?? null,
            'name'           => $wiersz['name'] ?? null,
            'link'           => $wiersz['link'] ?? null,
            'last_seen'      => $wiersz['last_seen'] ?? null,
            'message'        => $wiersz['message'] ?? null,
            'http_status'    => isset($wiersz['http_status']) ? (int) $wiersz['http_status'] : null,
            'zrodlo_danych'  => $wiersz['zrodlo_danych'] ?? null,
            'checked_at'     => $wiersz['checked_at'] ?? null,
        ];
    }

    private function boolOrNull(mixed $wartosc): ?bool
    {
        if ($wartosc === null || $wartosc === '') {
            return null;
        }
        if (is_bool($wartosc)) {
            return $wartosc;
        }

        return PluginModel::jakoBool($wartosc);
    }

    private function tekst(mixed $wartosc, int $max): ?string
    {
        if (! is_string($wartosc) && ! is_numeric($wartosc)) {
            return null;
        }
        $tekst = trim((string) $wartosc);

        return $tekst === '' ? null : mb_substr($tekst, 0, $max);
    }
}
