<?php

namespace App\Libraries;

/**
 * Mapowanie wyjątków na komunikaty dla UI / flash / JSON (issue #433).
 *
 * Zasada: pełny wyjątek (SQLSTATE, hosty DB/LMS, ścieżki plików, stack) trafia tylko do logu
 * z ID zdarzenia; użytkownik dostaje generyczny komunikat + ID („szczegóły w logu”).
 *
 * Whitelist: wyjątki, których getMessage() jest tworzone wyłącznie przez nasz kod
 * (klienci/parsery pluginów — prefiks 'ThreatFox: ' / 'Spamhaus ' lub klasa BladPobrania)
 * nie zawierają danych wewnętrznych i przechodzą do UI bez zmian.
 */
final class BladUi
{
    /** Prefiksy komunikatów zaufanych (tylko nasz tekst + ew. wartości z feedu). */
    private const PREFiksY_ZAUFANE = ['ThreatFox: ', 'Spamhaus '];

    /** Klasy wyjątków z zaufanym komunikatem. @var list<class-string<\Throwable>> */
    private const KLASY_ZAUFANE = [
        \McsPluginy\spamhaus\BladPobrania::class,
    ];

    /**
     * ID zdarzenia do korelacji UI ↔ log (8 znaków hex).
     */
    public static function idZdarzenia(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * Pełny wyjątek (ze stack trace) do logu pod danym ID zdarzenia.
     */
    public static function zaloguj(string $id, \Throwable $e, string $kontekst = ''): void
    {
        log_message('error', ($kontekst !== '' ? $kontekst . ' — ' : '') . 'zdarzenie ' . $id . ': ' . (string) $e);
    }

    /**
     * Czy komunikat wyjątku jest z whitelisty (bezpieczny dla użytkownika)?
     */
    public static function zaufany(\Throwable $e): bool
    {
        foreach (self::KLASY_ZAUFANE as $klasa) {
            if ($e instanceof $klasa) {
                return true;
            }
        }
        $msg = $e->getMessage();
        foreach (self::PREFiksY_ZAUFANE as $prefiks) {
            if (str_starts_with($msg, $prefiks)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Komunikat bezpieczny dla UI/JSON/flash. Nie-zaufany wyjątek: pełny zapis do logu
     * z nowym ID zdarzenia, a tutaj generyczny komunikat z tym ID.
     */
    public static function komunikat(\Throwable $e, string $kontekst = ''): string
    {
        if (self::zaufany($e)) {
            return $e->getMessage();
        }

        $id = self::idZdarzenia();
        self::zaloguj($id, $e, $kontekst);

        return 'Wystąpił błąd wewnętrzny (zdarzenie ' . $id . ') — szczegóły w logu serwera.';
    }
}
