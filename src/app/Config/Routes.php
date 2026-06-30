<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', function () {
    return redirect()->to('/login');
});

service('auth')->routes($routes);
$routes->get('test', 'Home::index');

$routes->group('', ['filter' => 'session'], static function ($routes) {
    $routes->get('dashboard', 'Dashboard::index');
    $routes->get('inventory', 'BinLocations::index');
    $routes->post('inventory', 'BinLocations::store');
    $routes->get('inventory/(:num)', 'BinLocations::show/$1');
    $routes->post('inventory/(:num)', 'BinLocations::update/$1');
    $routes->post('inventory/(:num)/check-qty', 'BinLocations::checkQuantity/$1');
    $routes->post('inventory/sync', 'BinLocations::sync');
    $routes->get('inventory/import-status', 'BinLocations::importStatus');
    $routes->get('logs', 'Logs::index');
    $routes->get('profile', 'Profile::index');
    $routes->post('profile', 'Profile::update');
    $routes->post('profile/password', 'Profile::updatePassword');
    $routes->get('reports', static fn () => redirect()->to('/logs'));
    $routes->get('users', 'Users::index', ['filter' => 'group:admin']);
    $routes->post('users', 'Users::store', ['filter' => 'group:admin']);
    $routes->get('users/(:num)', 'Users::show/$1', ['filter' => 'group:admin']);
    $routes->post('users/(:num)', 'Users::update/$1', ['filter' => 'group:admin']);
    $routes->get('bin-locations', static fn () => redirect()->to('/inventory'));
    $routes->get('bin-locations/(:any)', static fn () => redirect()->to('/inventory'));
    $routes->get('products', static fn () => redirect()->to('/inventory'));
    $routes->get('products/(:any)', static fn () => redirect()->to('/inventory'));
});
