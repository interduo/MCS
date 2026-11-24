<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Issue #446: skaner DNSBL czyta stare listingi per strefa
 * (WHERE strefa_kod = ? AND aktywny = true), a unikat (ip, strefa_kod) nie obsługuje
 * filtra po samej drugiej kolumnie — seq scan mcs_dnsbl_listingi per strefa.
 * Indeks kompozytowy (strefa_kod, aktywny) pokrywa oba zapytania skanera.
 */
class IndeksMcsDnsblListingiStrefaAktywny extends Migration
{
    public function up()
    {
        $t = $this->db->prefixTable('mcs_dnsbl_listingi');
        $this->db->query(
            "CREATE INDEX IF NOT EXISTS mcs_dnsbl_listingi_strefa_aktywny_idx
             ON {$t} (strefa_kod, aktywny)",
        );
    }

    public function down()
    {
        $this->db->query('DROP INDEX IF EXISTS mcs_dnsbl_listingi_strefa_aktywny_idx');
    }
}
