<?php

namespace App\Models;

/**
 * Cache mapowania LMS (IP → komputer/abonent) — nie łączy się z mcs_incydenty.
 */
class EnrichmentLmsModel extends EnrichmentCacheModel
{
    protected $table         = 'mcs_enrichment_lms';
    protected $allowedFields = [
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
}
