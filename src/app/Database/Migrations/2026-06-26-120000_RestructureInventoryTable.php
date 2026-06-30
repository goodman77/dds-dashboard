<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RestructureInventoryTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('bin_locations') && ! $this->db->tableExists('inventory')) {
            $this->forge->renameTable('bin_locations', 'inventory');
        }

        if (! $this->db->tableExists('inventory')) {
            $this->createInventoryTable();

            return;
        }

        $this->addInventoryProductFields();
        $this->dropLegacyAliasColumns();
        $this->rebuildInventoryIndexes();
    }

    public function down()
    {
        if (! $this->db->tableExists('inventory')) {
            return;
        }

        if ($this->db->fieldExists('name', 'inventory')) {
            $this->forge->dropColumn('inventory', ['name', 'description', 'quantity', 'is_main_sku']);
        }

        if (! $this->db->tableExists('bin_locations')) {
            $this->forge->renameTable('inventory', 'bin_locations');
        }
    }

    private function createInventoryTable(): void
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
            ],
            'is_main_sku' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'quantity' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'sku_net32_exists' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
            ],
            'net32_checked_at' => [
                'type' => 'DATETIME',
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
        $this->forge->addUniqueKey(['sheet_name', 'rack', 'bin', 'sku']);
        $this->forge->addKey('sku');
        $this->forge->addKey('rack');
        $this->forge->addKey('bin');
        $this->forge->addKey('net32_checked_at');
        $this->forge->createTable('inventory');
    }

    private function addInventoryProductFields(): void
    {
        $fields = [];

        if (! $this->db->fieldExists('is_main_sku', 'inventory')) {
            $fields['is_main_sku'] = [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'after'      => 'sku',
            ];
        }

        if (! $this->db->fieldExists('name', 'inventory')) {
            $fields['name'] = [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'is_main_sku',
            ];
        }

        if (! $this->db->fieldExists('description', 'inventory')) {
            $fields['description'] = [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'name',
            ];
        }

        if (! $this->db->fieldExists('quantity', 'inventory')) {
            $fields['quantity'] = [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
                'after'    => 'description',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('inventory', $fields);
        }

        $this->db->query('UPDATE inventory SET is_main_sku = 1 WHERE is_main_sku IS NULL');
    }

    private function dropLegacyAliasColumns(): void
    {
        $drop = [];

        foreach (['sku_aliases', 'sku_aliases_net32', 'net32_has_missing'] as $column) {
            if ($this->db->fieldExists($column, 'inventory')) {
                $drop[] = $column;
            }
        }

        if ($drop !== []) {
            $this->forge->dropColumn('inventory', $drop);
        }
    }

    private function rebuildInventoryIndexes(): void
    {
        $indexes = $this->db->getIndexData('inventory');
        $hasSkuUnique = false;

        foreach ($indexes as $index) {
            if ($index->type !== 'UNIQUE') {
                continue;
            }

            $fields = array_values($index->fields ?? []);

            if ($fields === ['sheet_name', 'rack', 'bin', 'sku']) {
                $hasSkuUnique = true;
            }
        }

        foreach ($indexes as $indexName => $index) {
            if ($index->type !== 'UNIQUE') {
                continue;
            }

            $fields = array_values($index->fields ?? []);

            if ($fields === ['sheet_name', 'rack', 'bin'] && ! $hasSkuUnique) {
                $this->forge->dropKey('inventory', (string) $indexName, false);
            }
        }

        if (! $hasSkuUnique) {
            $this->forge->addUniqueKey(['sheet_name', 'rack', 'bin', 'sku']);
        }
    }
}
