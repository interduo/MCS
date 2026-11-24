<?php

namespace App\Models;

/**
 * Cache lookup SpamRats — nie łączy się z mcs_incydenty.
 */
class EnrichmentSpamratsModel extends EnrichmentCacheModel
{
    protected $table         = 'mcs_enrichment_spamrats';
    protected $allowedFields = [
        'ip',
        'listed',
        'listed_dyna',
        'listed_noptr',
        'listed_spam',
        'listed_auth',
        'kody_json',
        'raw_json',
        'zrodlo_danych',
        'checked_at',
        'created_at',
        'updated_at',
    ];
}
