<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUserNames extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'first_name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ],
            'last_name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', ['first_name', 'last_name']);
    }
}
