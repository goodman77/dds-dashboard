<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddNet32StoreQuantitiesToInventory extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('inventory')) {
            return;
        }

        $fields = [];

        if (! $this->db->fieldExists('qty_lv', 'inventory')) {
            $fields['qty_lv'] = [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'quantity',
            ];
        }

        if (! $this->db->fieldExists('qty_brook', 'inventory')) {
            $fields['qty_brook'] = [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'qty_lv',
            ];
        }

        if (! $this->db->fieldExists('qty_coral', 'inventory')) {
            $fields['qty_coral'] = [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'qty_brook',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('inventory', $fields);
        }

        $this->db->query('UPDATE inventory SET qty_lv = quantity WHERE qty_lv IS NULL');
    }

    public function down()
    {
        if (! $this->db->tableExists('inventory')) {
            return;
        }

        $drop = [];

        foreach (['qty_lv', 'qty_brook', 'qty_coral'] as $column) {
            if ($this->db->fieldExists($column, 'inventory')) {
                $drop[] = $column;
            }
        }

        if ($drop !== []) {
            $this->forge->dropColumn('inventory', $drop);
        }
    }
}
