<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddActivityLogIdToInventoryShipStationCheckJobs extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('inventory_shipstation_check_jobs')) {
            return;
        }

        if ($this->db->fieldExists('activity_log_id', 'inventory_shipstation_check_jobs')) {
            return;
        }

        $this->forge->addColumn('inventory_shipstation_check_jobs', [
            'activity_log_id' => [
                'type'       => 'INT',
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'user_id',
            ],
        ]);

        $this->forge->addKey('activity_log_id');
    }

    public function down()
    {
        if (
            $this->db->tableExists('inventory_shipstation_check_jobs')
            && $this->db->fieldExists('activity_log_id', 'inventory_shipstation_check_jobs')
        ) {
            $this->forge->dropColumn('inventory_shipstation_check_jobs', 'activity_log_id');
        }
    }
}
