<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBinNet32JobsTable extends Migration
{
    public function up()
    {
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
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['queued', 'running', 'completed', 'failed'],
                'default'    => 'queued',
            ],
            'scope' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'all',
            ],
            'filters' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'total_locations' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'processed' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'sku_checks' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'missing_count' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'progress_message' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
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
        $this->forge->createTable('bin_net32_jobs');
    }

    public function down()
    {
        $this->forge->dropTable('bin_net32_jobs', true);
    }
}
