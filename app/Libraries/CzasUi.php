<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Krótka data i „ile temu” po polsku — do tabel ops.
 */
final class CzasUi
{
    public static function dataGodzina(string $at): string
    {
        $dt = self::parsuj($at);
        if ($dt === null) {
            return '';
        }

        return $dt->setTimezone(new DateTimeZone(app_timezone()))->format('Y-m-d H:i:s');
    }

    /**
     * Znacznik z bazy (UTC, zwykle z offsetem +00) do pokazania w strefie aplikacji.
     * Pusty ciąg zwraca $puste. Tekst bez godziny zostaje bez zmian.
     */
    public static function pokaz(string $at, string $puste = ''): string
    {
        $at = trim($at);
        if ($at === '') {
            return $puste;
        }
        if (! preg_match('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $at)) {
            return $at;
        }

        $fmt = self::dataGodzina($at);

        return $fmt !== '' ? $fmt : $at;
    }

    /**
     * W tekście (komunikat health, opis historii) zamienia znaczniki oznaczone jako UTC
     * na strefę aplikacji. Znaczniki bez strefy zostawia — nie są jednoznaczne.
     */
    public static function wTekscie(string $tekst): string
    {
        $wynik = preg_replace_callback(
            '/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?(?:\s*(?:UTC|Z)|\s*\+00(?::?00)?)?/',
            static function (array $m): string {
                $surowy = $m[0];
                if (! preg_match('/(?:UTC|Z|\+00)\s*$/', $surowy)) {
                    return $surowy;
                }
                $bez = trim((string) preg_replace('/(?:\s*UTC|\s*Z|\s*\+00(?::?00)?)\s*$/', '', $surowy));
                $lokalny = self::dataGodzina($bez . '+00');

                return $lokalny !== '' ? $lokalny : $surowy;
            },
            $tekst,
        );

        return is_string($wynik) ? $wynik : $tekst;
    }

    public static function wzglednie(string $at, ?int $terazTs = null): string
    {
        $dt = self::parsuj($at);
        if ($dt === null) {
            return '';
        }

        $teraz = $terazTs === null
            ? new DateTimeImmutable('now')
            : (new DateTimeImmutable('@' . $terazTs))->setTimezone($dt->getTimezone());

        $sek = $teraz->getTimestamp() - $dt->getTimestamp();
        if ($sek < 0) {
            $sek = 0;
        }

        if ($sek < 60) {
            return $sek . ' s temu';
        }

        $min = intdiv($sek, 60);
        if ($min < 60) {
            return $min . ' min temu';
        }

        $godz = intdiv($min, 60);
        if ($godz < 24) {
            return $godz . ' godz. temu';
        }

        $dni = intdiv($godz, 24);

        return $dni === 1 ? '1 dzień temu' : $dni . ' dni temu';
    }

    private static function parsuj(string $at): ?DateTimeImmutable
    {
        $at = trim($at);
        if ($at === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($at);
        } catch (Throwable) {
            return null;
        }
    }
}
