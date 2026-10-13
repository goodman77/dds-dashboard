<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddNet32StoreExistsToInventory extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('inventory')) {
            return;
        }

        $fields = [];

        if (! $this->db->fieldExists('net32_exists_lv', 'inventory')) {
            $fields['net32_exists_lv'] = [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'after'      => 'qty_coral',
            ];
        }

        if (! $this->db->fieldExists('net32_exists_brook', 'inventory')) {
            $fields['net32_exists_brook'] = [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'after'      => 'net32_exists_lv',
            ];
        }

        if (! $this->db->fieldExists('net32_exists_coral', 'inventory')) {
            $fields['net32_exists_coral'] = [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'after'      => 'net32_exists_brook',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('inventory', $fields);
        }

        $this->db->query('UPDATE inventory SET net32_exists_lv = sku_net32_exists WHERE net32_exists_lv IS NULL');
    }

    public function down()
    {
        if (! $this->db->tableExists('inventory')) {
            return;
        }

        $drop = [];

        foreach (['net32_exists_lv', 'net32_exists_brook', 'net32_exists_coral'] as $column) {
            if ($this->db->fieldExists($column, 'inventory')) {
                $drop[] = $column;
            }
        }

        if ($drop !== []) {
            $this->forge->dropColumn('inventory', $drop);
        }
    }
}
