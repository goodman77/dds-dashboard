<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateImportJobsTable extends Migration
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
            'imported' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'updated' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'total_available' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'processed' => [
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
        $this->forge->createTable('import_jobs');
    }

    public function down()
    {
        $this->forge->dropTable('import_jobs', true);
    }
}
