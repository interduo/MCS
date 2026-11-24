<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Issue #450: wyszukiwarka historii importów (ImportModel::szukaj) robi
 * LIKE '%q%' na blad_text OR id_wiadomosci OR checksum — leading-wildcard LIKE nie używa
 * zwykłego btree, więc każde wyszukanie to seq scan mcs_importy (plus drugi skan COUNT z paginate).
 *
 * Dla dowolnego tekstu nie da się tego zrobić zakresem (jak dla /24), więc jedyne rozwiązanie
 * to pg_trgm: CREATE EXTENSION IF NOT EXISTS pg_trgm (od PG13 rozszerzenie jest „trusted”,
 * więc wystarczy zwykłe CREATE na bazie) + indeksy GIN gin_trgm_ops na WSZYSTKICH trzech
 * kolumnach warunku OR — dopiero wtedy planner może użyć BitmapOr i uniknąć Seq Scan.
 * Na innych platformach (SQLite w testach) migracja jest no-op.
 */
class PgTrgmMcsImportySzukaj extends Migration
{
    public function up()
    {
        if ($this->db->getPlatform() !== 'Postgre') {
            return;
        }
        $this->db->query('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $t = $this->db->prefixTable('mcs_importy');
        $this->db->query("CREATE INDEX IF NOT EXISTS mcs_importy_blad_text_trgm_idx ON {$t} USING GIN (blad_text gin_trgm_ops)");
        $this->db->query("CREATE INDEX IF NOT EXISTS mcs_importy_id_wiadomosci_trgm_idx ON {$t} USING GIN (id_wiadomosci gin_trgm_ops)");
        $this->db->query("CREATE INDEX IF NOT EXISTS mcs_importy_checksum_trgm_idx ON {$t} USING GIN (checksum gin_trgm_ops)");
    }

    public function down()
    {
        if ($this->db->getPlatform() !== 'Postgre') {
            return;
        }
        $this->db->query('DROP INDEX IF EXISTS mcs_importy_checksum_trgm_idx');
        $this->db->query('DROP INDEX IF EXISTS mcs_importy_id_wiadomosci_trgm_idx');
        $this->db->query('DROP INDEX IF EXISTS mcs_importy_blad_text_trgm_idx');
    }
}
