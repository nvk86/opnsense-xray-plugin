<?php

namespace OPNsense\Xray;

/**
 * Default landing route for /ui/xray/.
 * The menu itself links directly to General, Clients and Diagnostics.
 */
class IndexController extends PageControllerBase
{
    public function indexAction()
    {
        $this->prepareView('clients');
    }
}
