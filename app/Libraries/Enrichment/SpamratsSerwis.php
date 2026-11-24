<?php

namespace App\Libraries\Enrichment;

use App\Libraries\PluginOpisPolaczenia;
use App\Models\EnrichmentSpamratsModel;
use App\Models\PluginModel;

/**
 * Enrichment SpamRats DNSBL RATS-All: cache TTL + lookup na żądanie.
 * Świadomie NIE zapisuje do mcs_incydenty / mcs_obserwacje / badge.
 *
 * Szkielet sprawdz() (walidacja IP → tylko IPv4 → cache → fixture → DNS
 * → upsert) jest w {@see EnrichmentSerwisBaza}; tu tylko klient,
 * konfiguracja credentials i mapowanie odpowiedzi.
 */
class SpamratsSerwis extends EnrichmentSerwisBaza
{
    private SpamratsClient $client;

    public function __construct(
        ?SpamratsClient $client = null,
        ?EnrichmentSpamratsModel $model = null,
        ?int $cacheTtl = null,
        ?string $fixturePath = null,
    ) {
        $this->client = $client ?? SpamratsClient::fromEnv();
        $this->ustawCache(
            $model ?? model(EnrichmentSpamratsModel::class),
            $cacheTtl,
            $fixturePath,
            'mcs.spamrats.cacheTtl',
            'mcs.spamrats.fixturePath',
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

    protected function trybZrodla(): string
    {
        return 'dns';
    }

    protected function walidujIp(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'SpamRats DNSBL obsługuje tylko IPv4.';
        }

        return null;
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
        $maKlucz = $this->hasCredentials();

        return PluginOpisPolaczenia::plikIKanal(
            $this->fixturePath,
            'DNS RATS-All',
            $this->client->getZone(),
            $maKlucz,
            'dns',
            'DNS',
            [
                PluginOpisPolaczenia::pozycja(
                    'Klucz API',
                    PluginOpisPolaczenia::sekret($maKlucz),
                    false,
                    $maKlucz,
                ),
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
            return $this->odpowiedz(false, false, $this->trybFixture(), 'Fixture SpamRats nie jest poprawnym JSON.', $ip, null);
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
            return $this->odpowiedz(false, false, $this->trybZrodla(), $resp['error'] !== '' ? $resp['error'] : 'Błąd DNS SpamRats', $ip, null);
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
        $listed = $this->jakoBoolNullable($data['listed'] ?? null);
        $dyna   = $this->jakoBoolNullable($data['listed_dyna'] ?? null);
        $noptr  = $this->jakoBoolNullable($data['listed_noptr'] ?? null);
        $spam   = $this->jakoBoolNullable($data['listed_spam'] ?? null);
        $auth   = $this->jakoBoolNullable($data['listed_auth'] ?? null);

        $kody = $data['kody'] ?? [];
        if (! is_array($kody)) {
            $kody = [];
        }
        $kodyOut = [];
        foreach ($kody as $k) {
            if (is_string($k) && $k !== '') {
                $kodyOut[] = $k;
            }
        }

        $wiersz = [
            'listed'        => $listed,
            'listed_dyna'   => $dyna,
            'listed_noptr'  => $noptr,
            'listed_spam'   => $spam,
            'listed_auth'   => $auth,
            'kody_json'     => json_encode($kodyOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'raw_json'      => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'zrodlo_danych' => $zrodlo,
            'checked_at'    => gmdate('Y-m-d H:i:sP'),
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
        $kody = $wiersz['kody_json'] ?? [];
        if (is_string($kody)) {
            $decoded = json_decode($kody, true);
            $kody    = is_array($decoded) ? $decoded : [];
        } elseif (! is_array($kody)) {
            $kody = [];
        }

        return [
            'ip'            => (string) ($wiersz['ip'] ?? ''),
            'listed'       => $this->jakoBoolNullable($wiersz['listed'] ?? null),
            'listed_dyna'  => $this->jakoBoolNullable($wiersz['listed_dyna'] ?? null),
            'listed_noptr' => $this->jakoBoolNullable($wiersz['listed_noptr'] ?? null),
            'listed_spam'  => $this->jakoBoolNullable($wiersz['listed_spam'] ?? null),
            'listed_auth'  => $this->jakoBoolNullable($wiersz['listed_auth'] ?? null),
            'kody'         => $kody,
            'zrodlo_danych'=> $wiersz['zrodlo_danych'] ?? null,
            'checked_at'   => $wiersz['checked_at'] ?? null,
        ];
    }

    private function jakoBoolNullable(mixed $v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }

        return PluginModel::jakoBool($v);
    }
}
