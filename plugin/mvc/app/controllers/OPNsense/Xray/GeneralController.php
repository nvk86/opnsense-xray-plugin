<?php

namespace OPNsense\Xray;

class GeneralController extends PageControllerBase
{
    public function indexAction()
    {
        $this->prepareView('general');
    }
}
