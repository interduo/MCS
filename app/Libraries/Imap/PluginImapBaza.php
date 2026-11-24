<?php

namespace App\Libraries\Imap;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\OpcjeMcs;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV1;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Wspólna baza pluginów źródeł IMAP (issue #463): shadowserver + play.
 *
 * Szkielet pobierz(): fixture (env mcs.<zrodlo>.fixturePath) → ImapKlientFabryka->dlaZrodla()
 * → importujZImap() → aktualizujHealth(). Fallback „ostatnie N UID” (gdy skrzynka cała SEEN)
 * jest wspólny: uidyDoPobrania() z opcją mcs.<zrodlo>.imapRecentLimit.
 *
 * Plugin zostawia tylko własną logikę: zrodlo(), parser, import formatu
 * (importujFixture / importujZImap) i komunikaty health (komunikatFixture / komunikatImapOk).
 */
abstract class PluginImapBaza implements SourcePluginInterface
{
    /** Parser formatu źródła (każdy plugin własny, stąd `object`). */
    protected object $parser;

    protected ImportKoperta $koperta;

    protected IncydentZapis $zapis;

    protected WagaV1 $waga;

    protected ?ImapKlientFabryka $imapFabryka;

    public function __construct(
        object $parser,
        ?ImportKoperta $koperta = null,
        ?IncydentZapis $zapis = null,
        ?WagaV1 $waga = null,
        ?ImapKlientFabryka $imapFabryka = null,
    ) {
        $this->parser      = $parser;
        $this->koperta     = $koperta ?? new ImportKoperta();
        $this->zapis       = $zapis ?? new IncydentZapis();
        $this->waga        = $waga ?? new WagaV1();
        $this->imapFabryka = $imapFabryka;
    }

    abstract public function zrodlo(): string;

    public function pobierz(): void
    {
        $fixture = $this->sciezkaFixture();
        if ($fixture !== '') {
            $liczba = $this->importujFixture($fixture);
            $this->aktualizujHealth(true, $this->komunikatFixture($liczba));

            return;
        }

        $fabryka = $this->imapFabryka ?? new ImapKlientFabryka();
        try {
            $klient = $fabryka->dlaZrodla($this->zrodlo());
        } catch (RuntimeException $e) {
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e;
        }

        try {
            $stats = $this->importujZImap($klient);
            $this->aktualizujHealth(true, $this->komunikatImapOk($stats));
        } catch (\Throwable $e) {
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e;
        } finally {
            $klient->zamknij();
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
            'msg' => $msg !== '' ? $msg : $this->etykietaBrakPrzebiegu(),
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
        return PluginOpisPolaczenia::imap('mcs.' . $this->zrodlo());
    }

    /**
     * UID do pobrania: najpierw UNSEEN; gdy pusto — fallback „ostatnie N UID” ze wszystkich
     * (mcs.<zrodlo>.imapRecentLimit), bo skrzynki produkcyjne bywają całe SEEN
     * (dedup po checksum/id_wiadomosci w kopercie chroni przed podwójnym importem).
     *
     * @return array{uidy: list<int>, fallback: bool}
     */
    protected function uidyDoPobrania(ImapKlientInterface $klient): array
    {
        $uidy = $klient->listujUid(true);
        if ($uidy !== []) {
            return ['uidy' => $uidy, 'fallback' => false];
        }

        $limit     = max(1, OpcjeMcs::int('mcs.' . $this->zrodlo() . '.imapRecentLimit'));
        $wszystkie = $klient->listujUid(false);
        rsort($wszystkie, SORT_NUMERIC);

        return ['uidy' => array_slice($wszystkie, 0, $limit), 'fallback' => true];
    }

    protected function aktualizujHealth(bool $ok, string $msg): void
    {
        model(PluginModel::class)->update($this->zrodlo(), [
            'health_at'  => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP'),
            'health_ok'  => $ok,
            'health_msg' => mb_substr($msg, 0, 500),
        ]);
    }

    /**
     * Ścieżka fixture (env mcs.<zrodlo>.fixturePath); puste = tryb IMAP.
     */
    protected function sciezkaFixture(): string
    {
        return trim((string) env('mcs.' . $this->zrodlo() . '.fixturePath', ''));
    }

    /**
     * Import z pliku fixture; rzuca wyjątek przy błędzie (health = blad).
     */
    abstract protected function importujFixture(string $sciezka): int;

    /**
     * Komunikat health po imporcie fixture.
     */
    abstract protected function komunikatFixture(int $liczba): string;

    /**
     * Import wiadomości IMAP (UNSEEN, fallback ostatnie N UID — patrz uidyDoPobrania).
     *
     * @return array<string, int|bool> statystyki przebiegu (co najmniej wiadomosci, importy, wiersze, fallback_ostatnie)
     */
    abstract protected function importujZImap(ImapKlientInterface $klient): array;

    /**
     * Komunikat health po imporcie IMAP.
     *
     * @param array<string, int|bool> $stats
     */
    abstract protected function komunikatImapOk(array $stats): string;

    /**
     * Opis gotowości .env gdy jeszcze nie było przebiegu importu (domyślnie pusto).
     */
    protected function opisKonfiguracji(): string
    {
        return '';
    }

    /**
     * Tekst health, gdy nie było jeszcze przebiegu (domyślnie „brak przebiegu”).
     */
    protected function etykietaBrakPrzebiegu(): string
    {
        return 'brak przebiegu';
    }
}
