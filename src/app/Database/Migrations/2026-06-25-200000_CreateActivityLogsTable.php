<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateActivityLogsTable extends Migration
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
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'message' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
            ],
            'details' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'reference_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('action');
        $this->forge->addKey('status');
        $this->forge->addKey('created_at');
        $this->forge->createTable('activity_logs');
    }

    public function down()
    {
        $this->forge->dropTable('activity_logs', true);
    }
}
