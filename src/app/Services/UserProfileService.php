<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserModel;
use CodeIgniter\Shield\Validation\ValidationRules;

class UserProfileService
{
    public function __construct(
        private readonly UserModel $users,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getProfile(int $userId): ?array
    {
        $user = $this->users->findById($userId);

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
            'created_at' => (string) ($user->created_at ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, message: string, errors?: array<string, string>}
     */
    public function updateProfile(int $userId, array $input): array
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            return ['ok' => false, 'message' => 'User not found.'];
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
        ]);

        if (! $validation->run($input)) {
            return [
                'ok'      => false,
                'message' => 'Could not update profile.',
                'errors'  => $validation->getErrors(),
            ];
        }

        $user->first_name = trim((string) ($input['first_name'] ?? '')) ?: null;
        $user->last_name  = trim((string) ($input['last_name'] ?? '')) ?: null;

        if (! $this->users->save($user)) {
            return [
                'ok'      => false,
                'message' => 'Could not save profile changes.',
                'errors'  => $this->users->errors(),
            ];
        }

        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return [
            'ok'      => true,
            'message' => $name !== ''
                ? sprintf('Profile updated for %s.', $name)
                : 'Profile updated.',
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, message: string, errors?: array<string, string>}
     */
    public function changePassword(int $userId, array $input): array
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            return ['ok' => false, 'message' => 'User not found.'];
        }

        $authRules = new ValidationRules();
        $passwordRules = $authRules->getPasswordRules();
        $passwordRules['rules'][] = 'strong_password[]';

        $validation = service('validation');
        $validation->setRules([
            'current_password' => [
                'label' => 'Current password',
                'rules' => 'required',
            ],
            'password' => $passwordRules,
            'password_confirm' => [
                'label' => 'Confirm new password',
                'rules' => 'required|matches[password]',
            ],
        ]);

        if (! $validation->run($input)) {
            return [
                'ok'      => false,
                'message' => 'Could not change password.',
                'errors'  => $validation->getErrors(),
            ];
        }

        $currentPassword = (string) ($input['current_password'] ?? '');
        $passwords         = service('passwords');
        $hash              = $user->getPasswordHash();

        if ($hash === null || ! $passwords->verify($currentPassword, $hash)) {
            return [
                'ok'      => false,
                'message' => 'Current password is incorrect.',
                'errors'  => ['current_password' => 'Current password is incorrect.'],
            ];
        }

        $user->setPassword((string) ($input['password'] ?? ''));

        if (! $this->users->save($user)) {
            return [
                'ok'      => false,
                'message' => 'Could not save the new password.',
                'errors'  => $this->users->errors(),
            ];
        }

        return [
            'ok'      => true,
            'message' => 'Password changed successfully.',
        ];
    }
}
