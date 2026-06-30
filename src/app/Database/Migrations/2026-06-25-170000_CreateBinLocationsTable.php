<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBinLocationsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'sheet_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'rack' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'bin' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'sku' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'sku_aliases' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'synced_at' => [
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
        $this->forge->addUniqueKey(['sheet_name', 'rack', 'bin']);
        $this->forge->addKey('sku');
        $this->forge->addKey('rack');
        $this->forge->addKey('bin');
        $this->forge->createTable('bin_locations');
    }

    public function down()
    {
        $this->forge->dropTable('bin_locations', true);
    }
}
