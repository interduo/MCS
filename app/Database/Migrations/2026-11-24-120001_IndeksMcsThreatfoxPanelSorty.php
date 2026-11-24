<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Issue #449: panel threatfox (plugins/threatfox/Widok.php::dane) czyta kartę sortami
 * ORDER BY aktywne DESC, ostatnie_at DESC LIMIT 200 na mcs_threatfox_trafienia oraz
 * ORDER BY aktywne DESC, ostatni_import_at DESC LIMIT 100 na mcs_threatfox_ioc.
 * Istniejący z migracji CreateMcsThreatfox addKey('aktywne') takiego sortu nie pokrywa —
 * PG sortuje wszystkie wiersze tabeli przed LIMIT (pełny sort przy każdym otwarciu panelu).
 *
 * Indeksy kompozytowe (aktywne DESC, <data> DESC) dokładnie pod te ORDER BY pozwalają
 * plannerowi na Index Scan bez węzła Sort (EXPLAIN: Index Scan using ... , bez Sort).
 */
class IndeksMcsThreatfoxPanelSorty extends Migration
{
    public function up()
    {
        $trafienia = $this->db->prefixTable('mcs_threatfox_trafienia');
        $this->db->query(
            "CREATE INDEX IF NOT EXISTS mcs_threatfox_trafienia_aktywne_ostatnie_at_idx
             ON {$trafienia} (aktywne DESC, ostatnie_at DESC)",
        );
        $ioc = $this->db->prefixTable('mcs_threatfox_ioc');
        $this->db->query(
            "CREATE INDEX IF NOT EXISTS mcs_threatfox_ioc_aktywne_ostatni_import_at_idx
             ON {$ioc} (aktywne DESC, ostatni_import_at DESC)",
        );
    }

    public function down()
    {
        $this->db->query('DROP INDEX IF EXISTS mcs_threatfox_ioc_aktywne_ostatni_import_at_idx');
        $this->db->query('DROP INDEX IF EXISTS mcs_threatfox_trafienia_aktywne_ostatnie_at_idx');
    }
}
