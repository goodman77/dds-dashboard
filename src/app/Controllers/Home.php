<?php

namespace App\Controllers;

use App\Libraries\Net32\Exceptions\Net32ApiException;
use CodeIgniter\HTTP\ResponseInterface;

class Home extends BaseController
{
    public function index(): ResponseInterface
    {
        try {
            $data = service('net32')->products()->getOffers([
                'vpCode'     => 'VDPP0425',
            ]);

            return $this->response->setJSON($data);
        } catch (Net32ApiException $exception) {
            return $this->response
                ->setStatusCode($exception->getStatusCode() ?: 502)
                ->setJSON([
                    'error'   => $exception->getMessage(),
                    'details' => $exception->getResponseBody(),
                ]);
        }
    }
}
