<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\Enrichment\AbuseipdbSerwis;
use App\Libraries\Enrichment\DnsblSerwis;
use App\Libraries\Enrichment\GreynoiseSerwis;
use App\Libraries\Enrichment\IsmaliciousSerwis;
use App\Libraries\Enrichment\LmsMapowanieSerwis;
use App\Libraries\Enrichment\SpamratsSerwis;

/**
 * Enrichment dla LMS/UI (token serwerowy — filtr mcsBadgeToken).
 * Nie wpływa na badge streamowy / mcs_incydenty.
 *
 * Jeden kontroler parametryzowany źródłem: GET api/enrichment/{zrodlo}?ip=&force=
 * Stare URL-e api/enrichment/abuseipdb|dnsbl|greynoise|ismalicious|lms|spamrats
 * działają dalej 1:1 (segment = alias źródła, patrz Config\Routes).
 */
class Enrichment extends BaseController
{
    /**
     * Whitelista źródeł: segment => [klasa serwisu, tryb błędu zwracany jako 503 (null = zawsze 400)].
     *
     * @var array<string, array{0: class-string, 1: ?string}>
     */
    private const ZRODLA = [
        'abuseipdb'   => [AbuseipdbSerwis::class, 'brak_klucza'],
        'dnsbl'       => [DnsblSerwis::class, null],
        'greynoise'   => [GreynoiseSerwis::class, 'brak_klucza'],
        'ismalicious' => [IsmaliciousSerwis::class, 'brak_klucza'],
        'lms'         => [LmsMapowanieSerwis::class, 'brak_konfiguracji'],
        'spamrats'    => [SpamratsSerwis::class, 'brak_klucza'],
    ];

    public function check(string $zrodlo = '')
    {
        $ip = $this->request->getGet('ip');
        $ip = is_string($ip) ? trim($ip) : '';

        if (! isset(self::ZRODLA[$zrodlo])) {
            return $this->response->setStatusCode(400)->setJSON([
                'ok'   => false,
                'blad' => 'nieznane_zrodlo',
                'ip'   => $ip,
                'tryb' => 'nieznane_zrodlo',
            ]);
        }

        $forceRaw = $this->request->getGet('force');
        $force    = filter_var($forceRaw, FILTER_VALIDATE_BOOLEAN);

        if ($force && ($odmowa = $this->odmowaLimituForceApi()) !== null) {
            return $odmowa;
        }

        [$klasaSerwisu, $tryb503] = self::ZRODLA[$zrodlo];
        $wynik = (new $klasaSerwisu())->sprawdz($ip, $force);

        if (! $wynik['ok']) {
            $kod = ($tryb503 !== null && $wynik['tryb'] === $tryb503) ? 503 : 400;

            return $this->response->setStatusCode($kod)->setJSON([
                'ok'   => false,
                'blad' => $wynik['blad'],
                'ip'   => $wynik['ip'],
                'tryb' => $wynik['tryb'],
            ]);
        }

        return $this->response->setJSON([
            'ok'      => true,
            'ip'      => $wynik['ip'],
            'z_cache' => $wynik['z_cache'],
            'tryb'    => $wynik['tryb'],
            'wynik'   => $wynik['wynik'],
        ]);
    }
}
