<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SwitchActivityLogsToUpdatedAt extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('updated_at', 'activity_logs')) {
            $this->forge->addColumn('activity_logs', [
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'after' => 'created_at',
                ],
            ]);

            $this->forge->addKey('updated_at');
        }

        if ($this->db->fieldExists('finished_at', 'activity_logs')) {
            $this->db->query(
                'UPDATE activity_logs SET updated_at = finished_at WHERE updated_at IS NULL AND finished_at IS NOT NULL',
            );

            $this->forge->dropColumn('activity_logs', 'finished_at');
        }

        $this->db->table('activity_logs')
            ->set('updated_at', 'created_at', false)
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->where('updated_at IS NULL', null, false)
            ->update();
    }

    public function down()
    {
        if (! $this->db->fieldExists('finished_at', 'activity_logs')) {
            $this->forge->addColumn('activity_logs', [
                'finished_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'after' => 'created_at',
                ],
            ]);
        }

        if ($this->db->fieldExists('updated_at', 'activity_logs')) {
            $this->db->query(
                'UPDATE activity_logs SET finished_at = updated_at WHERE finished_at IS NULL AND updated_at IS NOT NULL',
            );
        }
    }
}
