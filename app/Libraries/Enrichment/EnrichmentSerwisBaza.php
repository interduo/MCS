<?php

namespace App\Libraries\Enrichment;

use App\Libraries\OpcjeMcs;
use App\Models\EnrichmentCacheModel;

/**
 * Wspólna baza serwisów enrichment per-IP (issue #467).
 *
 * Jeden szkielet {@see EnrichmentSerwisBaza::sprawdz()}: walidacja IP →
 * odczyt cache (chyba że $force) → fixture → źródło (API/DNS/DB) → upsert
 * przez EnrichmentCacheModel → odpowiedź {ok, z_cache, tryb, blad, ip, wynik}.
 *
 * Klasy potomne deklarują tylko: model cache + TTL + fixturePath (konstruktor),
 * konfigurację źródła (maZrodlo/brakZrodlaKomunikat), etykiety trybów
 * (trybFixture/trybZrodla/trybBrak) oraz mapowanie odpowiedzi
 * (zFixture/zZrodla/wierszDoWyniku).
 *
 * Świadomie NIE zapisuje do mcs_incydenty / mcs_obserwacje / badge
 * (wyjątek: DnsblSerwis przy force, patrz jego zZrodla).
 */
abstract class EnrichmentSerwisBaza
{
    protected EnrichmentCacheModel $model;

    protected int $cacheTtl = 0;

    protected string $fixturePath = '';

    /**
     * Ustawia model cache + TTL + fixturePath — wołane z konstruktora potomka.
     *
     * @param string $kluczTtl        klucz OpcjeMcs z TTL cache (np. mcs.abuseipdb.cacheTtl)
     * @param string $kluczEnvFixture klucz env z ścieżką fixture (np. mcs.abuseipdb.fixturePath)
     */
    protected function ustawCache(
        EnrichmentCacheModel $model,
        ?int $cacheTtl,
        ?string $fixturePath,
        string $kluczTtl,
        string $kluczEnvFixture,
    ): void {
        $this->model       = $model;
        $ttl               = $cacheTtl ?? OpcjeMcs::int($kluczTtl);
        $this->cacheTtl    = max(0, $ttl);
        $this->fixturePath = $fixturePath ?? trim((string) env($kluczEnvFixture, ''));
    }

    public function hasFixture(): bool
    {
        return $this->fixturePath !== '' && is_readable($this->fixturePath);
    }

    /**
     * Etykieta aktywnego trybu: fixture → skonfigurowane źródło → brak konfiguracji.
     */
    public function tryb(): string
    {
        if ($this->hasFixture()) {
            return $this->trybFixture();
        }
        if ($this->maZrodlo()) {
            return $this->trybZrodla();
        }

        return $this->trybBrak();
    }

    /**
     * Podgląd ważnego cache bez wywołania źródła / fixture.
     *
     * @return array<string, mixed>|null
     */
    public function podgladCache(string $ip): ?array
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $cache = $this->model->znajdzPoIp($ip);
        if (! is_array($cache) || ! $this->model->cacheWazny($cache, $this->cacheTtl)) {
            return null;
        }

