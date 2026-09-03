<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddShipStationToInventory extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('inventory')) {
            return;
        }

        $fields = [];

        if (! $this->db->fieldExists('shipstation_location', 'inventory')) {
            $fields['shipstation_location'] = [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'net32_checked_at',
            ];
        }

        if (! $this->db->fieldExists('shipstation_warehouse', 'inventory')) {
            $fields['shipstation_warehouse'] = [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'shipstation_location',
            ];
        }

        if (! $this->db->fieldExists('shipstation_on_hand', 'inventory')) {
            $fields['shipstation_on_hand'] = [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'shipstation_warehouse',
            ];
        }

        if (! $this->db->fieldExists('shipstation_exists', 'inventory')) {
            $fields['shipstation_exists'] = [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'after'      => 'shipstation_on_hand',
            ];
        }

        if (! $this->db->fieldExists('shipstation_checked_at', 'inventory')) {
            $fields['shipstation_checked_at'] = [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'shipstation_exists',
            ];
        }

        if ($fields === []) {
            return;
        }

        $this->forge->addColumn('inventory', $fields);
        $this->forge->addKey('shipstation_exists');
        $this->forge->addKey('shipstation_checked_at');
    }

    public function down()
    {
        if (! $this->db->tableExists('inventory')) {
            return;
        }

        $drop = [];

        foreach ([
            'shipstation_location',
            'shipstation_warehouse',
            'shipstation_on_hand',
            'shipstation_exists',
            'shipstation_checked_at',
        ] as $column) {
            if ($this->db->fieldExists($column, 'inventory')) {
                $drop[] = $column;
            }
        }

        if ($drop !== []) {
            $this->forge->dropColumn('inventory', $drop);
        }
    }
}
