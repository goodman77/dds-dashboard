<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProductTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'mpid' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'vpcode' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'mp_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'quantity' => [
                'type'       => 'INT',
                'default'    => 0,
            ],
            'net32_quantity' => [
                'type' => 'INT',
                'null' => true,
            ],
            'availability' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'product_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => true,
            ],
            'sync_status' => [
                'type'       => 'ENUM',
                'constraint' => ['synced', 'pending', 'failed', 'never'],
                'default'    => 'never',
            ],
            'sync_message' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'last_synced_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'imported_at' => [
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
        $this->forge->addUniqueKey('mpid');
        $this->forge->addUniqueKey('vpcode');
        $this->forge->addKey('name');
        $this->forge->addKey('sync_status');
        $this->forge->createTable('products');

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'product_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'change_amount' => [
                'type' => 'INT',
            ],
            'quantity_before' => [
                'type' => 'INT',
            ],
            'quantity_after' => [
                'type' => 'INT',
            ],
            'reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'synced_to_net32' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('product_id');
        $this->forge->addForeignKey('product_id', 'products', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('inventory_adjustments');

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['success', 'partial', 'failed'],
            ],
            'products_affected' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'details' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('created_at');
        $this->forge->createTable('sync_logs');
    }

    public function down()
    {
        $this->forge->dropTable('inventory_adjustments', true);
        $this->forge->dropTable('sync_logs', true);
        $this->forge->dropTable('products', true);
    }
}
