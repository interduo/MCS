<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Cache mapowania LMS (IP → komputer/abonent) — nie łączy się z mcs_incydenty.
 */
class EnrichmentLmsModel extends Model
{
    protected $table            = 'mcs_enrichment_lms';
    protected $primaryKey       = 'ip';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'ip',
        'node_id',
        'komputer',
        'node_login',
        'customer_id',
        'abonent',
        'customer_lastname',
        'customer_name',
        'netid',
        'access',
        'trafiony',
        'raw_json',
        'zrodlo_danych',
        'checked_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function znajdzPoIp(string $ip): ?array
    {
        $wiersz = $this->find($ip);

        return is_array($wiersz) ? $wiersz : null;
    }

    /**
     * Upsert po PK `ip` — atomowy INSERT … ON CONFLICT (brak wyścigu check-then-insert).
     *
     * @param array<string, mixed> $dane
     */
    public function upsertDlaIp(string $ip, array $dane): void
    {
        $teraz   = gmdate('Y-m-d H:i:sP');
        $payload = array_merge($dane, [
            'ip'         => $ip,
            'created_at' => $teraz,
            'updated_at' => $teraz,
            'checked_at' => $dane['checked_at'] ?? $teraz,
        ]);
        // tylko pola z $allowedFields (jak insert()/update())
        $payload = array_intersect_key($payload, array_flip($this->allowedFields));

        $kolumny      = array_keys($payload);
        $miejsca      = implode(', ', array_fill(0, count($payload), '?'));
        $aktualizacje = [];
        foreach ($kolumny as $kolumna) {
            // przy konflikcie PK i created_at zostają bez zmian
            if ($kolumna === 'ip' || $kolumna === 'created_at') {
                continue;
            }
            $aktualizacje[] = $kolumna . ' = EXCLUDED.' . $kolumna;
        }

        $this->db->query(
            'INSERT INTO ' . $this->db->prefixTable($this->table)
            . ' (' . implode(', ', $kolumny) . ') VALUES (' . $miejsca . ')'
            . ' ON CONFLICT (ip) DO UPDATE SET ' . implode(', ', $aktualizacje),
            array_values($payload),
        );
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
