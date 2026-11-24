<?php

namespace App\Libraries;

/**
 * Neutralizacja CSV/formula injection (OWASP): pola zaczynające się od =, +, -, @, tab lub CR
 * dostają prefix apostrofu, żeby arkusz (Excel/LibreOffice) nie wykonał formuły/DDE.
 *
 * Używane we wszystkich eksportach CSV (dashboard, skany DNSBL) — wartości mogą pochodzić
 * z zewnętrznych feedów (ThreatFox, Shadowserver, URLhaus, TXT z DNS).
 */
final class CsvBezpieczenstwo
{
    /** Pierwsze znaki oznaczające formułę w arkuszu kalkulacyjnym. */
    private const ZNAKI_FORMULY = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Wartość pola CSV gotowa do fputcsv — złośliwy początek dostaje prefix '.
     */
    public static function pole(mixed $wartosc): string
    {
        $v = (string) ($wartosc ?? '');

        return $v !== '' && in_array($v[0], self::ZNAKI_FORMULY, true) ? "'" . $v : $v;
    }
}
