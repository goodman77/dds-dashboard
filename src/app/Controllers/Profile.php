<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class Profile extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = service('userProfile')->getProfile($userId);

        if ($profile === null) {
            return redirect()->to('/login');
        }

        return view('profile/index', [
            'title'   => 'Profile',
            'profile' => $profile,
        ]);
    }

    public function update(): RedirectResponse
    {
        $result = service('userProfile')->updateProfile(
            (int) auth()->id(),
            $this->request->getPost(),
        );

        $redirect = redirect()->to('/profile')->with(
            $result['ok'] ? 'success' : 'error',
            $result['message'],
        );

        if (! $result['ok']) {
            $redirect = $redirect->with('profile_errors', $result['errors'] ?? []);
        }

        return $redirect;
    }

    public function updatePassword(): RedirectResponse
    {
        $result = service('userProfile')->changePassword(
            (int) auth()->id(),
            $this->request->getPost(),
        );

        $redirect = redirect()->to('/profile')->with(
            $result['ok'] ? 'success' : 'error',
            $result['message'],
        );

        if (! $result['ok']) {
            $redirect = $redirect->with('password_errors', $result['errors'] ?? []);
        }

        return $redirect;
    }
}
