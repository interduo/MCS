<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Wspólna baza modeli cache enrichment (tabele mcs_enrichment_* z PK `ip`).
 *
 * Logika znajdzPoIp / upsertDlaIp / cacheWazny jest jedna dla wszystkich źródeł;
 * klasy potomne deklarują tylko $table i $allowedFields (plus własne metody).
 */
abstract class EnrichmentCacheModel extends Model
{
    protected $primaryKey       = 'ip';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    /**
     * @return array<string, mixed>|null
     */
    public function znajdzPoIp(string $ip): ?array
    {
        $wiersz = $this->find($ip);

        return is_array($wiersz) ? $wiersz : null;
    }

    /**
     * Zbiorczy lookup po wielu IP (issue #445): jeden SELECT … WHERE ip IN (…) na partię 500,
     * zamiast N zapytań per IP. Zwraca mapę ip => wiersz.
     *
     * @param list<string> $ips
     *
     * @return array<string, array<string, mixed>>
     */
    public function znajdzWiele(array $ips): array
    {
        $unikalne = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '') {
                $unikalne[$ip] = true;
            }
        }

        $mapa = [];
        foreach (array_chunk(array_keys($unikalne), 500) as $partia) {
            $wiersze = $this->whereIn('ip', $partia)->findAll();
            foreach ($wiersze as $w) {
                if (is_array($w) && isset($w['ip'])) {
                    $mapa[(string) $w['ip']] = $w;
                }
            }
        }

        return $mapa;
    }

    /**
     * Upsert po PK `ip` — atomowy INSERT … ON CONFLICT (brak wyścigu check-then-insert).
     *
     * @param array<string, mixed> $dane
     */
    public function upsertDlaIp(string $ip, array $dane): void
    {
        $this->upsertWieleDlaIp([array_merge($dane, ['ip' => $ip])]);
    }

    /**
     * Batch upsert po PK `ip` (issue #458): jedno INSERT … ON CONFLICT (ip) DO UPDATE na partię 500
     * wierszy (multi-row VALUES) zamiast upsertu per IP przy pełnym skanie AS.
     * Przy konflikcie PK i created_at zostają bez zmian.
     *
     * @param list<array<string, mixed>> $wiersze każdy z kluczem ip i polami z $allowedFields
     */
    public function upsertWieleDlaIp(array $wiersze): void
    {
        if ($wiersze === []) {
            return;
        }
        // dedup po ip (ostatni wygrywa jak przy sekwencyjnym upsert) — duplikat w jednym
        // batch INSERT … ON CONFLICT rzuciłby błąd „cannot affect row a second time”
        $poKluczu = [];
        foreach ($wiersze as $w) {
            if (isset($w['ip'])) {
                $poKluczu[(string) $w['ip']] = $w;
            } else {
                $poKluczu[] = $w;
            }
        }
        $wiersze = array_values($poKluczu);
        $t       = $this->db->prefixTable($this->table);
        foreach (array_chunk($wiersze, 500) as $partia) {
            $teraz    = gmdate('Y-m-d H:i:sP');
            $payloads = [];
            $kolumny  = [];
            foreach ($partia as $w) {
                $payload = array_merge($w, [
                    'created_at' => $teraz,
                    'updated_at' => $teraz,
                    'checked_at' => $w['checked_at'] ?? $teraz,
                ]);
                // tylko pola z $allowedFields (jak insert()/update()); kolumny = unia kluczy partii
                $payload    = array_intersect_key($payload, array_flip($this->allowedFields));
                $payloads[] = $payload;
                foreach (array_keys($payload) as $k) {
                    $kolumny[$k] = true;
                }
            }
            $kolumny      = array_keys($kolumny);
            $aktualizacje = [];
            foreach ($kolumny as $kolumna) {
                // przy konflikcie PK i created_at zostają bez zmian
                if ($kolumna === 'ip' || $kolumna === 'created_at') {
                    continue;
                }
                $aktualizacje[] = $kolumna . ' = EXCLUDED.' . $kolumna;
            }
            $values      = [];
            $params      = [];
            $placeholder = '(' . implode(', ', array_fill(0, count($kolumny), '?')) . ')';
            foreach ($payloads as $payload) {
                $values[] = $placeholder;
                foreach ($kolumny as $kolumna) {
                    $params[] = $payload[$kolumna] ?? null;
                }
            }
            $this->db->query(
                'INSERT INTO ' . $t . ' (' . implode(', ', $kolumny) . ') VALUES ' . implode(', ', $values)
                . ' ON CONFLICT (ip) DO UPDATE SET ' . implode(', ', $aktualizacje),
                $params,
            );
        }
    }

    public function cacheWazny(?array $wiersz, int $ttlSek): bool
    {
        if ($wiersz === null || $ttlSek <= 0) {
            return false;
        }
        $checked = $wiersz['checked_at'] ?? null;
        if (! is_string($checked) || $checked === '') {
            return false;
        }
        $ts = strtotime($checked);
        if ($ts === false) {
            return false;
        }

        return (time() - $ts) < $ttlSek;
    }
}
