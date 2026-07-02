<?php

namespace App\Controllers;

class SSL extends BaseController
{
    public function fileauth()
    {
        return $this->response
            ->setHeader('Content-Type', 'text/plain')
            ->setBody('20260702141051k7wamyww41gxaofy29uuhsnb5ol4lns');
    }
}