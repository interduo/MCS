<?php

namespace App\Libraries\Siec;

use App\Libraries\Dnsbl\DnsPakiet;
use App\Libraries\Dnsbl\DnsblNarzedzia;
use App\Libraries\OpcjeMcs;

/**
 * Synchroniczny lookup PTR (RFC 1035, typ 12) po UDP z TWARDYM timeoutem
 * (stream_select) — zamiennik blokującego gethostbyaddr() w requestcie HTTP
 * (issue #447: zimny cache revDNS nie może wieszać workera php-fpm).
 *
 * Resolver: lokalny unbound (mcs.revDns.resolver, domyślnie 127.0.0.1) — jak skaner DNSBL,
 * nie systemowy resolv.conf. Timeout: mcs.revDns.timeoutMs (domyślnie 300 ms).
 * Timeout / NXDOMAIN / błąd / śmieciowy pakiet → null (fallback „bez PTR”).
 */
final class PtrResolver
{
    public const TYP_PTR = 12;

    private string $serwer;
    private int $port;
    private float $timeout;

    public function __construct(?string $serwer = null, ?int $port = null, ?float $timeoutMs = null)
    {
        $resolver = trim((string) ($serwer ?? env('mcs.revDns.resolver', '127.0.0.1')));
        if (filter_var($resolver, FILTER_VALIDATE_IP) === false) {
            $resolver = '127.0.0.1';
        }
        $this->serwer  = $resolver;
        $this->port    = max(1, $port ?? 53);
        $ms            = $timeoutMs ?? OpcjeMcs::int('mcs.revDns.timeoutMs');
        $this->timeout = max(0.05, $ms / 1000);
    }

    /**
     * Hostname PTR albo null. Całość ograniczona twardym timeoutem — nigdy nie blokuje dłużej.
     */
    public function lookup(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $socket = null;
        try {
            $nazwa = self::nazwaPtr($ip);
            $id    = random_int(0, 0xFFFF);
            $pak   = DnsPakiet::zapytanie($id, $nazwa, self::TYP_PTR);

            $err    = 0;
            $msg    = '';
            $socket = @stream_socket_client('udp://' . $this->serwer . ':' . $this->port, $err, $msg, $this->timeout);
            if ($socket === false) {
                return null;
            }
            stream_set_blocking($socket, false);
            if (@fwrite($socket, $pak) !== strlen($pak)) {
                return null;
            }

            $termin = microtime(true) + $this->timeout;
            while (true) {
                $pozostaje = $termin - microtime(true);
                if ($pozostaje <= 0.0) {
                    return null; // twardy timeout — dalej już nie czekamy
                }
                $czytaj = [$socket];
                $zapis  = null;
                $wyj    = null;
                $sek    = (int) floor($pozostaje);
                $usek   = (int) (($pozostaje - $sek) * 1_000_000);
                $n      = @stream_select($czytaj, $zapis, $wyj, $sek, $usek);
                if ($n === false || $n === 0) {
                    continue; // brak danych przed terminem — pętla sama dojdzie do timeoutu
                }
                $buf = @stream_socket_recvfrom($socket, 4096);
                if ($buf === false || $buf === '') {
                    return null; // ICMP/odmowa (np. port zamknięty) — nie czekamy do końca
                }

                return self::parsujPtr($buf, $id);
            }
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /**
     * Nazwa zapytania PTR: odwrócone oktety + in-addr.arpa (IPv4) albo nibble + ip6.arpa (IPv6).
     */
    public static function nazwaPtr(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return DnsblNarzedzia::odwrocOktety($ip) . '.in-addr.arpa';
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            throw new \InvalidArgumentException('To nie jest adres IP: ' . $ip);
        }
        $nibble = str_split(bin2hex($bin));

        return implode('.', array_reverse($nibble)) . '.ip6.arpa';
    }

    /**
     * Parsuje odpowiedź DNS; zwraca pierwszy rekord PTR albo null (NXDOMAIN / brak PTR / zły pakiet).
     * Public static (jak DnsPakiet::parsuj) — testowalne bez sieci.
     */
    public static function parsujPtr(string $buf, int $oczekiwaneId): ?string
    {
        if (strlen($buf) < 12) {
            return null;
        }
        $h = unpack('nid/nflagi/nqd/nan/nns/nar', substr($buf, 0, 12));
        if ($h === false || ($h['flagi'] & 0x8000) === 0 || $h['id'] !== $oczekiwaneId) {
            return null;
        }

        $off = 12;
        for ($i = 0; $i < $h['qd']; $i++) {
            $off = self::pominNazwe($buf, $off) + 4;
        }
        for ($i = 0; $i < $h['an']; $i++) {
            $off = self::pominNazwe($buf, $off);
            if ($off + 10 > strlen($buf)) {
                return null;
            }
            $r = unpack('ntyp/nklasa/Nttl/ndl', substr($buf, $off, 10));
            if ($r === false) {
                return null;
            }
            $off += 10;
            if ($off + $r['dl'] > strlen($buf)) {
                return null;
            }
            if ($r['typ'] === self::TYP_PTR) {
                [$nazwa] = self::czytajNazwe($buf, $off);

                return $nazwa === '' ? null : $nazwa;
            }
            $off += $r['dl'];
        }

        return null;
    }

    private static function pominNazwe(string $buf, int $off): int
    {
        $len = strlen($buf);
        while (true) {
            if ($off >= $len) {
                throw new \UnexpectedValueException('Ucięta nazwa DNS');
            }
            $dl = ord($buf[$off]);
            if ($dl === 0) {
                return $off + 1;
            }
            if (($dl & 0xC0) === 0xC0) {
                return $off + 2;
            }
            $off += $dl + 1;
        }
    }

    /**
     * Dekoduje nazwę DNS (z kompresją — wskaźniki 0xC0) spod offsetu.
     *
     * @return array{0: string, 1: int} nazwa bez końcowej kropki + offset tuż za nazwą w oryginale
     */
    private static function czytajNazwe(string $buf, int $off): array
    {
        $len      = strlen($buf);
        $etykiety = [];
        $koniec   = $off;
        $poSkoku  = false;
        $skoki    = 0;
        while (true) {
            if ($off >= $len || $skoki > 16) {
                throw new \UnexpectedValueException('Ucięta/pętlowa nazwa DNS');
            }
            $dl = ord($buf[$off]);
            if ($dl === 0) {
                if (! $poSkoku) {
                    $koniec = $off + 1;
                }
                break;
            }
            if (($dl & 0xC0) === 0xC0) {
                if ($off + 1 >= $len) {
                    throw new \UnexpectedValueException('Ucięty wskaźnik kompresji DNS');
                }
                if (! $poSkoku) {
                    $koniec = $off + 2;
                }
                $off = (($dl & 0x3F) << 8) | ord($buf[$off + 1]);
                $poSkoku = true;
                $skoki++;
                continue;
            }
            if ($off + 1 + $dl > $len) {
                throw new \UnexpectedValueException('Ucięta etykieta DNS');
            }
            $etykiety[] = substr($buf, $off + 1, $dl);
            $off       += $dl + 1;
        }

        return [implode('.', $etykiety), $koniec];
    }
}
