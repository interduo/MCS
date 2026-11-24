<?php

namespace App\Libraries;

use App\Libraries\Enrichment\SpeedtestSerwis;

/**
 * Które wtyczki wymagające klucza / pliku testowego są skonfigurowane.
 * Nieskonfigurowane nie mają zakładek w UI (poza panelem wtyczek).
 */
final class WidocznoscWtyczek
{
    /**
     * Pozycje menu przeglądu zależne od konfiguracji.
     *
     * @return array{speedtest: bool, threatfox: bool}
     */
    public static function menu(): array
    {
        return [
            'speedtest' => self::bezpiecznie(static fn (): bool => (new SpeedtestSerwis())->tryb() !== 'brak_konfiguracji'),
            'threatfox' => self::bezpiecznie(static fn (): bool => trim(OpcjeMcs::tekst('mcs.threatfox.authKey'), " \t\n\r\0\x0B'\"") !== ''),
        ];
    }

    /**
     * Błąd odczytu konfiguracji nie może ukryć zakładki ani wywrócić strony.
     *
     * @param callable(): bool $sprawdz
     */
    private static function bezpiecznie(callable $sprawdz): bool
    {
        try {
            return $sprawdz();
        } catch (\Throwable) {
            return true;
        }
    }
}
