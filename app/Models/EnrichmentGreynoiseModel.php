<?php

namespace App\Models;

/**
 * Cache lookup GreyNoise Community — nie łączy się z mcs_incydenty.
 */
class EnrichmentGreynoiseModel extends EnrichmentCacheModel
{
    protected $table         = 'mcs_enrichment_greynoise';
    protected $allowedFields = [
        'ip',
        'noise',
        'riot',
        'classification',
        'name',
        'link',
        'last_seen',
        'message',
        'http_status',
        'raw_json',
        'zrodlo_danych',
        'checked_at',
        'created_at',
        'updated_at',
    ];
}
