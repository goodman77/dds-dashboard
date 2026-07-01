<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\UserManagementService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class Users extends BaseController
{
    protected UserManagementService $users;

    public function __construct()
    {
        $this->users = service('userManagement');
    }

    public function index()
    {
        return view('users/index', [
            'title' => 'Users',
            'users' => $this->users->listUsers(),
        ]);
    }

    public function show(int $id): ResponseInterface
    {
        $user = $this->users->getUser($id);

        if ($user === null) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'User not found.',
            ]);
        }

        return $this->response->setJSON([
            'ok'   => true,
            'user' => $user,
        ]);
    }

    public function store(): RedirectResponse
    {
        $result = $this->users->createUser($this->request->getPost());

        if ($result['ok']) {
            return redirect()->to('/users')->with('success', $result['message']);
        }

        return redirect()->back()
            ->withInput()
            ->with('error', $result['message'])
            ->with('errors', $result['errors'] ?? []);
    }

    public function update(int $id): RedirectResponse
    {
        $result = $this->users->updateUser(
            $id,
            $this->request->getPost(),
            (int) auth()->id(),
        );

        if ($result['ok']) {
            return redirect()->to('/users')->with('success', $result['message']);
        }

        return redirect()->back()
            ->withInput()
            ->with('error', $result['message'])
            ->with('edit_user_id', $id)
            ->with('edit_errors', $result['errors'] ?? []);
    }
}
