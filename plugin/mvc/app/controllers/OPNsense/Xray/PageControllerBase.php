<?php

namespace OPNsense\Xray;

/**
 * Shared page preparation for the Xray client-only UI.
 *
 * OPNsense menu entries are General, Clients and Diagnostics. The Diagnostics
 * page contains two tabs: Diagnostics and Log. Xray has no server-management
 * page because this plugin is an outbound VLESS/REALITY client gateway.
 */
abstract class PageControllerBase extends \OPNsense\Base\IndexController
{
    protected function prepareView(string $section): void
    {
        $this->view->section      = $section;
        $this->view->generalForm  = $this->getForm('general');
        $this->view->instanceForm = $this->getForm('instance');
        $this->view->pick('OPNsense/Xray/general');
    }
}
