<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddShipStationLocationMatchToInventory extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('inventory')) {
            return;
        }

        if ($this->db->fieldExists('shipstation_location_matches', 'inventory')) {
            return;
        }

        $this->forge->addColumn('inventory', [
            'shipstation_location_matches' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'after'      => 'shipstation_checked_at',
            ],
        ]);

        $this->forge->addKey('shipstation_location_matches');
    }

    public function down()
    {
        if ($this->db->tableExists('inventory') && $this->db->fieldExists('shipstation_location_matches', 'inventory')) {
            $this->forge->dropColumn('inventory', 'shipstation_location_matches');
        }
    }
}
