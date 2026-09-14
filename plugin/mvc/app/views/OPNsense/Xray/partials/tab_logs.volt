<!-- LOGS -->
<div id="logs" class="tab-pane fade">
    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;
                        display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
                <ul class="nav nav-pills nav-sm" id="logSubTabs" style="margin: 0;">
                    <li class="active">
                        <a data-toggle="tab" href="#logStartup">
                            <i class="fa fa-terminal fa-fw"></i> {{ lang._('Service Log') }}
                        </a>
                    </li>
                    <li>
                        <a data-toggle="tab" href="#logCore">
                            <i class="fa fa-file-text-o fa-fw"></i> {{ lang._('Core Log') }}
                        </a>
                    </li>
                    <li>
                        <a data-toggle="tab" href="#logWatchdog">
                            <i class="fa fa-heartbeat fa-fw"></i> {{ lang._('Watchdog Log') }}
                        </a>
                    </li>
                </ul>
            </div>
        </section>
    </div>

    <div class="row">
        <section class="col-xs-12">
            <div class="tab-content" style="padding: 0 15px 15px;">
                <div id="logStartup" class="tab-pane fade in active" style="padding-top: 10px;">
                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <button id="logStartupRefreshBtn" class="btn btn-sm btn-default">
                            <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                        </button>
                        <span class="text-muted" style="font-size: 12px;">
                            {{ lang._('/var/log/xray-service.log — last 200 lines') }}
                        </span>
                    </div>
                    <pre id="logStartupContent"
                         style="min-height: 300px; max-height: 550px; overflow-y: auto;
                                background: #1e1e1e; color: #d4d4d4; font-family: monospace;
                                font-size: 12px; padding: 12px; border-radius: 4px;
                                border: 1px solid #444;">{{ lang._('Loading...') }}</pre>
                </div>

                <div id="logCore" class="tab-pane fade in" style="padding-top: 10px;">
                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <button id="logCoreRefreshBtn" class="btn btn-sm btn-default">
                            <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                        </button>
                        <div class="input-group input-group-sm" style="min-width: 200px; width: auto;">
                            <span class="input-group-addon"><i class="fa fa-server fa-fw"></i></span>
                            <select id="logInstanceSelect" class="form-control"></select>
                        </div>
                        <span class="text-muted" style="font-size: 12px;">
                            {{ lang._('/var/log/xray-{uuid}.log — last 200 lines') }}
                        </span>
                    </div>
                    <pre id="logCoreContent"
                         style="min-height: 300px; max-height: 550px; overflow-y: auto;
                                background: #1e1e1e; color: #d4d4d4; font-family: monospace;
                                font-size: 12px; padding: 12px; border-radius: 4px;
                                border: 1px solid #444;">{{ lang._('Select a client.') }}</pre>
                </div>

                <div id="logWatchdog" class="tab-pane fade in" style="padding-top: 10px;">
                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <button id="logWatchdogRefreshBtn" class="btn btn-sm btn-default">
                            <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                        </button>
                        <span class="text-muted" style="font-size: 12px;">
                            {{ lang._('/var/log/xray-watchdog.log — last 200 lines') }}
                        </span>
                    </div>
                    <pre id="logWatchdogContent"
                         style="min-height: 300px; max-height: 550px; overflow-y: auto;
                                background: #1e1e1e; color: #d4d4d4; font-family: monospace;
                                font-size: 12px; padding: 12px; border-radius: 4px;
                                border: 1px solid #444;">{{ lang._('Loading...') }}</pre>
                </div>
            </div>
        </section>
    </div>
</div>
