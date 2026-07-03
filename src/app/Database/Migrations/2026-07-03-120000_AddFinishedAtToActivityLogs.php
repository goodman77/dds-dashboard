<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFinishedAtToActivityLogs extends Migration
{
    public function up()
    {
        if ($this->db->fieldExists('updated_at', 'activity_logs')) {
            return;
        }

        $this->forge->addColumn('activity_logs', [
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'created_at',
            ],
        ]);

        $this->forge->addKey('updated_at');

        $this->db->table('activity_logs')
            ->set('updated_at', 'created_at', false)
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->update();
    }

    public function down()
    {
        if ($this->db->fieldExists('updated_at', 'activity_logs')) {
            $this->forge->dropColumn('activity_logs', 'updated_at');
        }
    }
}
