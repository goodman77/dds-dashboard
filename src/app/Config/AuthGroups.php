<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\AuthGroups as ShieldAuthGroups;

class AuthGroups extends ShieldAuthGroups
{
    public string $defaultGroup = 'employee';

    /**
     * @var array<string, array<string, string>>
     */
    public array $groups = [
        'admin' => [
            'title'       => 'Admin',
            'description' => 'Full access and can manage employee accounts.',
        ],
        'employee' => [
            'title'       => 'Employee',
            'description' => 'Standard dashboard access.',
        ],
    ];

    public array $permissions = [
        'admin.access'  => 'Can access the admin dashboard',
        'users.create'  => 'Can create employee users',
        'users.manage'  => 'Can view and manage users',
    ];

    public array $matrix = [
        'admin' => [
            'admin.*',
            'users.*',
        ],
        'employee' => [
            'admin.access',
        ],
    ];
}
