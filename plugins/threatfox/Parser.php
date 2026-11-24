<?php

namespace McsPluginy\threatfox;

use RuntimeException;

/**
 * Parser odpowiedzi ThreatFox API (pojedynczy obiekt JSON):
 *   {"query_status":"ok","data":[{id,ioc,ioc_type,threat_type,threat_type_desc,malware,
 *    malware_printable,confidence_level,first_seen,last_seen,reporter,reference,tags[]}, ...]}
 *
 * query_status: "ok" = dane (pusta lista przy ok = błąd — odpowiedź niekompletna),
 * "no_results" = sukces z 0 rekordami, cokolwiek innego ("illegal" itp.) = błąd.
 *
 * Z ioc_type bierzemy IP tylko dla "ip" i "ip:port" (z ip:port wyciągamy IP i port;
 * IPv6 z portem ma nawiasy: [2001:db8::1]:443). Pozostałe typy (domain, url, md5, …)
 * zostają jako rekord referencyjny z ip=null — bez trafień.
 */
final class Parser
{
    /**
     * @return list<array<string, mixed>> rekordy (kolejność jak w odpowiedzi)
     */
    public function parsuj(string $tresc): array
    {
        if (trim($tresc) === '') {
            throw new RuntimeException('ThreatFox: pusta odpowiedź');
        }

        $obj = json_decode($tresc, true);
        if (! is_array($obj)) {
            throw new RuntimeException('ThreatFox: odpowiedź nie jest poprawnym JSON-em');
        }

        $status = strtolower(trim((string) ($obj['query_status'] ?? '')));
        if ($status === 'no_results') {
            return [];
        }
        if ($status !== 'ok') {
            throw new RuntimeException('ThreatFox: query_status=' . ($status !== '' ? $status : '(brak)'));
        }

        $data = $obj['data'] ?? null;
        if (! is_array($data) || $data === []) {
            throw new RuntimeException('ThreatFox: query_status=ok, ale pusta lista data (odpowiedź niekompletna?)');
        }

        $out = [];
        foreach ($data as $r) {
            if (! is_array($r)) {
                continue;
            }
            $ioc = trim((string) ($r['ioc'] ?? ''));
            if ($ioc === '') {
                continue;
            }
            $typ         = strtolower(trim((string) ($r['ioc_type'] ?? '')));
            [$ip, $port] = self::wyciagnijIpPort($ioc, $typ);

            $tags = [];
            if (is_array($r['tags'] ?? null)) {
                foreach ($r['tags'] as $t) {
                    $t = trim((string) $t);
                    if ($t !== '') {
                        $tags[] = $t;
                    }
                }
            }

            $out[] = [
                'id'                => (string) ($r['id'] ?? ''),
                'ioc'               => $ioc,
                'ioc_type'          => $typ,
                'ip'                => $ip,
                'port'              => $port,
                'threat_type'       => strtolower(trim((string) ($r['threat_type'] ?? ''))),
                'threat_type_desc'  => (string) ($r['threat_type_desc'] ?? ''),
                'malware'           => (string) ($r['malware'] ?? ''),
                'malware_printable' => (string) ($r['malware_printable'] ?? ''),
                'confidence_level'  => isset($r['confidence_level']) && is_numeric($r['confidence_level'])
                    ? max(0, min(100, (int) $r['confidence_level']))
                    : null,
                'first_seen'        => (string) ($r['first_seen'] ?? ''),
                'last_seen'         => (string) ($r['last_seen'] ?? ''),
                'reporter'          => (string) ($r['reporter'] ?? ''),
                'reference'         => (string) ($r['reference'] ?? ''),
                'tags'              => $tags,
            ];
        }

        return $out;
    }

    /**
     * Wyciąga IP (i port) z IOC typu ip / ip:port. Zwraca [null, null] dla innych typów
     * albo gdy adres nie przechodzi walidacji.
     *
     * @return array{0: ?string, 1: ?int}
     */
    public static function wyciagnijIpPort(string $ioc, string $typ): array
    {
        if ($typ !== 'ip' && $typ !== 'ip:port') {
            return [null, null];
        }
        if ($typ === 'ip') {
            return filter_var($ioc, FILTER_VALIDATE_IP) !== false ? [$ioc, null] : [null, null];
        }

        // ip:port — IPv4 "1.2.3.4:443", IPv6 w nawiasach "[2001:db8::1]:443"
        if (preg_match('/^\[(?<adres>[^\]]+)\]:(?<port>\d{1,5})$/', $ioc, $m) === 1) {
            $ip   = $m['adres'];
            $port = (int) $m['port'];
        } elseif (substr_count($ioc, ':') === 1) {
            [$ip, $ps] = explode(':', $ioc, 2);
            $port      = ctype_digit($ps) ? (int) $ps : null;
        } else {
            return [null, null]; // gołe IPv6 bez nawiasów w polu ip:port — niejednoznaczne
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [null, null];
        }
        if ($port !== null && ($port < 0 || $port > 65535)) {
            $port = null;
        }

        return [$ip, $port];
    }
}
