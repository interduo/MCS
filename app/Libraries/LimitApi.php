<?php

namespace App\Libraries;

use CodeIgniter\Throttle\ThrottlerInterface;

/**
 * Rate limit tokenowych endpointów API (/api/badge, /api/enrichment/*) — issue #451.
 *
 * Dwa kubełki tokenów (Throttler CI4) w oknie 1 minuty, limit mcs.api.rateLimit
 * (domyślnie 60 żądań/min): osobno per token serwerowy i osobno per adres IP klienta.
 * Oba muszą przejść — wyciek/nadużycie jednego tokenu nie zjada limitu całego ruchu,
 * a jeden adres IP nie może „spalić” limitu wielu tokenów.
 * 429 + Retry-After dokleja filtr McsApiThrottleFilter.
 */
class LimitApi
{
    public const OKNO = 60;

    private ThrottlerInterface $throttler;

    public function __construct(?ThrottlerInterface $throttler = null)
    {
        $this->throttler = $throttler ?? service('throttler');
    }

    /**
     * Zużywa token w obu kubełkach. Zwraca false, gdy którykolwiek limit jest wyczerpany.
     */
    public function pozwol(string $token, string $ip): bool
    {
        $limit = max(1, OpcjeMcs::int('mcs.api.rateLimit'));

        if (! $this->throttler->check(self::klucz('t', $token), $limit, self::OKNO)) {
            return false;
        }

        if ($ip !== '' && ! $this->throttler->check(self::klucz('i', $ip), $limit, self::OKNO)) {
            return false;
        }

        return true;
    }

    /**
     * Klucz cache bez znaków zastrzeżonych ({}()/\@:).
     */
    public static function klucz(string $typ, string $wartosc): string
    {
        return 'mcsapi_' . $typ . '_' . md5($wartosc);
    }

    /**
     * Sekundy do odnowienia jednego tokenu (nagłówek Retry-After).
     */
    public static function retryAfter(int $limit): int
    {
        return max(1, (int) ceil(self::OKNO / max(1, $limit)));
    }
}
