<?php

namespace McsPluginy\threatfox;

use App\Models\PluginModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Dane karty /dashboard/threatfox (tylko odczyt DB).
 */
final class Widok
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{
     *   stan: array<string, mixed>,
     *   trafienia: list<array<string, mixed>>,
     *   ioc: list<array<string, mixed>>,
     *   historia: list<array<string, mixed>>,
     *   health_ok: ?bool,
     *   health_msg: string,
     *   health_at: string
     * }
     */
    public function dane(int $limitHistorii = 30): array
    {
        $stan = $this->db->table('mcs_threatfox_stan')->where('id', 1)->get()->getRowArray() ?? [];
        $traf = $this->db->table('mcs_threatfox_trafienia')
            ->orderBy('aktywne', 'DESC')->orderBy('ostatnie_at', 'DESC')->limit(200)->get()->getResultArray();
        $ioc = $this->db->table('mcs_threatfox_ioc')
            ->orderBy('aktywne', 'DESC')->orderBy('ostatni_import_at', 'DESC')->limit(100)->get()->getResultArray();
        $hist = $this->db->table('mcs_threatfox_historia')
            ->orderBy('id', 'DESC')->limit($limitHistorii)->get()->getResultArray();

        $plugin = model(PluginModel::class)->find(Serwis::ZRODLO);
        $plugin = is_array($plugin) ? $plugin : [];

        $ok = $plugin['health_ok'] ?? null;

        return [
            'stan'       => $stan,
            'trafienia'  => $traf,
            'ioc'        => $ioc,
            'historia'   => $hist,
            'health_ok'  => $ok === null ? null : PluginModel::jakoBool($ok),
            'health_msg' => (string) ($plugin['health_msg'] ?? ''),
            'health_at'  => (string) ($plugin['health_at'] ?? ''),
        ];
    }
}
