{{ partial('OPNsense/Xray/partials/scripts') }}

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
{% if section == 'general' %}
    <li class="active"><a data-toggle="tab" href="#general">{{ lang._('General') }}</a></li>
{% elseif section == 'clients' %}
    <li class="active"><a data-toggle="tab" href="#instances">{{ lang._('Clients') }}</a></li>
{% elseif section == 'diagnostics' %}
    <li class="active"><a data-toggle="tab" href="#diagnostics">{{ lang._('Diagnostics') }}</a></li>
    <li><a data-toggle="tab" href="#logs">{{ lang._('Log') }}</a></li>
{% endif %}
</ul>

<div class="tab-content content-box">
    <div id="general" class="tab-pane fade in{% if section == 'general' %} active{% endif %}"{% if section != 'general' %} style="display:none;"{% endif %}>
        {{ partial('OPNsense/Xray/partials/general_status') }}
        {{ partial("layout_partials/base_form", {'fields': generalForm, 'id': 'frm_general_settings'}) }}
    </div>

{% if section == 'clients' %}
    {{ partial('OPNsense/Xray/partials/tab_instances') }}
{% elseif section == 'diagnostics' %}
    {{ partial('OPNsense/Xray/partials/tab_diagnostics') }}
    {{ partial('OPNsense/Xray/partials/tab_logs') }}
{% endif %}
</div>

{% if section == 'general' %}
<section class="grid-bottom-reserve __mt">
    <div class="alert content-box" style="display:flex;align-items:center;margin-bottom:0;">
        <button class="btn btn-primary"
                id="reconfigureAct"
                type="button"
                data-endpoint="/api/xray/service/reconfigure"
                data-label="{{ lang._('Save') }}"
                data-error-title="{{ lang._('Error applying Xray settings.') }}">
            <i class="fa fa-save"></i> {{ lang._('Save') }}
        </button>
    </div>
</section>
{% endif %}

{% if section == 'clients' %}
{{ partial("layout_partials/base_dialog", ['fields': instanceForm, 'id': 'DialogInstance', 'label': lang._('Edit Client')]) }}
{% endif %}
{% if section == 'diagnostics' %}
{{ partial('OPNsense/Xray/partials/modal_debug') }}
{% endif %}
