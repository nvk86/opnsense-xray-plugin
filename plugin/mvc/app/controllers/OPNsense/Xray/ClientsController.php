<?php

namespace OPNsense\Xray;

class ClientsController extends PageControllerBase
{
    public function indexAction()
    {
        $this->prepareView('clients');
    }
}
