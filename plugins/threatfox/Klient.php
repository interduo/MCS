<?php

namespace McsPluginy\threatfox;

/**
 * Klient API ThreatFox (abuse.ch) — POST JSON z nagłówkiem Auth-Key.
 *
 * Endpoint (zweryfikowana dokumentacja):
 *   POST https://threatfox-api.abuse.ch/api/v1/
 *   body: {"query":"get_iocs","days":N}   (N: 1..7, domyślnie 7)
 *   nagłówek: Auth-Key: <klucz>           (klucz z opcji mcs.threatfox.authKey)
 *
 * Bez ponowień w jednym przebiegu: błąd kończy przebieg, backoff liczy Harmonogram.
 * Callable HTTP (do testów): fn(string $url, array $req): array{ok, http_code, body, error, retry_after},
 * gdzie $req = ['method' => 'POST', 'timeout' => int, 'body' => string, 'headers' => list<string>].
 */
class Klient
{
    public const URL_API = 'https://threatfox-api.abuse.ch/api/v1/';

    /** Domyślny User-Agent. Własny ustaw w .env / WWW: mcs.threatfox.userAgent. */
    public const USER_AGENT = 'MCS/1.0 (+https://mcs.alfa-system.net)';

    public const DNI_MIN = 1;
    public const DNI_MAX = 7;

    /** @var (callable(string, array): array{ok: bool, http_code: int, body: string, error: string, retry_after: int})|null */
    private $http;

    private int $timeout;

    private string $authKey;

    private int $days;

    /**
     * @param (callable(string, array): array{ok: bool, http_code: int, body: string, error: string, retry_after: int})|null $http
     */
    public function __construct(?callable $http = null, int $timeout = 30, string $authKey = '', ?int $days = null)
    {
        $this->http    = $http;
        $this->timeout = max(1, $timeout);
        $this->authKey = trim($authKey, " \t\n\r\0\x0B'\"");
        $this->days    = self::przytnijDni($days ?? self::dniZOpcji());
    }

    public static function fromOpcje(): self
    {
        return new self(
            null,
            \App\Libraries\OpcjeMcs::int('mcs.threatfox.httpTimeout'),
            self::authKeyZOpcji(),
            self::dniZOpcji(),
        );
    }

    public static function authKeyZOpcji(): string
    {
        return trim(\App\Libraries\OpcjeMcs::tekst('mcs.threatfox.authKey'), " \t\n\r\0\x0B'\"");
    }

    public static function dniZOpcji(): int
    {
        return self::przytnijDni(\App\Libraries\OpcjeMcs::int('mcs.threatfox.days'));
    }

    public static function przytnijDni(int $dni): int
    {
        return max(self::DNI_MIN, min(self::DNI_MAX, $dni));
    }

    /**
     * User-Agent tylko z .env (mcs.threatfox.userAgent) — jak u spamhaus; nie jest opcją WWW.
     */
    public static function userAgent(): string
    {
        $ua = trim((string) env('mcs.threatfox.userAgent', ''), " \t\n\r\0\x0B'\"");

        return $ua !== '' ? $ua : self::USER_AGENT;
    }

    public function dni(): int
    {
        return $this->days;
    }

    /**
     * @return array{ok: bool, http_code: int, body: string, error: string, retry_after: int}
     */
    public function pobierz(): array
    {
        if ($this->authKey === '') {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'brak Auth-Key (mcs.threatfox.authKey)', 'retry_after' => 0];
        }

        $req = [
            'method'  => 'POST',
            'timeout' => $this->timeout,
            'body'    => (string) json_encode(['query' => 'get_iocs', 'days' => $this->days]),
            'headers' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Auth-Key: ' . $this->authKey,
            ],
        ];

        $res = $this->http !== null
            ? ($this->http)(self::URL_API, $req)
            : $this->curl($req);

        if ($res['ok'] && trim($res['body']) === '') {
            return ['ok' => false, 'error' => 'HTTP ' . $res['http_code'] . ': pusta odpowiedź'] + $res;
        }

        return $res;
    }

    /**
     * @param array{method: string, timeout: int, body: string, headers: list<string>} $req
     *
     * @return array{ok: bool, http_code: int, body: string, error: string, retry_after: int}
     */
    private function curl(array $req): array
    {
        $retryAfter = 0;
        $ch         = curl_init(self::URL_API);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            // Redirecty tylko po https (egress https wymuszony także po redirectach).
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => min(15, $req['timeout']),
            CURLOPT_TIMEOUT        => $req['timeout'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $req['body'],
            CURLOPT_USERAGENT      => self::userAgent(),
            CURLOPT_HTTPHEADER     => $req['headers'],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $linia) use (&$retryAfter): int {
                if (stripos($linia, 'retry-after:') === 0) {
                    $retryAfter = (int) trim(substr($linia, 12));
                }

                return strlen($linia);
            },
        ]);
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => 'cURL: ' . $err, 'retry_after' => 0];
        }
        if ($code !== 200) {
            return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => 'HTTP ' . $code, 'retry_after' => $retryAfter];
        }

        return ['ok' => true, 'http_code' => $code, 'body' => (string) $out, 'error' => '', 'retry_after' => 0];
    }
}
