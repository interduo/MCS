<?php

namespace App\Models;

/**
 * Cache lookup multi-DNSBL — panel /ip i dashboard.
 */
class EnrichmentDnsblModel extends EnrichmentCacheModel
{
    protected $table         = 'mcs_enrichment_dnsbl';
    protected $allowedFields = [
        'ip',
        'listed_any',
        'listy_json',
        'raw_json',
        'zrodlo_danych',
        'zakres_skanu',
        'checked_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Aktualnie listed IP (do dashboardu).
     *
     * @return list<array<string, mixed>>
     */
    public function listedAktualne(?callable $akceptujIp = null, ?string $listaFiltr = null, int $limit = 500): array
    {
        $builder = $this->builder();
        $builder->where('listed_any', true);
        $builder->orderBy('checked_at', 'DESC');
        $builder->limit(max(1, min(2000, $limit)));
        $rows = $builder->get()->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $ip = (string) ($row['ip'] ?? '');
            if ($ip === '') {
                continue;
            }
            if ($akceptujIp !== null && ! $akceptujIp($ip)) {
                continue;
            }
            if ($listaFiltr !== null && $listaFiltr !== '') {
                $listy = $row['listy_json'] ?? [];
                if (is_string($listy)) {
                    $decoded = json_decode($listy, true);
                    $listy   = is_array($decoded) ? $decoded : [];
                }
                if (! is_array($listy) || ! isset($listy[$listaFiltr]['listed']) || ! $listy[$listaFiltr]['listed']) {
                    continue;
                }
            }
            $out[] = $row;
        }

        return $out;
    }
}
