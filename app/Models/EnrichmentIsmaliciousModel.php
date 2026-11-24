<?php

namespace App\Models;

/**
 * Cache lookup isMalicious — nie łączy się z mcs_incydenty.
 */
class EnrichmentIsmaliciousModel extends EnrichmentCacheModel
{
    protected $table         = 'mcs_enrichment_ismalicious';
    protected $allowedFields = [
        'ip',
        'malicious',
        'risk_score',
        'confidence',
        'blocklist_hits',
        'blocklist_listed',
        'classification',
        'raw_json',
        'zrodlo_danych',
        'checked_at',
        'created_at',
        'updated_at',
    ];
}