        return $this->wierszDoWyniku($cache);
    }

    /**
     * Szablon lookupu pojedynczego IP.
     *
     * @return array{ok:bool,z_cache:bool,tryb:string,blad:?string,ip:string,wynik:?array<string,mixed>}
     */
    public function sprawdz(string $ip, bool $force = false): array
    {
        return $this->szkieletSprawdz($ip, $force);
    }

    /**
     * Właściwy szkielet sprawdz() — potomek może go wywołać z własnym
     * rozszerzonym sprawdz() (np. DnsblSerwis z zakresem/importId).
     *
     * @param array<string, mixed> $kontekst przekazywany dalej do zFixture/zZrodla
     *
     * @return array{ok:bool,z_cache:bool,tryb:string,blad:?string,ip:string,wynik:?array<string,mixed>}
     */
    final protected function szkieletSprawdz(string $ip, bool $force, array $kontekst = []): array
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->odpowiedz(false, false, $this->tryb(), 'Wymagany prawidłowy adres IP.', $ip, null);
        }

        $blad = $this->walidujIp($ip);
        if ($blad !== null) {
            return $this->odpowiedz(false, false, $this->tryb(), $blad, $ip, null);
        }

        if (! $force) {
            $cache = $this->model->znajdzPoIp($ip);
            if (is_array($cache) && $this->model->cacheWazny($cache, $this->cacheTtl)) {
                return $this->odpowiedz(true, true, 'cache', null, $ip, $this->wierszDoWyniku($cache), $this->dodatkiCacheHit($kontekst));
            }
        }

        if ($this->hasFixture()) {
            return $this->zFixture($ip, $kontekst);
        }

        if (! $this->maZrodlo()) {
            return $this->odpowiedz(false, false, $this->trybBrak(), $this->brakZrodlaKomunikat(), $ip, null);
        }

        return $this->zZrodla($ip, $kontekst);
    }

    /**
     * Czy oprócz fixture jest skonfigurowane źródło (klucz API / credentials / DNS).
     * Domyślnie tak (DNSBL: samo zapytanie DNS, bez klucza).
     */
    protected function maZrodlo(): bool
    {
        return true;
    }

    /**
     * Komunikat zwracany, gdy brak źródła (trybBrak()).
     */
    protected function brakZrodlaKomunikat(): string
    {
        return 'Brak konfiguracji klucza API.';
    }

    /** Etykieta trybu, gdy aktywny jest fixture. */
    protected function trybFixture(): string
    {
        return 'fixture';
    }

    /** Etykieta trybu aktywnego źródła (API/DNS/DB). */
    protected function trybZrodla(): string
    {
        return 'api';
    }

    /** Etykieta trybu, gdy brak źródła. */
    protected function trybBrak(): string
    {
        return 'brak_klucza';
    }

    /**
     * Dodatkowa walidacja IP po walidacji ogólnej (np. tylko IPv4).
     * Zwraca komunikat błędu albo null, gdy IP jest OK.
     */
    protected function walidujIp(string $ip): ?string
    {
        return null;
    }

    /**
     * Dodatkowe pola odpowiedzi przy trafieniu z cache (poza standardowym kontraktem).
     *
     * @param array<string, mixed> $kontekst
     *
     * @return array<string, mixed>
     */
    protected function dodatkiCacheHit(array $kontekst): array
    {
        return [];
    }

    /**
     * Lookup przez plik fixture (dry-run).
     *
     * @param array<string, mixed> $kontekst
     *
     * @return array{ok:bool,z_cache:bool,tryb:string,blad:?string,ip:string,wynik:?array<string,mixed>}
     */
    abstract protected function zFixture(string $ip, array $kontekst): array;

    /**
     * Lookup przez aktywne źródło (API/DNS/DB) + upsert do cache.
     *
     * @param array<string, mixed> $kontekst
     *
     * @return array{ok:bool,z_cache:bool,tryb:string,blad:?string,ip:string,wynik:?array<string,mixed>}
     */
    abstract protected function zZrodla(string $ip, array $kontekst): array;

    /**
     * Mapowanie wiersza cache do wyniku zwracanego na zewnątrz.
     *
     * @param array<string, mixed> $wiersz
     *
     * @return array<string, mixed>
     */
    abstract protected function wierszDoWyniku(array $wiersz): array;

    /**
     * Standardowa odpowiedź sprawdz().
     *
     * @param array<string, mixed>|null $wynik
     * @param array<string, mixed>      $dodatki pola doklejane na końcu (np. incydenty)
     *
     * @return array{ok:bool,z_cache:bool,tryb:string,blad:?string,ip:string,wynik:?array<string,mixed>}
     */
    protected function odpowiedz(bool $ok, bool $zCache, string $tryb, ?string $blad, string $ip, ?array $wynik, array $dodatki = []): array
    {
        return array_merge([
            'ok'      => $ok,
            'z_cache' => $zCache,
            'tryb'    => $tryb,
            'blad'    => $blad,
            'ip'      => $ip,
            'wynik'   => $wynik,
        ], $dodatki);
    }

    /**
     * Upsert wiersza przez model cache + ponowny odczyt (wspólne dla wszystkich źródeł).
     *
     * @param array<string, mixed> $wiersz
     *
     * @return array<string, mixed>
     */
    protected function zapiszWiersz(string $ip, array $wiersz): array
    {
        $this->model->upsertDlaIp($ip, $wiersz);
        $odczyt = $this->model->znajdzPoIp($ip);

        return is_array($odczyt) ? $odczyt : array_merge(['ip' => $ip], $wiersz);
    }

    /**
     * Surowa treść pliku fixture albo null, gdy nie można odczytać.
     */
    protected function wczytajFixtureRaw(): ?string
    {
        $raw = file_get_contents($this->fixturePath);

        return is_string($raw) ? $raw : null;
    }
}
