<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddNet32CheckToBinLocations extends Migration
{
    public function up()
    {
        $this->forge->addColumn('bin_locations', [
            'sku_net32_exists' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'after'      => 'sku_aliases',
            ],
            'sku_aliases_net32' => [
                'type' => 'JSON',
                'null' => true,
                'after' => 'sku_net32_exists',
            ],
            'net32_has_missing' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'after'      => 'sku_aliases_net32',
            ],
            'net32_checked_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'net32_has_missing',
            ],
        ]);

        $this->forge->addKey('net32_has_missing');
        $this->forge->addKey('net32_checked_at');
    }

    public function down()
    {
        $this->forge->dropColumn('bin_locations', [
            'sku_net32_exists',
            'sku_aliases_net32',
            'net32_has_missing',
            'net32_checked_at',
        ]);
    }
}
