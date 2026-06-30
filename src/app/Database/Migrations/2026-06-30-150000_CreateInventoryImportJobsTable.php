<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateInventoryImportJobsTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('inventory_import_jobs')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'activity_log_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['queued', 'running', 'completed', 'failed'],
                'default'    => 'queued',
            ],
            'sheet_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'progress_message' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'result' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'errors' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'started_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'finished_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->createTable('inventory_import_jobs');
    }

    public function down()
    {
        $this->forge->dropTable('inventory_import_jobs', true);
    }
}
