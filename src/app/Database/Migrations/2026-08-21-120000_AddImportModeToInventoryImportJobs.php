<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddImportModeToInventoryImportJobs extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('inventory_import_jobs')) {
            return;
        }

        if ($this->db->fieldExists('import_mode', 'inventory_import_jobs')) {
            return;
        }

        $this->forge->addColumn('inventory_import_jobs', [
            'import_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'reconcile',
                'after'      => 'sheet_name',
            ],
        ]);
    }

    public function down()
    {
        if (! $this->db->tableExists('inventory_import_jobs')) {
            return;
        }

        if ($this->db->fieldExists('import_mode', 'inventory_import_jobs')) {
            $this->forge->dropColumn('inventory_import_jobs', 'import_mode');
        }
    }
}
