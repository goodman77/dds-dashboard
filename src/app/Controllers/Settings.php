<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class Settings extends BaseController
{
    public function index()
    {
        $settings = service('appSettings');

        return view('settings/index', [
            'title'             => 'Settings',
            'lowStockThreshold' => $settings->lowStockThreshold(),
        ]);
    }

    public function update(): RedirectResponse
    {
        $result = service('appSettings')->updateLowStockThreshold($this->request->getPost());

        return redirect()->to('/settings')->with(
            $result['ok'] ? 'success' : 'error',
            $result['message'],
        );
    }
}
