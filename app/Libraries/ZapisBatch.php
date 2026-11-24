<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Zbiorcze zapisy INSERT/UPDATE (batch) — działa na PostgreSQL (produkcja) i SQLite (testy):
 * multi-row INSERT z bound params (?) oraz ON CONFLICT … DO UPDATE.
 *
 * Jedna runda do DB na paczkę (chunk 500–1000) zamiast pętli per wiersz.
 * ON CONFLICT zdejmuje wyścig check-then-insert (dwa procesy, ten sam klucz → oba OK).
 *
 * Uwaga SQLite3: PHP wykonuje zapytanie z RETURNING dwa razy (bug SQLite3::query),
 * więc RETURNING używamy tylko na sterowniku PostgreSQL; na SQLite id bierzemy z lastInsertRowID.
 */
final class ZapisBatch
{
    /**
     * Multi-row INSERT; zwraca id wstawionych wierszy w kolejności VALUES.
     *
     * @param list<array<string, mixed>> $wiersze niepusta lista wierszy o identycznych kluczach
     *
     * @return list<int>
     */
    public static function insertZwrocId(BaseConnection $db, string $tabela, array $wiersze): array
    {
        if ($wiersze === []) {
            return [];
        }
        [$sql, $binds] = self::zlozInsert($db, $tabela, $wiersze);

        if ($db->DBDriver !== 'SQLite3') {
            // PostgreSQL: INSERT … RETURNING id — jedna runda, id w kolejności VALUES.
            $rows = $db->query($sql . ' RETURNING id', $binds)->getResultArray();

            return array_map(static fn (array $r): int => (int) $r['id'], $rows);
        }

        // SQLite3 (testy): bez RETURNING (exec — pojedyncze wykonanie), id = zakres od lastInsertRowID.
        $db->query($sql, $binds);
        $ostatnie = (int) $db->insertID();
        $n        = count($wiersze);
        $ids      = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = $ostatnie - ($n - 1 - $i);
        }

        return $ids;
    }

    /**
     * Multi-row upsert: INSERT … ON CONFLICT (…) DO UPDATE SET … [WHERE …].
     * Bez RETURNING — pojedyncze wykonanie na obu sterownikach.
     *
     * Uwaga: wiersze w paczce muszą mieć unikalne klucze konfliktu — PostgreSQL i SQLite
     * odrzucają „cannot affect row a second time”; dedup kluczy robiony jest po stronie wołającego.
     *
     * @param list<array<string, mixed>> $wiersze
     * @param string                     $konflikt np. '(zrodlo, klucz_dedupu)' albo 'ioc'
     * @param list<string>               $set      przypisania, np. 'waga = excluded.waga'
     * @param string|null                $warunek  opcjonalny WHERE do DO UPDATE (np. tylko gdy wartość wzrosła)
     */
    public static function upsert(
        BaseConnection $db,
        string $tabela,
        array $wiersze,
        string $konflikt,
        array $set,
        ?string $warunek = null,
    ): void {
        if ($wiersze === []) {
            return;
        }
        [$sql, $binds] = self::zlozInsert($db, $tabela, $wiersze);
        $sql .= ' ON CONFLICT ' . $konflikt . ' DO UPDATE SET ' . implode(', ', $set);
        if ($warunek !== null && $warunek !== '') {
            $sql .= ' WHERE ' . $warunek;
        }
        $db->query($sql, $binds);
    }

    /**
     * UPDATE wielu wierszy jednym zapytaniem: SET kolumna = CASE … WHEN … THEN … END WHERE … IN (…).
     * Do podpinania per-wierszowych wartości (np. incydent_id) bez pętli per wiersz.
     *
     * @param array<int|string, mixed> $mapa klucz kolumny WHERE => nowa wartość kolumny SET
     */
    public static function updateCaseWhereIn(
        BaseConnection $db,
        string $tabela,
        string $kolumnaSet,
        string $kolumnaWhere,
        array $mapa,
    ): void {
        if ($mapa === []) {
            return;
        }
        $t         = $db->prefixTable($tabela);
        $when      = [];
        $whenBinds = [];
        $in        = [];
        $inBinds   = [];
        foreach ($mapa as $klucz => $wartosc) {
            $when[]       = 'WHEN ? THEN ?';
            $whenBinds[]  = $klucz;
            $whenBinds[]  = $wartosc;
            $in[]         = '?';
            $inBinds[]    = $klucz;
        }
        $sql = 'UPDATE ' . $t . ' SET ' . $kolumnaSet . ' = CASE ' . $kolumnaWhere . ' '
            . implode(' ', $when)
            . ' ELSE ' . $kolumnaSet . ' END WHERE ' . $kolumnaWhere . ' IN (' . implode(', ', $in) . ')';
        $db->query($sql, array_merge($whenBinds, $inBinds));
    }

    /**
     * @param list<array<string, mixed>> $wiersze
     *
     * @return array{string, list<mixed>}
     */
    private static function zlozInsert(BaseConnection $db, string $tabela, array $wiersze): array
    {
        $t       = $db->prefixTable($tabela);
        $kolumny = array_keys($wiersze[0]);
        $values  = [];
        $binds   = [];
        foreach ($wiersze as $w) {
            if (! is_array($w) || array_keys($w) !== $kolumny) {
                throw new RuntimeException('ZapisBatch: wiersze paczki muszą mieć identyczne kolumny (' . $tabela . ')');
            }
            $ph = [];
            foreach ($kolumny as $c) {
                $ph[]    = '?';
                $binds[] = $w[$c];
            }
            $values[] = '(' . implode(', ', $ph) . ')';
        }
        $sql = 'INSERT INTO ' . $t . ' (' . implode(', ', $kolumny) . ') VALUES ' . implode(', ', $values);

        return [$sql, $binds];
    }
}
