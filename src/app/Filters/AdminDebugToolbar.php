<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shows the CodeIgniter Debug Toolbar only for admin users.
 */
class AdminDebugToolbar implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (! $this->shouldShowToolbar()) {
            return null;
        }

        return (new DebugToolbar())->after($request, $response, $arguments);
    }

    private function shouldShowToolbar(): bool
    {
        if (! function_exists('auth') || ! auth()->loggedIn()) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && $user->inGroup('admin');
    }
}
