<!-- DIAGNOSTICS -->
<div id="diagnostics" class="tab-pane fade in{% if section == 'diagnostics' %} active{% endif %}">

    {# ── Toolbar ─────────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;
                        display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">

                {# Instance selector #}
                <div class="input-group input-group-sm" style="min-width: 200px; width: auto;">
                    <span class="input-group-addon">
                        <i class="fa fa-server fa-fw"></i>
                    </span>
                    <select id="diagInstanceSelect" class="form-control">
                    </select>
                </div>

                <div style="width: 1px; height: 22px; background: #ddd;"></div>

                <button id="btnDiagRefresh" class="btn btn-sm btn-default">
                    <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                </button>
                <button id="btnCopyDebug" class="btn btn-sm btn-default">
                    <i class="fa fa-clipboard fa-fw"></i> {{ lang._('Copy Debug Info') }}
                </button>
                <span id="copyDebugResult" style="font-size: 12px;"></span>
            </div>
        </section>
    </div>

    {# ── Stats table ─────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 12px 15px 15px;">
                <table class="table table-condensed table-striped" style="max-width: 600px;">
                    <tbody>
                        <tr>
                            <th style="width: 220px;">{{ lang._('TUN Interface') }}</th>
                            <td id="diag_tun_iface">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('TUN Status') }}</th>
                            <td id="diag_tun_status">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('TUN IP') }}</th>
                            <td id="diag_tun_ip">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('MTU') }}</th>
                            <td id="diag_mtu">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Bytes In') }}</th>
                            <td id="diag_bytes_in">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Bytes Out') }}</th>
                            <td id="diag_bytes_out">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Packets In') }}</th>
                            <td id="diag_pkts_in">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Packets Out') }}</th>
                            <td id="diag_pkts_out">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('xray-core Uptime') }}</th>
                            <td id="diag_xray_uptime">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Proxy Connectivity') }}</th>
                            <td id="diag_connectivity">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Last Health Check') }}</th>
                            <td id="diag_health_checked">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Health Latency') }}</th>
                            <td id="diag_health_latency">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Consecutive Failures') }}</th>
                            <td id="diag_health_failures">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('OPNsense Assignment') }}</th>
                            <td id="diag_assignment">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Interface IP Configuration') }}</th>
                            <td id="diag_ip_config">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Dynamic Gateway Policy') }}</th>
                            <td id="diag_dynamic_gateway">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Gateway Health Sync') }}</th>
                            <td id="diag_gateway_sync">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Native Gateway') }}</th>
                            <td id="diag_native_gateway">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Native Gateway Status') }}</th>
                            <td id="diag_native_gateway_status">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('PF route-to Rule') }}</th>
                            <td id="diag_pf_route">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ lang._('Remote Endpoint') }}</th>
                            <td id="diag_endpoint">&mdash;</td>
                        </tr>
                    </tbody>
                </table>
                <p id="diagError" class="text-danger" style="display: none;"></p>
            </div>
        </section>
    </div>

</div>
