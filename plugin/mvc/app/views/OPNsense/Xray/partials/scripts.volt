<script>
    $(document).ready(function () {
        var currentSection = '{{ section }}';

        // ── Helpers ───────────────────────────────────────────────
        function escAttr(s) {
            return String(s).replace(/[&"<>]/g, function (c) {
                return {'&':'&amp;','"':'&quot;','<':'&lt;','>':'&gt;'}[c];
            });
        }

        function enabledValue(v) {
            return v === true || v === 1 || v === '1' || v === 'Y' || v === 'yes';
        }

        // ── Per-instance status overlay ───────────────────────────
        var instanceStatusCache = {};
        var instanceTestCache   = {};

        function statusBadge(info) {
            if (!info) return '<span class="label label-default" style="font-size:11px;">--</span>';
            var xOk = info.xray_core === 'running';
            var hOk = info.hev === 'running';
            var tOk = info.tun === 'running';
            var link = info.connectivity || 'unknown';
            var linkClass = link === 'online' ? 'label-success'
                          : link === 'offline' ? 'label-danger'
                          : link === 'stale' ? 'label-warning'
                          : 'label-default';
            return '<span class="label ' + (xOk ? 'label-success' : 'label-danger') + '" style="font-size:11px;">' +
                'xray: ' + (xOk ? 'up' : 'down') +
                '</span> ' +
                '<span class="label ' + (hOk ? 'label-success' : 'label-danger') + '" style="font-size:11px;">' +
                'hev: ' + (hOk ? 'up' : 'down') +
                '</span> ' +
                '<span class="label ' + (tOk ? 'label-success' : 'label-danger') + '" style="font-size:11px;">' +
                'tun: ' + (tOk ? 'up' : 'down') +
                '</span> ' +
                '<span class="label ' + linkClass + '" style="font-size:11px;" title="' + escAttr(info.health_message || '') + '">' +
                'link: ' + link + '</span>';
        }

        function applyStatusToGrid() {
            $('#grid-instances .xray-status-cell').each(function () {
                var uuid = $(this).data('uuid');
                $(this).html(statusBadge(instanceStatusCache[uuid]));
            });
        }

        function testResultBadge(info) {
            if (!info) return '<span style="font-size:11px;color:#999;">--</span>';
            var ok = info.result === 'ok' || info.online === true;
            var text = ok ? 'online' : 'offline';
            if (ok && info.latency_ms != null) text += ' · ' + info.latency_ms + ' ms';
            return '<span class="label ' + (ok ? 'label-success' : 'label-danger') + '" style="font-size:11px;" title="'
                + escAttr(info.message || '') + '">' + escAttr(text) + '</span>';
        }

        function applyTestResultToGrid() {
            $('#grid-instances .xray-test-cell').each(function () {
                var uuid = $(this).data('uuid');
                $(this).html(testResultBadge(instanceTestCache[uuid]));
            });
        }

        function applyCommandStateToGrid() {
            $('#grid-instances .cmd-inst-start').each(function () {
                var uuid = $(this).data('row-id');
                var info = instanceStatusCache[uuid];
                if (!info) return;

                var running = info.xray_core === 'running'
                           || info.hev === 'running'
                           || info.tun === 'running';
                var enabled = info.effective_enabled === true;

                $('#grid-instances .cmd-inst-start[data-row-id="' + uuid + '"]')
                    .toggle(enabled && !running);
                $('#grid-instances .cmd-inst-stop[data-row-id="' + uuid + '"]')
                    .toggle(running);
                $('#grid-instances .cmd-inst-restart[data-row-id="' + uuid + '"]')
                    .toggle(enabled && running);
                $('#grid-instances .cmd-inst-test[data-row-id="' + uuid + '"]')
                    .toggle(enabled && running);
            });
        }

        // ── Instances CRUD table (UIBootgrid) ───────────────────────
        // The General page does not render the Clients grid.
        // OPNsense's current UIBootgrid() assumes the selected element exists;
        // calling it on an empty jQuery set aborts this document.ready handler
        // before the General Save button is initialized.  Only initialize the
        // grid on the Clients page where #grid-instances is actually present.
        if ($("#grid-instances").length) {
            $("#grid-instances").UIBootgrid({
            search: '/api/xray/instance/searchItem',
            get:    '/api/xray/instance/getItem/',
            set:    '/api/xray/instance/setItem/',
            add:    '/api/xray/instance/addItem',
            del:    '/api/xray/instance/delItem/',
            toggle: '/api/xray/instance/toggleItem/',
            options: {
                formatters: {
                    instanceStatus: function (column, row) {
                        if (!enabledValue(row.enabled)) {
                            return '<span class="label label-default" style="font-size:11px;">disabled</span>';
                        }
                        return '<span class="xray-status-cell" data-uuid="' + escAttr(row.uuid) + '">' +
                            statusBadge(instanceStatusCache[row.uuid]) + '</span>';
                    },
                    instanceTestResult: function (column, row) {
                        return '<span class="xray-test-cell" data-uuid="' + escAttr(row.uuid) + '">'
                            + testResultBadge(instanceTestCache[row.uuid]) + '</span>';
                    },
                    commands: function (column, row) {
                        var uuid = escAttr(row.uuid);
                        var disabled = enabledValue(row.enabled) ? '' : ' disabled="disabled"';
                        var actionStyle = ' style="display:none;margin-right:2px;padding:1px 4px;"';
                        var commonStyle = ' style="margin-right:2px;padding:1px 4px;"';
                        return '<button type="button" class="btn btn-xs btn-default cmd-inst-start bootgrid-tooltip"' + actionStyle
                             +   disabled + ' data-row-id="' + uuid + '" title="{{ lang._("Start this client") }}">'
                             +   '<span class="fa fa-play fa-fw text-success"></span></button>'
                             + '<button type="button" class="btn btn-xs btn-default cmd-inst-stop bootgrid-tooltip"' + actionStyle
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Stop this client") }}">'
                             +   '<span class="fa fa-stop fa-fw text-danger"></span></button>'
                             + '<button type="button" class="btn btn-xs btn-default cmd-inst-restart bootgrid-tooltip"' + actionStyle
                             +   disabled + ' data-row-id="' + uuid + '" title="{{ lang._("Restart this client") }}">'
                             +   '<span class="fa fa-refresh fa-fw text-warning"></span></button>'
                             + '<button type="button" class="btn btn-xs btn-default cmd-inst-test bootgrid-tooltip"' + actionStyle
                             +   disabled + ' data-row-id="' + uuid + '" title="{{ lang._("Test connectivity") }}">'
                             +   '<span class="fa fa-plug fa-fw"></span></button>'
                             + '<button type="button" class="btn btn-xs btn-default command-edit bootgrid-tooltip"' + commonStyle
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Edit") }}">'
                             +   '<span class="fa fa-pencil fa-fw"></span></button>'
                             + '<button type="button" class="btn btn-xs btn-default command-delete bootgrid-tooltip"' + commonStyle
                             +   ' data-row-id="' + uuid + '" title="{{ lang._("Delete") }}">'
                             +   '<span class="fa fa-trash-o fa-fw"></span></button>';
                    }
                }
            }
            });

            // After grid loads/reloads data, fetch and overlay status
            $('#grid-instances').on('loaded.rs.jquery.bootgrid', function () {
                refreshInstancesStatus();
                populateInstanceSelects();
            });
        }

        // Per-instance start / stop / test (row button handlers)
        $(document).on('click', '#grid-instances .cmd-inst-start', function () {
            instanceServiceAction('start', $(this).data('row-id'));
        });
        $(document).on('click', '#grid-instances .cmd-inst-stop', function () {
            instanceServiceAction('stop', $(this).data('row-id'));
        });
        $(document).on('click', '#grid-instances .cmd-inst-restart', function () {
            instanceServiceAction('restart', $(this).data('row-id'));
        });
        $(document).on('click', '#grid-instances .cmd-inst-test', function () {
            var uuid = $(this).data('row-id');
            var $btn = $(this).prop('disabled', true);
            $.ajax({
                url: '/api/xray/service/testconnect/' + encodeURIComponent(uuid),
                type: 'POST', dataType: 'json',
                success: function (data) {
                    instanceTestCache[uuid] = data;
                    applyTestResultToGrid();
                    setTimeout(refreshInstancesStatus, 150);
                },
                complete: function () { $btn.prop('disabled', false); }
            });
        });

        function instanceServiceAction(action, uuid) {
            var url = '/api/xray/service/' + action + (uuid ? '/' + encodeURIComponent(uuid) : '');
            $.ajax({
                url: url, type: 'POST', dataType: 'json',
                success: function () { setTimeout(refreshInstancesStatus, 1500); }
            });
        }

        // ── General settings form ───────────────────────────────────
        mapDataToFormUI({'frm_general_settings': "/api/xray/general/get"}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        // ── Apply ─────────────────────────────────────────────────
        // On General, persist the form before runtime reconciliation. On Clients
        // the grid has already persisted row edits, so Apply only reconciles runtime.
        if ($("#reconfigureAct").length) {
            $("#reconfigureAct").SimpleActionButton({
                onPreAction: function () {
                    var dfObj = new $.Deferred();
                    if (!$("#frm_general_settings").length) {
                        dfObj.resolve();
                        return dfObj;
                    }
                    saveFormToEndpoint("/api/xray/general/set", 'frm_general_settings', function () {
                        dfObj.resolve();
                    });
                    return dfObj;
                }
            });
        }

        // ── Status badges + per-instance status ───────────────────
        function refreshInstancesStatus() {
            ajaxGet("/api/xray/service/statusall", {}, function (data) {
                if (data.error) return;
                instanceStatusCache = data;

                // Aggregate: any instance running = global running
                var anyXray = false, anyHev = false, anyTun = false;
                var linkTotal = 0, linkOnline = 0, linkOffline = 0, linkUnknown = 0;
                $.each(data, function (uuid, info) {
                    if (info.xray_core === 'running') anyXray = true;
                    if (info.hev === 'running') anyHev = true;
                    if (info.tun === 'running') anyTun = true;
                    if (info.effective_enabled && !info.manual_stopped) {
                        linkTotal++;
                        if (info.connectivity === 'online') linkOnline++;
                        else if (info.connectivity === 'offline') linkOffline++;
                        else linkUnknown++;
                    }
                });
                var xok = anyXray, tok = anyTun;
                $('#badge_xray')
                    .removeClass('label-success label-danger label-default')
                    .addClass(xok ? 'label-success' : 'label-danger')
                    .text('xray-core: ' + (xok ? 'running' : 'stopped'));
                $('#badge_tun')
                    .removeClass('label-success label-danger label-default')
                    .addClass(tok ? 'label-success' : 'label-danger')
                    .text('HEV TUN: ' + (tok ? 'running' : 'stopped'));
                var linkClass = linkTotal === 0 ? 'label-default' : (linkOffline > 0 ? 'label-danger' : (linkUnknown > 0 ? 'label-warning' : 'label-success'));
                var linkText = linkTotal === 0 ? 'VPN link: no enabled clients' : ('VPN link: ' + linkOnline + '/' + linkTotal + ' online');
                $('#badge_link')
                    .removeClass('label-success label-danger label-warning label-default')
                    .addClass(linkClass)
                    .text(linkText);

                // Update per-instance status and runtime actions in grid.
                applyStatusToGrid();
                applyCommandStateToGrid();

                var running = xok || anyHev || tok;
                $('#btnStartAll').prop('disabled', running);
                $('#btnStopAll').prop('disabled', !running);
                $('#btnRestartAll').prop('disabled', !running);
            });
        }
        refreshInstancesStatus();
        setInterval(refreshInstancesStatus, 5000);

        // ── Start / Stop / Restart ──────────────────────────────────
        function serviceAction(action, confirmMsg, callback) {
            if (confirmMsg && !confirm(confirmMsg)) {
                return;
            }
            var $btns = $('#btnStartAll, #btnStopAll, #btnRestartAll').prop('disabled', true);
            var $btn = action === 'start'   ? $('#btnStartAll')
                     : action === 'stop'    ? $('#btnStopAll')
                     :                        $('#btnRestartAll');
            var origHtml = $btn.html();
            $btn.html('<i class="fa fa-spinner fa-spin"></i>');

            $.ajax({
                url:      '/api/xray/service/' + action,
                type:     'POST',
                dataType: 'json',
                success: function (data) {
                    $btn.html(origHtml);
                    if (data.result !== 'ok') {
                        alert('{{ lang._("Action failed:") }} ' + (data.message || 'unknown error'));
                    }
                    setTimeout(function () {
                        refreshInstancesStatus();
                        $btns.prop('disabled', false);
                        if (callback) callback();
                    }, 1500);
                },
                error: function (xhr) {
                    $btn.html(origHtml);
                    $btns.prop('disabled', false);
                    alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                }
            });
        }

        $('#btnStartAll').click(function () {
            serviceAction('start', null, null);
        });
        $('#btnStopAll').click(function () {
            var confirmStop = '{{ lang._("Stop Xray VPN? Active connections will be terminated.") }}';
            serviceAction('stop', confirmStop, null);
        });
        $('#btnRestartAll').click(function () {
            serviceAction('restart', null, null);
        });

        // ── Import VLESS (inside DialogInstance) ──────────────────
        function applyImportToDialog(data) {
            var $dlg = $('#DialogInstance');
            if (data.name) {
                $dlg.find('[id="instance.name"]').val(data.name);
            }
            ['server','port','vless_uuid','vless_flow','transport','xhttp_mode','xhttp_path','xhttp_host','grpc_service_name','grpc_authority','grpc_multi_mode','security','reality_sni','reality_public_key','reality_short_id','reality_spider_x','fingerprint'].forEach(function (key) {
                if (data[key] === undefined) return;
                var $el = $dlg.find('[id="instance.' + key + '"]');
                if ($el.is(':checkbox')) {
                    $el.prop('checked', data[key] === true || data[key] === 1 || data[key] === '1').trigger('change');
                } else {
                    $el.val(data[key]).trigger('change');
                }
            });
        }

        // Inject the VLESS import panel into DialogInstance on first open
        var dialogInjected = false;
        $('#DialogInstance').on('show.bs.modal', function () {
            if (dialogInjected) {
                // Reset state on each open
                $('#dlgImportLink').val('');
                $('#dlgImportResult').text('').removeClass('text-success text-danger');
                $('#dlgImportPanel').collapse('hide');
                return;
            }
            dialogInjected = true;

            // Import panel — collapsible, injected before the form table
            var importHtml =
                '<div style="margin: 0 0 10px;">' +
                    '<a data-toggle="collapse" href="#dlgImportPanel" class="btn btn-sm btn-default" style="margin-bottom: 6px;">' +
                        '<i class="fa fa-upload"></i> {{ lang._("Import VLESS link") }}' +
                    '</a>' +
                    '<div id="dlgImportPanel" class="collapse">' +
                        '<div class="well well-sm" style="margin-bottom: 0;">' +
                            '<div class="input-group">' +
                                '<input type="text" id="dlgImportLink" class="form-control input-sm"' +
                                '  style="font-family: monospace; font-size: 12px;"' +
                                '  placeholder="vless://UUID@host:443?security=reality&pbk=...#Name" />' +
                                '<span class="input-group-btn">' +
                                    '<button type="button" id="dlgImportParseBtn" class="btn btn-sm btn-primary">' +
                                        '<i class="fa fa-magic"></i> {{ lang._("Parse & Fill") }}' +
                                    '</button>' +
                                '</span>' +
                            '</div>' +
                            '<span id="dlgImportResult" style="font-size: 12px; display: inline-block; margin-top: 4px;"></span>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            var $body = $(this).find('.modal-body');
            $body.prepend(importHtml);

        });

        // ── Validate unsaved client config ──────────────────────────
        // The dialog can be validated before Save.  The API builds the same
        // Xray VLESS/XHTTP/REALITY structure and runs the installed plugin-owned Xray binary
        // with `run -test` against a temporary file; nothing is persisted.
        function dialogInstancePayload() {
            var $dlg = $('#DialogInstance');
            var keys = ['enabled','name','server','port','vless_uuid','vless_flow','transport','xhttp_mode','xhttp_path','xhttp_host',
                        'xhttp_padding_enabled','xhttp_padding_bytes','xhttp_padding_obfs','xhttp_padding_placement',
                        'xhttp_padding_method','xhttp_uplink_method','xhttp_session_placement','xhttp_seq_placement',
                        'grpc_service_name','grpc_authority','grpc_multi_mode',
                        'security','reality_sni','reality_public_key','reality_short_id','reality_spider_x','fingerprint',
                        'socks5_listen','socks5_port','tun_interface','tun_address','mtu','gateway_health_sync','loglevel'];
            var out = {};
            $.each(keys, function (_, key) {
                var $el = $dlg.find('[id="instance.' + key + '"]');
                if (!$el.length) return;
                out[key] = $el.is(':checkbox') ? ($el.is(':checked') ? '1' : '0') : $el.val();
            });
            return out;
        }

        function instanceFieldRow($dlg, field) {
            var $el = $dlg.find('[id="instance.' + field + '"]');
            if (!$el.length) return $();
            var $row = $el.closest('tr');
            if (!$row.length) $row = $el.closest('.form-group');
            return $row;
        }

        function updateTransportFields($dlg) {
            var transport = $dlg.find('[id="instance.transport"]').val() || 'xhttp';
            var groups = {
                xhttp: ['xhttp_mode','xhttp_path','xhttp_host'],
                grpc: ['grpc_service_name','grpc_authority','grpc_multi_mode']
            };
            $.each(groups, function (name, fields) {
                $.each(fields, function (_, field) {
                    instanceFieldRow($dlg, field).toggle(name === transport);
                });
            });

            var $xhttpToggle = $dlg.find('.xray-advanced-toggle-xhttp');
            $xhttpToggle.toggle(transport === 'xhttp');
            if (transport !== 'xhttp') {
                $xhttpToggle.find('.xray-advanced-toggle')
                    .attr('aria-expanded', 'false')
                    .find('i').removeClass('fa-chevron-down').addClass('fa-chevron-right');
                $.each(['xhttp_padding_enabled','xhttp_padding_bytes','xhttp_padding_obfs','xhttp_padding_placement',
                        'xhttp_padding_method','xhttp_uplink_method','xhttp_session_placement','xhttp_seq_placement'],
                    function (_, field) { instanceFieldRow($dlg, field).hide(); });
            }
        }

        function setupCollapsedAdvancedSections($dlg) {
            var groups = [
                {
                    id: 'xhttp',
                    title: '{{ lang._("Advanced: XHTTP traffic shaping") }}',
                    fields: ['xhttp_padding_enabled','xhttp_padding_bytes','xhttp_padding_obfs','xhttp_padding_placement',
                             'xhttp_padding_method','xhttp_uplink_method','xhttp_session_placement','xhttp_seq_placement']
                },
                {
                    id: 'local',
                    title: '{{ lang._("Advanced: Local SOCKS5 / HEV TUN") }}',
                    fields: ['socks5_listen','socks5_port','tun_interface','tun_address','mtu','gateway_health_sync','loglevel']
                }
            ];

            $.each(groups, function (_, group) {
                var rows = $();
                $.each(group.fields, function (_, field) {
                    var $el = $dlg.find('[id="instance.' + field + '"]');
                    if (!$el.length) return;
                    var $row = $el.closest('tr');
                    if (!$row.length) $row = $el.closest('.form-group');
                    rows = rows.add($row);
                });
                if (!rows.length) return;

                var marker = 'xray-advanced-toggle-' + group.id;
                var $first = rows.first();
                if (!$dlg.find('.' + marker).length) {
                    var $toggleRow = $('<tr class="' + marker + '"><td colspan="2">' +
                        '<button type="button" class="btn btn-default btn-sm xray-advanced-toggle" aria-expanded="false">' +
                        '<i class="fa fa-chevron-right"></i> ' + group.title + '</button></td></tr>');
                    if ($first.is('tr')) {
                        $first.before($toggleRow);
                    } else {
                        $toggleRow = $('<div class="' + marker + '" style="margin:8px 0">' +
                            '<button type="button" class="btn btn-default btn-sm xray-advanced-toggle" aria-expanded="false">' +
                            '<i class="fa fa-chevron-right"></i> ' + group.title + '</button></div>');
                        $first.before($toggleRow);
                    }
                    $toggleRow.find('.xray-advanced-toggle').on('click', function () {
                        var opening = $(this).attr('aria-expanded') !== 'true';
                        $(this).attr('aria-expanded', opening ? 'true' : 'false');
                        $(this).find('i').toggleClass('fa-chevron-right', !opening).toggleClass('fa-chevron-down', opening);
                        rows.toggle(opening);
                    });
                }
                rows.hide();
                $dlg.find('.' + marker + ' .xray-advanced-toggle')
                    .attr('aria-expanded', 'false')
                    .find('i').removeClass('fa-chevron-down').addClass('fa-chevron-right');
            });
        }

        $('#DialogInstance').on('shown.bs.modal', function () {
            setupCollapsedAdvancedSections($(this));
            updateTransportFields($(this));
            var $footer = $(this).find('.modal-footer');
            if (!$footer.find('#dlgValidateConfigBtn').length) {
                $footer.prepend(
                    '<span id="dlgValidateResult" style="margin-right:8px;"></span>' +
                    '<button type="button" id="dlgValidateConfigBtn" class="btn btn-default" style="margin-right:6px;">' +
                    '<i class="fa fa-check-circle"></i> {{ lang._("Validate Config") }}</button>'
                );
            }
            $('#dlgValidateResult').text('').removeClass('text-success text-danger');
        });

        $(document).on('change', '#DialogInstance [id="instance.transport"]', function () {
            updateTransportFields($('#DialogInstance'));
        });

        $(document).on('click', '#dlgValidateConfigBtn', function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#dlgValidateResult').removeClass('text-success text-danger')
                .text("{{ lang._('Validating...') }}");
            $.ajax({
                url: '/api/xray/instance/validateDraft',
                type: 'POST',
                contentType: 'application/json; charset=utf-8',
                data: JSON.stringify({instance: dialogInstancePayload()}),
                dataType: 'json',
                success: function (data) {
                    if (data && data.result === 'ok') {
                        $res.addClass('text-success').text(data.message || "{{ lang._('Configuration OK.') }}");
                    } else {
                        $res.addClass('text-danger').text((data && data.message) || "{{ lang._('Validation failed.') }}");
                    }
                },
                error: function (xhr) {
                    var msg = "{{ lang._('HTTP error:') }} " + xhr.status;
                    if (xhr.responseJSON && xhr.responseJSON.message) msg += ': ' + xhr.responseJSON.message;
                    $res.addClass('text-danger').text(msg);
                },
                complete: function () { $btn.prop('disabled', false); }
            });
        });

        // Import parse handler (inside dialog)
        $(document).on('click', '#dlgImportParseBtn', function () {
            var link = $.trim($('#dlgImportLink').val());
            var $res = $('#dlgImportResult');
            if (!link) {
                $res.removeClass('text-success').addClass('text-danger')
                    .text("{{ lang._('Paste a VLESS link first.') }}");
                return;
            }

            var $btn = $(this).prop('disabled', true);
            $res.removeClass('text-success text-danger').text("{{ lang._('Parsing...') }}");
            var b64 = btoa(unescape(encodeURIComponent(link)));

            $.ajax({
                url:         '/api/xray/import/parse',
                type:        'POST',
                contentType: 'application/json; charset=utf-8',
                data:        JSON.stringify({link_b64: b64}),
                dataType:    'json',
                success: function (data) {
                    $btn.prop('disabled', false);
                    if (data.status !== 'ok') {
                        $res.removeClass('text-success').addClass('text-danger')
                            .text("{{ lang._('Parse error:') }} " + (data.message || 'unknown'));
                        return;
                    }
                    applyImportToDialog(data);
                    $res.removeClass('text-danger').addClass('text-success')
                        .text("{{ lang._('Imported! Fields filled from link.') }}");
                    // Collapse import panel after success
                    setTimeout(function () { $('#dlgImportPanel').collapse('hide'); }, 1500);
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    $res.removeClass('text-success').addClass('text-danger')
                        .text("{{ lang._('HTTP error:') }} " + xhr.status);
                }
            });
        });

        // Enter key in import field triggers parse
        $(document).on('keypress', '#dlgImportLink', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#dlgImportParseBtn').click();
            }
        });

        // Diagnostics selectors exist only on the Diagnostics page.

        // ── Diagnostics ─────────────────────────────────────────────
        function populateInstanceSelects() {
            $.ajax({
                url: '/api/xray/instance/searchItem',
                type: 'POST',
                dataType: 'json',
                data: {rowCount: -1, current: 1, searchPhrase: ''},
                success: function (data) {
                    var rows = (data && data.rows) ? data.rows : [];
                    var $diagSel = $('#diagInstanceSelect');
                    var $logSel = $('#logInstanceSelect');
                    var savedDiag = $diagSel.val();
                    var savedLog = $logSel.val();
                    $diagSel.empty();
                    $logSel.empty();
                    $.each(rows, function (_, row) {
                        $diagSel.append($('<option></option>').val(row.uuid).text(row.name || row.uuid));
                        $logSel.append($('<option></option>').val(row.uuid).text(row.name || row.uuid));
                    });
                    if (savedDiag && $diagSel.find('option[value="' + savedDiag + '"]').length) {
                        $diagSel.val(savedDiag);
                    }
                    if (savedLog && $logSel.find('option[value="' + savedLog + '"]').length) {
                        $logSel.val(savedLog);
                    }
                }
            });
        }

        function loadDiagnostics() {
            var uuid = $('#diagInstanceSelect').val();
            var url = '/api/xray/service/diagnostics' + (uuid ? '/' + encodeURIComponent(uuid) : '');
            $('#btnDiagRefresh').prop('disabled', true);
            $('#diagError').hide();
            ajaxGet(url, {}, function (data) {
                $('#btnDiagRefresh').prop('disabled', false);
                if (data.error) {
                    $('#diagError').text(data.error).show();
                    return;
                }
                var running = data.tun_status === 'running';
                var statusHtml = running
                    ? '<span class="label label-success">running</span>'
                    : '<span class="label label-danger">' + escAttr(data.tun_status || 'down') + '</span>';

                $('#diag_tun_iface').text(data.tun_interface  || '\u2014');
                $('#diag_tun_status').html(statusHtml);
                $('#diag_tun_ip').text(data.tun_ip           || '\u2014');
                $('#diag_mtu').text(data.mtu > 0 ? data.mtu + ' bytes' : '\u2014');
                $('#diag_bytes_in').text(data.bytes_in_hr    || '\u2014');
                $('#diag_bytes_out').text(data.bytes_out_hr  || '\u2014');
                $('#diag_pkts_in').text(data.pkts_in != null ? data.pkts_in.toLocaleString() : '\u2014');
                $('#diag_pkts_out').text(data.pkts_out != null ? data.pkts_out.toLocaleString() : '\u2014');
                $('#diag_xray_uptime').text(data.xray_uptime || '\u2014');
                var conn = data.connectivity || 'unknown';
                var connClass = conn === 'online' ? 'label-success' : (conn === 'offline' ? 'label-danger' : (conn === 'stale' ? 'label-warning' : 'label-default'));
                $('#diag_connectivity').html('<span class="label ' + connClass + '" title="' + escAttr(data.health_message || '') + '">' + escAttr(conn) + '</span>');
                $('#diag_health_checked').text(data.health_checked_at ? new Date(data.health_checked_at * 1000).toLocaleString() : '\u2014');
                $('#diag_health_latency').text(data.health_latency_ms != null ? data.health_latency_ms + ' ms' : '\u2014');
                $('#diag_health_failures').text(data.health_failures != null ? data.health_failures : '\u2014');
                $('#diag_assignment').text(data.assigned ? ((data.assignment || '') + (data.assignment_descr ? ' (' + data.assignment_descr + ')' : '')) : 'not assigned');
                var ip4mode = String(data.interface_ip_config || 'none').toLowerCase();
                var ip6mode = String(data.interface_ipv6_config || 'none').toLowerCase();
                var ipModeOk = (ip4mode === '' || ip4mode === 'none') && (ip6mode === '' || ip6mode === 'none');
                $('#diag_ip_config').html(ipModeOk
                    ? '<span class="label label-success">IPv4/IPv6: None</span>'
                    : '<span class="label label-danger">IPv4: ' + escAttr(ip4mode) + ', IPv6: ' + escAttr(ip6mode) + '</span>');
                $('#diag_dynamic_gateway').html(data.dynamic_gateway_policy
                    ? '<span class="label label-danger">enabled — disable for Far Gateway mode</span>'
                    : '<span class="label label-success">disabled</span>');
                var syncHtml = data.gateway_health_sync_enabled
                    ? (data.gateway_sync_ready ? '<span class="label label-success">enabled</span>' : '<span class="label label-warning">enabled / not ready</span>')
                    : '<span class="label label-default">disabled</span>';
                $('#diag_gateway_sync').html(syncHtml);
                var gwIdentity = data.native_gateway_name || '\u2014';
                if (data.native_gateway_name && data.native_gateway_address) {
                    gwIdentity += ' @ ' + data.native_gateway_address;
                }
                if (data.native_gateway_name && data.native_gateway_is_far) {
                    gwIdentity += ' (Far)';
                }
                if (!data.native_gateway_name && data.expected_gateway_ip) {
                    gwIdentity = 'expected ' + data.expected_gateway_ip;
                }
                $('#diag_native_gateway').text(gwIdentity);
                var gwStatus = data.native_gateway_status_text || data.native_gateway_status || '';
                var gwClass = data.native_gateway_force_down || data.native_gateway_status === 'force_down' || data.native_gateway_status === 'down'
                    ? 'label-danger'
                    : (gwStatus ? 'label-success' : 'label-default');
                var forceSuffix = data.native_gateway_force_down ? ' (Force Down)' : '';
                $('#diag_native_gateway_status').html(gwStatus
                    ? '<span class="label ' + gwClass + '">' + escAttr(gwStatus + forceSuffix) + '</span>'
                    : '\u2014');
                $('#diag_pf_route').html(data.pf_route_to_present ? '<span class="label label-success">present</span>' : '<span class="label label-warning" title="No route-to rule referencing this TUN was found in the loaded/generated PF ruleset">not detected</span>');
                $('#diag_endpoint').text(data.server_address ? (data.server_address + (data.server_port ? ':' + data.server_port : '')) : '\u2014');
            });
        }

        var diagAutoRefresh = null;
        $('a[href="#diagnostics"]').on('shown.bs.tab', function () {
            loadDiagnostics();
            if (!diagAutoRefresh) {
                diagAutoRefresh = setInterval(function () {
                    if ($('#diagnostics').hasClass('active')) {
                        loadDiagnostics();
                    }
                }, 30000);
            }
        });
        $('#btnDiagRefresh').click(function () {
            loadDiagnostics();
        });
        $('#diagInstanceSelect').on('change', function () {
            loadDiagnostics();
        });
        $('#logInstanceSelect').on('change', function () {
            loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn', true);
        });

        // ── Logs ────────────────────────────────────────────────────
        function loadLog(apiEndpoint, preId, btnId, perInstance) {
            var uuid = perInstance ? $('#logInstanceSelect').val() : '';
            if (perInstance && !uuid) {
                $('#' + preId).text("{{ lang._('Select a client.') }}");
                return;
            }
            var apiEndpointWithUuid = apiEndpoint + (uuid ? '/' + encodeURIComponent(uuid) : '');
            $('#' + btnId).prop('disabled', true);
            $('#' + preId).text("{{ lang._('Loading...') }}");
            $.post(apiEndpointWithUuid, null, function (data) {
                var text = (data && data.log) || "{{ lang._('Log is empty.') }}";
                $('#' + preId).text(text);
                $('#' + btnId).prop('disabled', false);
                var pre = document.getElementById(preId);
                if (pre) { pre.scrollTop = pre.scrollHeight; }
            }, 'json').fail(function (xhr) {
                $('#' + preId).text("{{ lang._('Error loading log:') }} " + xhr.status);
                $('#' + btnId).prop('disabled', false);
            });
        }

        $('a[href="#logs"]').on('shown.bs.tab', function () {
            var $active = $('#logSubTabs .active a');
            var href = $active.attr('href');
            if (href === '#logStartup') {
                loadLog("/api/xray/service/log", 'logStartupContent', 'logStartupRefreshBtn', false);
            } else if (href === '#logCore') {
                loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn', true);
            } else if (href === '#logWatchdog') {
                loadLog("/api/xray/service/watchdoglog", 'logWatchdogContent', 'logWatchdogRefreshBtn', false);
            }
        });

        $('#logSubTabs a').on('shown.bs.tab', function (e) {
            var href = $(e.target).attr('href');
            if (href === '#logStartup') {
                loadLog("/api/xray/service/log", 'logStartupContent', 'logStartupRefreshBtn', false);
            } else if (href === '#logCore') {
                loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn', true);
            } else if (href === '#logWatchdog') {
                loadLog("/api/xray/service/watchdoglog", 'logWatchdogContent', 'logWatchdogRefreshBtn', false);
            }
        });

        $("#logStartupRefreshBtn").click(function () {
            loadLog("/api/xray/service/log", 'logStartupContent', 'logStartupRefreshBtn', false);
        });
        $("#logCoreRefreshBtn").click(function () {
            loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn', true);
        });
        $("#logWatchdogRefreshBtn").click(function () {
            loadLog("/api/xray/service/watchdoglog", 'logWatchdogContent', 'logWatchdogRefreshBtn', false);
        });

        // ── Copy Debug Info ─────────────────────────────────────────
        $('#btnCopyDebug').click(function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#copyDebugResult');
            $res.removeClass('text-success text-danger').text("{{ lang._('Collecting...') }}");

            var diagData = {}, serviceLog = '', coreLog = '', watchdogLog = '';
            var diagDone = $.Deferred(), serviceDone = $.Deferred(), coreDone = $.Deferred(), watchdogDone = $.Deferred();

            var debugUuid = $('#diagInstanceSelect').val() || $('#logInstanceSelect').val();
            if (debugUuid) {
                ajaxGet('/api/xray/service/diagnostics/' + encodeURIComponent(debugUuid), {}, function (data) {
                    diagData = data;
                    diagDone.resolve();
                });
            } else {
                diagData = {error: 'No client selected'};
                diagDone.resolve();
            }
            $.post('/api/xray/service/log', null, function (data) {
                serviceLog = (data && data.log) || '';
                serviceDone.resolve();
            }, 'json').fail(function () { serviceDone.resolve(); });
            $.post('/api/xray/service/watchdoglog', null, function (data) {
                watchdogLog = (data && data.log) || '';
                watchdogDone.resolve();
            }, 'json').fail(function () { watchdogDone.resolve(); });
            if (debugUuid) {
                $.post('/api/xray/service/xraylog/' + encodeURIComponent(debugUuid), null, function (data) {
                    coreLog = (data && data.log) || '';
                    coreDone.resolve();
                }, 'json').fail(function () { coreDone.resolve(); });
            } else {
                coreDone.resolve();
            }

            $.when(diagDone, serviceDone, coreDone, watchdogDone).done(function () {
                var info = "=== Xray Debug Info ===\n"
                    + "Date: " + new Date().toISOString() + "\n\n"
                    + "--- Diagnostics ---\n"
                    + JSON.stringify(diagData, null, 2) + "\n\n"
                    + "--- Service Log (last 200 lines) ---\n"
                    + serviceLog + "\n\n"
                    + "--- Watchdog Log (last 200 lines) ---\n"
                    + watchdogLog + "\n\n"
                    + "--- Core Log (last 200 lines) ---\n"
                    + coreLog + "\n";

                $('#debugInfoContent').val(info);
                $('#debugInfoModal').modal('show');
                $('#debugInfoModal').one('shown.bs.modal', function () {
                    var ta = document.getElementById('debugInfoContent');
                    ta.focus();
                    ta.select();
                });
                $res.addClass('text-success').text("{{ lang._('Use Ctrl+C / Cmd+C to copy') }}");
                $btn.prop('disabled', false);
            });
        });

        // Separate OPNsense menu pages do not emit a Bootstrap "shown" event on first render.
        if (currentSection === 'diagnostics') {
            setTimeout(function () {
                populateInstanceSelects();
                setTimeout(loadDiagnostics, 250);
            }, 0);
            if (!diagAutoRefresh) {
                diagAutoRefresh = setInterval(loadDiagnostics, 30000);
            }
        }

        // ── Tab hash ────────────────────────────────────────────────
        if (window.location.hash !== "") {
            $('a[href="' + window.location.hash + '"]').click();
        }
        $('.nav-tabs a').on('shown.bs.tab', function (e) {
            history.pushState(null, null, e.target.hash);
        });
    });
</script>
