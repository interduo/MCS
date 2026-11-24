<?php

namespace App\Models;

/**
 * Cache lookup AbuseIPDB — nie łączy się z mcs_incydenty.
 */
class EnrichmentAbuseipdbModel extends EnrichmentCacheModel
{
    protected $table         = 'mcs_enrichment_abuseipdb';
    protected $allowedFields = [
        'ip',
        'abuse_confidence_score',
        'total_reports',
        'last_reported_at',
        'country_code',
        'isp',
        'usage_type',
        'is_whitelisted',
        'raw_json',
        'zrodlo_danych',
        'checked_at',
        'created_at',
        'updated_at',
    ];
}
