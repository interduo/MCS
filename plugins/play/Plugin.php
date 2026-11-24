<?php

namespace McsPluginy\play;

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
 * Plugin źródła Play (Ochrona Anty-DDoS) — powiadomienia DDoS w treści maila
 * (antyddos@play.pl) przez IMAP. Usługa Play chroni przed atakami wolumetrycznymi;
 * MCS importuje alerty Event/Mitigation/CIDR, nie sam tor ochrony sieciowej.
 *
 * Szkielet IMAP (fixture → fabryka → importujZImap → health, fallback ostatnie N UID)
 * jest wspólny: PluginImapBaza (issue #463). Limit rozmiaru/wierszy: issue #453.
 *
 * Uwaga operacyjna: FreeScout na tej samej skrzynce potrafi ściągnąć i usunąć
 * maile z IMAP; MCS widzi wtedy tylko resztki (np. Witamy/test).
 */
class Plugin extends PluginImapBaza
{
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
        return 'play';
    }

    protected function importujFixture(string $sciezka): int
    {
        if (! is_readable($sciezka)) {
            throw new RuntimeException('Play: nie mozna odczytac fixturePath=' . $sciezka);
        }
        try {
            ImportLimity::sprawdzPlik($sciezka, 'Play');
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

        $tresc   = (string) file_get_contents($sciezka);
        $subject = 'DDoS notification - fixture';
        if (preg_match('/^Subject:\s*(.+)$/mi', $tresc, $m) === 1) {
            $subject = trim($m[1]);
            $tresc   = (string) preg_replace('/^Subject:\s*.+$/mi', '', $tresc, 1);
        }

        return $this->importujPowiadomienie(
            $subject,
            $tresc,
            null,
            hash('sha256', $subject . "\n" . $tresc),
            'fixture:' . basename($sciezka),
            ['tryb' => 'fixture', 'plik' => basename($sciezka)],
        );
    }

    protected function komunikatFixture(int $liczba): string
    {
        return $liczba > 0
            ? "import fixture OK ({$liczba} wierszy)"
            : 'fixture bez wierszy DDoS / duplikat';
    }

    /**
     * @param array<string, int|bool> $stats
     */
    protected function komunikatImapOk(array $stats): string
    {
        $msg = sprintf(
            'import IMAP OK (wiadomosci=%d, ddos=%d, nowe_importy=%d, wiersze=%d%s)',
            $stats['wiadomosci'],
            $stats['ddos'],
            $stats['importy'],
            $stats['wiersze'],
            $stats['fallback_ostatnie'] ? ', tryb=ostatnie_SEEN' : '',
        );
        // IMAP działa, ale brak DDoS w skrzynce = tor gotowy (czekamy na alert).
        // Historyczne maile mogły zniknąć po FreeScout; to nie jest awaria credentials.
        if ($stats['ddos'] === 0) {
            return $stats['wiadomosci'] === 0
                ? 'Play IMAP OK — skrzynka pusta; tor gotowy, czekam na powiadomienia DDoS (' . Parser::nadawca() . ').'
                : sprintf(
                    'Play IMAP OK — %d wiadomości, 0× DDoS od %s; tor gotowy, czekam na kolejne alerty.',
                    $stats['wiadomosci'],
                    Parser::nadawca(),
                );
        }

        return $msg;
    }

    /**
     * Play: health bez przebiegu i bez komunikatu zostawia pusty msg (jak wcześniej).
     */
    protected function etykietaBrakPrzebiegu(): string
    {
        return '';
    }

    /**
     * @param array<string, int|bool> $stats
     *
     * @return array{wiadomosci: int, ddos: int, importy: int, wiersze: int, fallback_ostatnie: bool}
     */
    protected function importujZImap(ImapKlientInterface $klient): array
    {
        $wybor    = $this->uidyDoPobrania($klient);
        $uidy     = $wybor['uidy'];
        $fallback = $wybor['fallback'];

        $sumaWierszy  = 0;
        $sumaImportow = 0;
        $sumaDdos     = 0;

        foreach ($uidy as $uid) {
            $wiadomosc = $klient->pobierzWiadomosc($uid);
            $from      = strtolower((string) ($wiadomosc['from'] ?? ''));
            $subject   = (string) ($wiadomosc['subject'] ?? '');
            $tresc     = (string) ($wiadomosc['tresc'] ?? '');

            // Sam temat lub podciąg domeny nie wystarcza: tylko dokładny nadawca alertów.
            $wygladaNaDdos = Parser::czyOdNadawcy($from)
                && $this->parser->toPowiadomienieDdos($subject, $tresc);

            if (! $wygladaNaDdos) {
                continue;
            }

            $sumaDdos++;
            $checksum = hash('sha256', $subject . "\n" . $tresc);
            $liczba   = $this->importujPowiadomienie(
                $subject,
                $tresc,
                $wiadomosc['date'] ?? null,
                $checksum,
                $wiadomosc['message_id'],
                [
                    'tryb'    => 'imap',
                    'uid'     => $uid,
                    'from'    => $from,
                    'subject' => $subject,
                ],
            );
            if ($liczba > 0) {
                $sumaWierszy += $liczba;
                $sumaImportow++;
                $klient->oznaczPrzeczytane($uid);
            }
        }

        return [
            'wiadomosci'        => count($uidy),
            'ddos'              => $sumaDdos,
            'importy'           => $sumaImportow,
            'wiersze'           => $sumaWierszy,
            'fallback_ostatnie' => $fallback,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function importujPowiadomienie(
        string $subject,
        string $tresc,
        ?string $dateNaglowka,
        string $checksum,
        ?string $idWiadomosci,
        array $meta,
    ): int {
        try {
            ImportLimity::sprawdzTresc($tresc, 'Play', $subject !== '' ? $subject : 'wiadomosc');
        } catch (PrzekroczonyLimitImportu $e) {
            $this->koperta->oznaczBlad($this->zrodlo(), $checksum, $idWiadomosci, $meta, $e->getMessage());
            throw $e;
        }

        $importId = $this->koperta->rozpocznij($this->zrodlo(), $checksum, $idWiadomosci, $meta);
        if ($importId === null) {
            return 0;
        }

        $db = Database::connect();

        try {
            $wiersze = $this->parser->parsujPowiadomienie($subject, $tresc, $dateNaglowka);
            ImportLimity::sprawdzLiczbeWierszy('Play', count($wiersze));
            if ($wiersze === []) {
                $this->koperta->zakonczKwarantanna($importId, 'Brak rozpoznanego powiadomienia DDoS w treści/temacie');

                return 0;
            }

            // Zbiorczy zapis (issue #442): wiersze źródła paczkami (INSERT … RETURNING id),
            // potem incydenty jednym zapiszWiele.
            $wierszeDb = [];
            $incydenty = [];
            foreach ($wiersze as $w) {
                $kategoria = $this->parser->kategoriaDlaWiersza($w);
                $wynikWagi = $this->waga->wylicz($kategoria, [
                    'message_type' => $w['message_type'],
                ]);

                $wierszeDb[] = [
                    'import_id'        => $importId,
                    'zaobserwowano_at' => $w['timestamp'],
                    'ip'               => $w['ip'],
                    'klucz_dedupu'     => $w['klucz_dedupu'],
                    'payload_json'     => json_encode($w, JSON_UNESCAPED_UNICODE),
                ];

                $tytul = 'Play Scrubbing DDoS — ' . $w['message_type'];
                if (! empty($w['event_id'])) {
                    $tytul .= ' #' . $w['event_id'];
                }

                $incydenty[] = [
                    'zrodlo'           => $this->zrodlo(),
                    'klucz_dedupu'     => $w['klucz_dedupu'],
                    'podmiot_typ'      => $w['podmiot_typ'],
                    'podmiot'          => $w['podmiot'],
                    'zaobserwowano_at' => $w['timestamp'],
                    'waga'             => $wynikWagi['waga'],
                    'wersja_wagi'      => $wynikWagi['wersja'],
                    'kategoria'        => $kategoria,
                    'tytul'            => $tytul,
                    'import_id'        => $importId,
                    'id_w_zrodle'      => null, // uzupełnione niżej po zbiorczym INSERT wierszy źródła
                    'payload'          => [
                        'message_type'  => $w['message_type'],
                        'event_id'      => $w['event_id'],
                        'policy'        => $w['policy'],
                        'tid'           => $w['tid'],
                        'mitigation_id' => $w['mitigation_id'],
                        'cidr'          => $w['cidr'],
                        'waga_meta'     => $wynikWagi,
                    ],
                ];
            }

            foreach (array_chunk($wierszeDb, \App\Libraries\IncydentZapis::ROZMIAR_PACZKI, true) as $paczka) {
                $ids = \App\Libraries\ZapisBatch::insertZwrocId($db, 'mcs_play_wiersze', array_values($paczka));
                foreach (array_keys($paczka) as $j => $i) {
                    $incydenty[$i]['id_w_zrodle'] = (string) $ids[$j];
                }
            }
            $this->zapis->zapiszWiele($incydenty);
            $licznik = count($wiersze);

            $this->koperta->zakonczOk($importId, ['wierszy' => $licznik]);

            return $licznik;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }
    }
}
