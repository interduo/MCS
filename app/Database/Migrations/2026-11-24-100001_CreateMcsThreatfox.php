<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Plugin threatfox — IOC abuse.ch ThreatFox (get_iocs, ostatnie 1–7 dni) dopasowane lokalnie
 * do prefiksów AS (as198050_cidry). Tylko ioc_type ip / ip:port dają trafienia.
 *
 * mcs_threatfox_stan       — jeden wiersz (id=1): harmonogram, backoff, wynik (brak|czysto|trafienie).
 * mcs_threatfox_ioc        — wszystkie IOC z ostatniej odpowiedzi (UNIQUE ioc), aktywne/zdjęte, pola ThreatFox.
 * mcs_threatfox_trafienia  — stan bieżący trafień (unikat ioc+nasz_prefiks), aktywne/zdjęte.
 * mcs_threatfox_historia   — dziennik: pobrano / nowe / zdjete / blad.
 */
class CreateMcsThreatfox extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'default' => 1],
            'ostatnia_proba_at' => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'ostatnie_ok_at'    => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'nastepne_po'       => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'bledy_z_rzedu'     => ['type' => 'INT', 'default' => 0],
            'ostatni_blad'      => ['type' => 'TEXT', 'null' => true],
            'ostatni_blad_at'   => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'wynik'             => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'brak'],
            'wynik_at'          => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'aktywne_trafienia' => ['type' => 'INT', 'default' => 0],
            'rekordy'           => ['type' => 'INT', 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('mcs_threatfox_stan', true);
        $this->db->table('mcs_threatfox_stan')->insert(['id' => 1, 'wynik' => 'brak', 'bledy_z_rzedu' => 0, 'aktywne_trafienia' => 0, 'rekordy' => 0]);

        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'auto_increment' => true],
            'ioc'                => ['type' => 'TEXT'],
            'ioc_type'           => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => ''],
            'ip'                 => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'port'               => ['type' => 'INT', 'null' => true],
            'threat_type'        => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],
            'threat_type_desc'   => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'malware'            => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'malware_printable'  => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'confidence_level'   => ['type' => 'INT', 'null' => true],
            'first_seen'         => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'last_seen'          => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'reporter'           => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'reference'          => ['type' => 'TEXT', 'null' => true],
            'tags'               => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'threatfox_id'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'aktywne'            => ['type' => 'BOOLEAN', 'default' => true],
            'zdjete_at'          => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'pierwszy_import_at' => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'ostatni_import_at'  => ['type' => 'TIMESTAMPTZ', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['ioc']);
        $this->forge->addKey('aktywne');
        $this->forge->addKey('ip');
        $this->forge->createTable('mcs_threatfox_ioc', true);

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'auto_increment' => true],
            'ioc'          => ['type' => 'TEXT'],
            'nasz_prefiks' => ['type' => 'VARCHAR', 'constraint' => 64],
            'ip'           => ['type' => 'VARCHAR', 'constraint' => 45],
            'port'         => ['type' => 'INT', 'null' => true],
            'threat_type'  => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],
            'kategoria'    => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => ''],
            'aktywne'      => ['type' => 'BOOLEAN', 'default' => true],
            'pierwsze_at'  => ['type' => 'TIMESTAMPTZ'],
            'ostatnie_at'  => ['type' => 'TIMESTAMPTZ'],
            'zdjete_at'    => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'incydent_id'  => ['type' => 'BIGINT', 'null' => true],
            'payload_json' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['ioc', 'nasz_prefiks']);
        $this->forge->addKey('aktywne');
        $this->forge->createTable('mcs_threatfox_trafienia', true);

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'auto_increment' => true],
            'at'           => ['type' => 'TIMESTAMPTZ'],
            'zdarzenie'    => ['type' => 'VARCHAR', 'constraint' => 16],
            'ioc'          => ['type' => 'TEXT', 'null' => true],
            'nasz_prefiks' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'opis'         => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('at');
        $this->forge->createTable('mcs_threatfox_historia', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_threatfox_historia', true);
        $this->forge->dropTable('mcs_threatfox_trafienia', true);
        $this->forge->dropTable('mcs_threatfox_ioc', true);
        $this->forge->dropTable('mcs_threatfox_stan', true);
    }
}
