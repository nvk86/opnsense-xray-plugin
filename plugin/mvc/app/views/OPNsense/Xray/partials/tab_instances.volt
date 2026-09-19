<!-- CLIENTS -->
<div id="instances" class="tab-pane fade in{% if section == 'clients' %} active{% endif %}"{% if section != 'clients' %} style="display:none;"{% endif %}>

    {# ── Instances grid ──────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <table id="grid-instances"
                   class="table table-condensed table-hover table-striped"
                   data-editDialog="DialogInstance"
                   data-editAlert="InstanceChangeMessage">
                <thead>
                    <tr>
                        <th data-column-id="uuid"
                            data-type="string"
                            data-identifier="true"
                            data-visible="false">{{ lang._('ID') }}</th>

                        <th data-column-id="enabled"
                            data-width="6em"
                            data-type="string"
                            data-formatter="rowtoggle">{{ lang._('Enabled') }}</th>

                        <th data-column-id="name"
                            data-type="string">{{ lang._('Name') }}</th>

                        <th data-column-id="server_address"
                            data-type="string">{{ lang._('Server') }}</th>

                        <th data-column-id="server_port"
                            data-type="string"
                            data-width="6em">{{ lang._('Port') }}</th>

                        <th data-column-id="transport"
                            data-type="string"
                            data-width="8em">{{ lang._('Transport') }}</th>

                        <th data-column-id="inst_status"
                            data-formatter="instanceStatus"
                            data-sortable="false"
                            data-width="10em">{{ lang._('Status') }}</th>

                        <th data-column-id="inst_testresult"
                            data-formatter="instanceTestResult"
                            data-sortable="false"
                            data-width="10em">{{ lang._('Test Result') }}</th>

                        <th data-column-id="commands"
                            data-formatter="commands"
                            data-sortable="false"
                            data-width="11em">{{ lang._('') }}</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <td></td>
                        <td>
                            <button data-action="add" type="button" class="btn btn-xs btn-primary">
                                <span class="fa fa-fw fa-plus"></span>
                            </button>
                            <button data-action="deleteSelected" type="button" class="btn btn-xs btn-default">
                                <span class="fa fa-fw fa-trash-o"></span>
                            </button>
                        </td>
                    </tr>
                </tfoot>
            </table>

        </section>
    </div>

</div>
