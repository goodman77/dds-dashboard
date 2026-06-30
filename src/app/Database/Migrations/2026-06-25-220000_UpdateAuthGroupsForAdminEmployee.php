<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UpdateAuthGroupsForAdminEmployee extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('auth_groups_users')) {
            return;
        }

        $this->db->table('auth_groups_users')
            ->whereNotIn('group', ['admin', 'employee'])
            ->update(['group' => 'employee']);

        $firstUser = $this->db->table('users')
            ->select('id')
            ->orderBy('id', 'ASC')
            ->get(1)
            ->getRowArray();

        if ($firstUser === null) {
            return;
        }

        $userId = (int) $firstUser['id'];

        $this->db->table('auth_groups_users')->where('user_id', $userId)->delete();
        $this->db->table('auth_groups_users')->insert([
            'user_id'    => $userId,
            'group'      => 'admin',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function down()
    {
        // Groups are defined in config; no schema rollback needed.
    }
}
