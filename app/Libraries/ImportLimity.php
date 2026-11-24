<?php

namespace App\Libraries;

/**
 * Limity importów (issue #453): rozmiar pliku/załącznika i liczba wierszy na import.
 *
 * Konfigurowalne przez OpcjeMcs: mcs.import.maxPlikMb (domyślnie 100 MB),
 * mcs.import.maxWierszy (domyślnie 500 000). Przekroczenie = wyjątek
 * PrzekroczonyLimitImportu; import oznaczany jako `blad` w mcs_importy.blad_text
 * i NIE importowany częściowo bez oznaczenia.
 */
final class ImportLimity
{
    public const DOMYSLNY_MAX_PLIK_MB = 100;

    public const DOMYSLNY_MAX_WIERSZY = 500000;

    /** Maks. rozmiar pliku/załącznika/treści importu (bajty). */
    public static function maxPlikB(): int
    {
        $mb = OpcjeMcs::int('mcs.import.maxPlikMb');
        if ($mb < 1) {
            $mb = self::DOMYSLNY_MAX_PLIK_MB;
        }

        return $mb * 1024 * 1024;
    }

    /** Maks. liczba wierszy (rekordów) na jeden import. */
    public static function maxWierszy(): int
    {
        $n = OpcjeMcs::int('mcs.import.maxWierszy');

        return $n >= 1 ? $n : self::DOMYSLNY_MAX_WIERSZY;
    }

    /**
     * Sprawdza rozmiar pliku na dysku PRZED czytaniem (bez wczytywania do pamięci).
     *
     * @throws PrzekroczonyLimitImportu
     */
    public static function sprawdzPlik(string $sciezka, string $kontekst): void
    {
        clearstatcache(true, $sciezka);
        $rozmiar = filesize($sciezka);
        if ($rozmiar !== false && $rozmiar > self::maxPlikB()) {
            throw new PrzekroczonyLimitImportu(
                self::komunikatRozmiar($kontekst, basename($sciezka), $rozmiar, self::maxPlikB()),
            );
        }
    }

    /**
     * Sprawdza rozmiar treści już w pamięci (załącznik IMAP, body HTTP, eksport SSH).
     *
     * @throws PrzekroczonyLimitImportu
     */
    public static function sprawdzTresc(string $tresc, string $kontekst, string $nazwa = ''): void
    {
        $rozmiar = strlen($tresc);
        if ($rozmiar > self::maxPlikB()) {
            throw new PrzekroczonyLimitImportu(
                self::komunikatRozmiar($kontekst, $nazwa, $rozmiar, self::maxPlikB()),
            );
        }
    }

    /**
     * Sprawdza liczbę rekordów importu (wywołuj wewnątrz przebiegu importu —
     * koperta dostanie status `blad` przez istniejący catch).
     *
     * @throws PrzekroczonyLimitImportu
     */
    public static function sprawdzLiczbeWierszy(string $kontekst, int $liczba): void
    {
        $max = self::maxWierszy();
        if ($liczba > $max) {
            throw new PrzekroczonyLimitImportu(self::komunikatWiersze($kontekst, $max));
        }
    }

    public static function komunikatRozmiar(string $kontekst, string $nazwa, int $rozmiar, int $limit): string
    {
        $co = $nazwa !== '' ? $nazwa : 'dane';

        return sprintf(
            '%s: %s ma %s, limit to %s — import odrzucony w całości',
            $kontekst,
            $co,
            self::formatujMb($rozmiar),
            self::formatujMb($limit),
        );
    }

    public static function komunikatWiersze(string $kontekst, int $limit): string
    {
        return sprintf(
            '%s: przekroczono limit %d wierszy na import — plik odrzucony w całości',
            $kontekst,
            $limit,
        );
    }

    public static function formatujMb(int $bajty): string
    {
        return round($bajty / 1024 / 1024, 1) . ' MB';
    }
}
