<!-- GLOBAL SERVICE STATUS / CONTROLS -->
<div class="row">
    <section class="col-xs-12">
        <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;
                    display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
            <div>
                <span id="badge_xray" class="label label-default">xray-core: ...</span>
                <span id="badge_tun"  class="label label-default">HEV TUN: ...</span>
                <span id="badge_link" class="label label-default">VPN link: ...</span>
            </div>

            <div style="width: 1px; height: 22px; background: #ddd;"></div>

            <div style="display: flex; gap: 4px;">
                <button id="btnStartAll" class="btn btn-xs btn-success"
                        title="{{ lang._('Start all enabled clients') }}">
                    <i class="fa fa-play fa-fw"></i> {{ lang._('Start All') }}
                </button>
                <button id="btnStopAll" class="btn btn-xs btn-danger"
                        title="{{ lang._('Stop all running clients') }}">
                    <i class="fa fa-stop fa-fw"></i> {{ lang._('Stop All') }}
                </button>
                <button id="btnRestartAll" class="btn btn-xs btn-warning"
                        title="{{ lang._('Restart all running clients') }}">
                    <i class="fa fa-refresh fa-fw"></i> {{ lang._('Restart All') }}
                </button>
            </div>

        </div>
    </section>
</div>
