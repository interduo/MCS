<?php

namespace App\Libraries\Siec;

/**
 * Walidacja egress HTTP (ochrona przed SSRF przy błędnej/zhakowanej konfiguracji):
 *  - tylko schemat https, niepusty host, bez userinfo (user:pass@host),
 *  - host będący literałem IP musi być publiczny,
 *  - po resolpcie DNS wszystkie adresy hosta muszą być publiczne
 *    (RFC1918, loopback, link-local 169.254.0.0/16, CGNAT, multicast, zakresy dokumentacji).
 *
 * Wzorzec: plugins/urlhaus/Klient.php (wymusza https + niepusty host).
 * Klienci HTTP walidują baseUrl/apiPath przy starcie (fromEnv → baseUrlZEnv),
 * a przed każdym realnym żądaniem curl — waliduj() (z resolve DNS).
 */
final class BezpiecznyUrl
{
    /** Zakresy blokowane dla egressu (IPv4 + IPv6). */
    private const ZABLOKOWANE_CIDRY = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
        '2001:db8::/32',
    ];

    /** @var array<string, bool> cache: host (lowercase) => czy wszystkie jego IP są publiczne */
    private static array $cacheHostow = [];

    private function __construct()
    {
    }

    /**
     * Walidacja schematu + hostu bez DNS (szybka — przy starcie klienta z .env).
     *
     * @return ?string null gdy URL jest bezpieczny, komunikat przy odrzuceniu
     */
    public static function walidujSchemat(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return 'pusty URL';
        }

        $c = parse_url($url);
        if (! is_array($c)) {
            return 'nieprawidłowy URL';
        }

        $schemat = strtolower((string) ($c['scheme'] ?? ''));
        if ($schemat !== 'https') {
            return 'schemat musi być https (jest: ' . ($schemat !== '' ? $schemat : 'brak') . ')';
        }

        $host = self::host($url);
        if ($host === '') {
            return 'brak hosta';
        }

        if (isset($c['user']) || isset($c['pass'])) {
            return 'URL nie może zawierać danych logowania (user:pass@host)';
        }

        if (array_key_exists('port', $c) && ((int) $c['port'] < 1 || (int) $c['port'] > 65535)) {
            return 'nieprawidłowy port';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false && ! self::ipPubliczny($host)) {
            return 'host to zablokowany adres IP ' . $host;
        }

        return null;
    }

    /**
     * Pełna walidacja egress: schemat https + resolve DNS hosta;
     * każdy rozwiązany adres IP musi być publiczny.
     * Niepowodzenie resolpcji = odrzucenie (fail-closed).
     *
     * @param ?callable(string): list<string> $resolver zwraca listę A dla hosta (domyślnie gethostbynamel)
     *
     * @return ?string null gdy OK, komunikat przy odrzuceniu
     */
    public static function waliduj(string $url, ?callable $resolver = null): ?string
    {
        $blad = self::walidujSchemat($url);
        if ($blad !== null) {
            return $blad;
        }

        $host = self::host($url);
        if (! self::hostPubliczny($host, $resolver)) {
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return 'host to zablokowany adres IP ' . $host;
            }

            return 'host ' . $host . ' nie rozwiązuje do publicznego adresu IP (odrzucony)';
        }

        return null;
    }

    /**
     * Base URL z .env dla klienta HTTP: gdy niebezpieczny — loguje warning
     * i zwraca '' (klient traktuje to jak nieustawioną opcję / fallback domyślny).
     */
    public static function baseUrlZEnv(string $base, string $kluczEnv): string
    {
        $base = trim($base);
        if ($base === '') {
            return '';
        }

        $blad = self::walidujSchemat($base);
        if ($blad === null) {
            return $base;
        }

        log_message('warning', 'MCS egress odrzucony dla ' . $kluczEnv . ' (' . $blad . ') host=' . self::host($base));

        return '';
    }

    /**
     * Czy wszystkie adresy IP hosta (literał IP albo nazwa po resolpcie DNS) są publiczne.
     *
     * @param ?callable(string): list<string> $resolver
     */
    public static function hostPubliczny(string $host, ?callable $resolver = null): bool
    {
        $host = strtolower(trim($host, " \t[]"));
        if ($host === '') {
            return false;
        }

        if (array_key_exists($host, self::$cacheHostow)) {
            return self::$cacheHostow[$host];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::$cacheHostow[$host] = self::ipPubliczny($host);
        }

        $resolver ??= static fn (string $h): array => gethostbynamel($h) ?: [];
        $ipy       = $resolver($host);
        if (! is_array($ipy) || $ipy === []) {
            // Fail-closed: bez resolpcji nie wiemy, dokąd pójdzie żądanie.
            return self::$cacheHostow[$host] = false;
        }

        foreach ($ipy as $ip) {
            if (! self::ipPubliczny((string) $ip)) {
                return self::$cacheHostow[$host] = false;
            }
        }

        return self::$cacheHostow[$host] = true;
    }

    /**
     * Czy adres IP jest publiczny (nie RFC1918/loopback/link-local/CGNAT/multicast/zastawiony).
     */
    public static function ipPubliczny(string $ip): bool
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return ! (new CidrMatcher())->ipWLiscie($ip, self::ZABLOKOWANE_CIDRY);
    }

    /**
     * Host z URL (bez nawiasów IPv6, bez portu).
     */
    public static function host(string $url): string
    {
        $c    = parse_url(trim($url));
        $host = is_array($c) ? (string) ($c['host'] ?? '') : '';

        return trim($host, '[]');
    }

    /**
     * Czyszczenie cache DNS (testy).
     */
    public static function resetujCache(): void
    {
        self::$cacheHostow = [];
    }
}
