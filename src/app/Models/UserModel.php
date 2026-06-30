<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\User;
use CodeIgniter\Shield\Models\UserModel as ShieldUserModel;

class UserModel extends ShieldUserModel
{
    protected $returnType = User::class;

    protected $allowedFields = [
        'email',
        'username',
        'password_hash',
        'first_name',
        'last_name',
        'active',
    ];
}
