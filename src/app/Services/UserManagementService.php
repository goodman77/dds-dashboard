<?php

declare(strict_types=1);

namespace App\Services;

use App\Entities\User;
use App\Models\UserModel;
use CodeIgniter\Shield\Validation\ValidationRules;

class UserManagementService
{
    public function __construct(
        private readonly UserModel $users,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUsers(): array
    {
        $rows = [];

        foreach ($this->users->orderBy('id', 'ASC')->findAll() as $user) {
            $groups = $user->getGroups();
            $role   = in_array('admin', $groups, true) ? 'admin' : 'employee';

            $rows[] = [
                'id'         => (int) $user->id,
                'username'   => (string) ($user->username ?? ''),
                'email'      => $user->getEmail() ?? '',
                'first_name' => (string) ($user->first_name ?? ''),
                'last_name'  => (string) ($user->last_name ?? ''),
                'role'       => $role,
                'role_label' => $role === 'admin' ? 'Admin' : 'Employee',
                'active'     => (bool) $user->active,
                'created_at' => (string) ($user->created_at ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, message: string, errors?: array<string, string>}
     */
    public function createEmployee(array $input): array
    {
        $rules = $this->validationRules();
        $rules['password_confirm'] = [
            'label' => 'Confirm password',
            'rules' => 'required|matches[password]',
        ];

        $validation = service('validation');
        $validation->setRules($rules);

        if (! $validation->run($input)) {
            return [
                'ok'      => false,
                'message' => 'Could not create user.',
                'errors'  => $validation->getErrors(),
            ];
        }

        $username = trim((string) ($input['username'] ?? ''));

        $user = new User([
            'username'   => $username,
            'email'      => trim((string) ($input['email'] ?? '')),
            'password'   => (string) ($input['password'] ?? ''),
            'first_name' => trim((string) ($input['first_name'] ?? '')) ?: null,
            'last_name'  => trim((string) ($input['last_name'] ?? '')) ?: null,
            'active'     => 1,
        ]);

        if (! $this->users->save($user)) {
            return [
                'ok'      => false,
                'message' => 'Could not save user.',
                'errors'  => $this->users->errors(),
            ];
        }

        $saved = $this->users->findById((int) $this->users->getInsertID());

        if ($saved === null) {
            return [
                'ok'      => false,
                'message' => 'User was created but could not be loaded.',
            ];
        }

        $saved->addGroup('employee');

        return [
            'ok'      => true,
            'message' => sprintf('Employee account created for %s.', $saved->username ?? $saved->getEmail()),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUser(int $id): ?array
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return null;
        }

        $groups = $user->getGroups();
        $role   = in_array('admin', $groups, true) ? 'admin' : 'employee';

        return [
            'id'         => (int) $user->id,
            'username'   => (string) ($user->username ?? ''),
            'email'      => $user->getEmail() ?? '',
            'first_name' => (string) ($user->first_name ?? ''),
            'last_name'  => (string) ($user->last_name ?? ''),
            'role'       => $role,
            'role_label' => $role === 'admin' ? 'Admin' : 'Employee',
            'active'     => (bool) $user->active,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, message: string, errors?: array<string, string>}
     */
    public function updateUser(int $id, array $input, int $actingUserId): array
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return [
                'ok'      => false,
                'message' => 'User not found.',
            ];
        }

        $validation = service('validation');
        $validation->setRules([
            'first_name' => [
                'label' => 'First name',
                'rules' => 'permit_empty|max_length[100]',
            ],
            'last_name' => [
                'label' => 'Last name',
                'rules' => 'permit_empty|max_length[100]',
            ],
            'active' => [
                'label' => 'Status',
                'rules' => 'required|in_list[0,1]',
            ],
        ]);

        if (! $validation->run($input)) {
            return [
                'ok'      => false,
                'message' => 'Could not update user.',
                'errors'  => $validation->getErrors(),
            ];
        }

        $active = (int) ($input['active'] ?? 0) === 1;

        if ($id === $actingUserId && ! $active) {
            return [
                'ok'      => false,
                'message' => 'You cannot deactivate your own account.',
            ];
        }

        if (! $active && $user->inGroup('admin') && $this->countActiveAdmins() <= 1 && (bool) $user->active) {
            return [
                'ok'      => false,
                'message' => 'At least one active admin account is required.',
            ];
        }

        $user->first_name = trim((string) ($input['first_name'] ?? '')) ?: null;
        $user->last_name  = trim((string) ($input['last_name'] ?? '')) ?: null;
        $user->active     = $active ? 1 : 0;

        if (! $this->users->save($user)) {
            return [
                'ok'      => false,
                'message' => 'Could not save user changes.',
                'errors'  => $this->users->errors(),
            ];
        }

        return [
            'ok'      => true,
            'message' => sprintf(
                'Updated %s (%s).',
                trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->username ?? 'user'),
                $active ? 'Active' : 'Inactive',
            ),
        ];
    }

    private function countActiveAdmins(): int
    {
        $count = 0;

        foreach ($this->users->findAll() as $user) {
            if ($user->inGroup('admin') && (bool) $user->active) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    private function validationRules(): array
    {
        $authRules = new ValidationRules();

        return array_merge(
            $authRules->getRegistrationRules(),
            [
                'first_name' => [
                    'label' => 'First name',
                    'rules' => 'permit_empty|max_length[100]',
                ],
                'last_name' => [
                    'label' => 'Last name',
                    'rules' => 'permit_empty|max_length[100]',
                ],
            ],
        );
    }
}
