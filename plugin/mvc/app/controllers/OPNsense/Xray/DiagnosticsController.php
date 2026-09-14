<?php

namespace OPNsense\Xray;

class DiagnosticsController extends PageControllerBase
{
    public function indexAction()
    {
        $this->prepareView('diagnostics');
    }
}
