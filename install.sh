#!/bin/sh
# opnsense-xray-plugin 1.0.1 installer for OPNsense / FreeBSD 15+
#
# Xray-core and HevSocks5Tunnel are installed as plugin-owned upstream
# binaries under /usr/local/libexec/xray. The installer never enables a
# FreeBSD package repository and never installs/removes the xray-core package.
#
# At install/upgrade time the installer resolves the newest non-draft upstream
# releases from GitHub (including Xray pre-releases), verifies the selected
# release-asset SHA256 digests published by GitHub, and additionally verifies
# Xray against the matching upstream .dgst asset.
#
# Already-installed current upstream components are verified in place and are
# not downloaded again.
#
# Usage:
#   sh install.sh
#   sh install.sh uninstall

set -eu

PLUGIN_VERSION="1.0.1"
XRAY_RELEASES_API="https://api.github.com/repos/XTLS/Xray-core/releases?per_page=20"
HEV_RELEASES_API="https://api.github.com/repos/heiher/hev-socks5-tunnel/releases?per_page=20"

SCRIPT_DIR=$(CDPATH= cd "$(dirname "$0")" && pwd -P)
PLUGIN_DIR="$SCRIPT_DIR/plugin"

VERSION_FILE="/usr/local/opnsense/mvc/app/models/OPNsense/Xray/version.txt"
XRAY_HOME="/usr/local/libexec/xray"
XRAY_BIN="$XRAY_HOME/xray"
XRAY_UPSTREAM_INFO="$XRAY_HOME/upstream-release.txt"
HEV_BIN="$XRAY_HOME/hev-socks5-tunnel"
HEV_UPSTREAM_INFO="$XRAY_HOME/hev-upstream-release.txt"
XRAY_ASSET_DIR="/usr/local/share/opnsense-xray"
LEGACY_SHARED_ASSET_DIR="/usr/local/share/xray"
XRAY_CONF_DIR="/usr/local/etc/opnsense-xray"
LEGACY_SHARED_CONF_DIR="/usr/local/etc/xray"

OLD_XRAY_BIN="/usr/local/bin/xray"
OLD_XRAY_CONF_DIR="/usr/local/etc/xray-core"
SERVICE_CONTROL="/usr/local/opnsense/scripts/Xray/xray-service-control.php"
CONTROL_TIMEOUT="45s"

warn() { echo "[WARN] $*" >&2; }
die()  { echo "[ERROR] $*" >&2; exit 1; }

ask_yes_no() {
    _prompt="$1"
    _default="$2"
    if [ "$_default" = "y" ]; then
        printf "%s [Y/n] " "$_prompt"
        _fallback="y"
    else
        printf "%s [y/N] " "$_prompt"
        _fallback="n"
    fi
    read -r _answer < /dev/tty 2>/dev/null || _answer="$_fallback"
    [ -n "$_answer" ] || _answer="$_fallback"
    case "$_answer" in [yY]*) return 0 ;; *) return 1 ;; esac
}

run_control_bounded() {
    _control="$1"
    shift
    # FreeBSD timeout(1) acts as a process reaper by default and waits for all
    # descendants. start_instance intentionally leaves daemon(8) -> Xray alive,
    # so the default mode would always time out a successful runtime restore.
    # -f bounds only the PHP controller itself and does not reap/wait for the
    # long-lived daemonized Xray descendants.
    /usr/bin/timeout -f -k 5s "$CONTROL_TIMEOUT" /usr/local/bin/php "$_control" "$@"
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "Required command is missing: $1"
}

select_upstream_asset() {
    _arch=$(uname -m 2>/dev/null || echo unknown)
    case "$_arch" in
        amd64|x86_64)
            XRAY_ARCHIVE="Xray-freebsd-64.zip"
            HEV_ASSET="hev-socks5-tunnel-freebsd-x86_64"
            ;;
        *)
            die "opnsense-xray-plugin currently supports amd64 only: upstream HEV does not publish a FreeBSD arm64 binary. Found: $_arch"
            ;;
    esac
    XRAY_ARCHIVE_DGST="${XRAY_ARCHIVE}.dgst"
}

release_metadata_line() {
    _json="$1"
    _asset="$2"
    _dgst="${3:-}"
    /usr/local/bin/php -r '
        $file = $argv[1];
        $assetName = $argv[2];
        $dgstName = $argv[3] ?? "";
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) exit(2);
        foreach ($data as $rel) {
            if (!is_array($rel) || !empty($rel["draft"])) continue;
            $assets = [];
            foreach (($rel["assets"] ?? []) as $a) {
                if (is_array($a) && isset($a["name"])) $assets[(string)$a["name"]] = $a;
            }
            if (!isset($assets[$assetName])) continue;
            if ($dgstName !== "" && !isset($assets[$dgstName])) continue;
            $a = $assets[$assetName];
            $d = $dgstName !== "" ? $assets[$dgstName] : null;
            $digest = strtolower(preg_replace("~^sha256:~i", "", (string)($a["digest"] ?? "")));
            $ddigest = $d ? strtolower(preg_replace("~^sha256:~i", "", (string)($d["digest"] ?? ""))) : "";
            $tag = (string)($rel["tag_name"] ?? "");
            $version = preg_replace("~^v~", "", $tag);
            $url = (string)($a["browser_download_url"] ?? "");
            $durl = $d ? (string)($d["browser_download_url"] ?? "") : "";
            $channel = !empty($rel["prerelease"]) ? "prerelease" : "stable";
            if ($version === "" || $url === "" || !preg_match("~^[0-9a-f]{64}$~", $digest)) exit(3);
            if ($dgstName !== "" && ($durl === "" || !preg_match("~^[0-9a-f]{64}$~", $ddigest))) exit(4);
            echo $version, "|", $digest, "|", $url, "|", $ddigest, "|", $durl, "|", $channel, PHP_EOL;
            exit(0);
        }
        exit(5);
    ' "$_json" "$_asset" "$_dgst"
}

resolve_upstream_releases() {
    echo "==> Resolving latest upstream releases..."
    _meta="$STATE_DIR/release-metadata"
    mkdir -p "$_meta"
    _xjson="$_meta/xray.json"
    _hjson="$_meta/hev.json"

    fetch -q -T 30 -o "$_xjson" "$XRAY_RELEASES_API" || die "Could not query Xray GitHub releases."
    fetch -q -T 30 -o "$_hjson" "$HEV_RELEASES_API" || die "Could not query HEV GitHub releases."
    [ -s "$_xjson" ] || die "Xray GitHub release metadata is empty."
    [ -s "$_hjson" ] || die "HEV GitHub release metadata is empty."

    _xline=$(release_metadata_line "$_xjson" "$XRAY_ARCHIVE" "$XRAY_ARCHIVE_DGST") \
        || die "Could not resolve a current Xray release containing $XRAY_ARCHIVE and $XRAY_ARCHIVE_DGST."
    IFS='|' read -r XRAY_VERSION XRAY_ARCHIVE_SHA256 XRAY_URL XRAY_DGST_SHA256 XRAY_DGST_URL XRAY_CHANNEL <<EOF
$_xline
EOF

    _hline=$(release_metadata_line "$_hjson" "$HEV_ASSET" "") \
        || die "Could not resolve a current HEV release containing $HEV_ASSET."
    IFS='|' read -r HEV_VERSION HEV_SHA256 HEV_URL _unused1 _unused2 HEV_CHANNEL <<EOF
$_hline
EOF

    case "$XRAY_ARCHIVE_SHA256:$XRAY_DGST_SHA256:$HEV_SHA256" in
        *[!0-9a-f:]*|*::*) die "Resolved upstream SHA256 metadata is invalid." ;;
    esac
    echo "[OK]  Latest Xray: v$XRAY_VERSION ($XRAY_CHANNEL)"
    echo "[OK]  Latest HEV : $HEV_VERSION ($HEV_CHANNEL)"
}

metadata_value() {
    _file="$1"; _key="$2"
    [ -r "$_file" ] || return 1
    sed -n "s/^${_key}=//p" "$_file" | head -n 1
}

assess_installed_upstream() {
    NEED_XRAY=1
    NEED_HEV=1

    if [ -x "$XRAY_BIN" ] && [ -r "$XRAY_ASSET_DIR/geoip.dat" ] && [ -r "$XRAY_ASSET_DIR/geosite.dat" ] && [ -r "$XRAY_UPSTREAM_INFO" ]; then
        _v=$(metadata_value "$XRAY_UPSTREAM_INFO" version 2>/dev/null || true)
        _archive_sha=$(metadata_value "$XRAY_UPSTREAM_INFO" archive_sha256 2>/dev/null || true)
        _dgst_sha=$(metadata_value "$XRAY_UPSTREAM_INFO" dgst_sha256 2>/dev/null || true)
        _bin_sha=$(metadata_value "$XRAY_UPSTREAM_INFO" binary_sha256 2>/dev/null || true)
        _geoip_sha=$(metadata_value "$XRAY_UPSTREAM_INFO" geoip_sha256 2>/dev/null || true)
        _geosite_sha=$(metadata_value "$XRAY_UPSTREAM_INFO" geosite_sha256 2>/dev/null || true)
        _line=$("$XRAY_BIN" version 2>/dev/null | head -n 1 || true)
        if [ "$_v" = "$XRAY_VERSION" ] \
            && [ "$_archive_sha" = "$XRAY_ARCHIVE_SHA256" ] \
            && [ "$_dgst_sha" = "$XRAY_DGST_SHA256" ] \
            && printf '%s\n' "$_line" | grep -Fq "Xray $XRAY_VERSION" \
            && [ -n "$_bin_sha" ] && [ "$(sha256 -q "$XRAY_BIN" | tr 'A-F' 'a-f')" = "$_bin_sha" ] \
            && [ -n "$_geoip_sha" ] && [ "$(sha256 -q "$XRAY_ASSET_DIR/geoip.dat" | tr 'A-F' 'a-f')" = "$_geoip_sha" ] \
            && [ -n "$_geosite_sha" ] && [ "$(sha256 -q "$XRAY_ASSET_DIR/geosite.dat" | tr 'A-F' 'a-f')" = "$_geosite_sha" ]; then
            XRAY_BINARY_SHA256="$_bin_sha"
            XRAY_GEOIP_SHA256="$_geoip_sha"
            XRAY_GEOSITE_SHA256="$_geosite_sha"
            NEED_XRAY=0
        fi
    fi

    if [ -x "$HEV_BIN" ] && [ -r "$HEV_UPSTREAM_INFO" ]; then
        _v=$(metadata_value "$HEV_UPSTREAM_INFO" version 2>/dev/null || true)
        _sha=$(sha256 -q "$HEV_BIN" | tr 'A-F' 'a-f')
        if [ "$_v" = "$HEV_VERSION" ] && [ "$_sha" = "$HEV_SHA256" ]; then
            NEED_HEV=0
        fi
    fi

    [ "$NEED_XRAY" = "1" ] || echo "[OK]  Xray v$XRAY_VERSION is already current and verified; download will be skipped."
    [ "$NEED_HEV" = "1" ] || echo "[OK]  HEV $HEV_VERSION is already current and verified; download will be skipped."
}

require_root_opnsense() {
    [ "$(id -u)" = "0" ] || die "Run this installer as root."
    [ -d "$PLUGIN_DIR" ] || die "Plugin source directory not found: $PLUGIN_DIR"
    [ -x /usr/local/bin/php ] || die "This does not look like OPNsense: /usr/local/bin/php is missing."
    [ -x /usr/local/sbin/configctl ] || die "This does not look like OPNsense: configctl is missing."
    [ -f /conf/config.xml ] || die "This does not look like OPNsense: /conf/config.xml is missing."

    require_command freebsd-version
    require_command fetch
    require_command tar
    require_command sha256
    require_command cmp
    require_command ps
    require_command grep
    require_command sed
    require_command awk
    require_command sysrc
    require_command service
    require_command timeout
    require_command curl

    _major=$(freebsd-version -u 2>/dev/null | sed -nE 's/^([0-9]+).*/\1/p' | head -1)
    [ -n "$_major" ] || die "Could not determine FreeBSD userland version."
    [ "$_major" -ge 15 ] || die "This build targets OPNsense on FreeBSD 15.x or newer; found $(freebsd-version -u 2>/dev/null || echo unknown)."
    select_upstream_asset
}

validate_source_tree() {
    echo "==> Validating installer source tree..."

    for _req in \
        "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        "$PLUGIN_DIR/scripts/Xray/xray-watchdog.php" \
        "$PLUGIN_DIR/scripts/Xray/xray-health.php" \
        "$PLUGIN_DIR/scripts/Xray/xray-gateway-sync.php" \
        "$PLUGIN_DIR/scripts/Xray/xray-ifstats.php" \
        "$PLUGIN_DIR/scripts/Xray/xray-testconnect.php" \
        "$PLUGIN_DIR/scripts/Xray/xray-log.php" \
        "$PLUGIN_DIR/scripts/Xray/xray-logrotate-hup.php" \
        "$PLUGIN_DIR/service/conf/actions.d/actions_xray.conf" \
        "$PLUGIN_DIR/etc/inc/plugins.inc.d/xray.inc" \
        "$PLUGIN_DIR/etc/rc.syshook.d/start/50-xray" \
        "$PLUGIN_DIR/etc/newsyslog.conf.d/xray.conf" \
        "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/General.xml" \
        "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.xml" \
        "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/ACL/ACL.xml" \
        "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Menu/Menu.xml" \
        "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/General.php" \
        "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.php" \
        "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/IndexController.php" \
        "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/PageControllerBase.php" \
        "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/GeneralController.php" \
        "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/ClientsController.php" \
        "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/DiagnosticsController.php" \
        "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/GeneralController.php" \
        "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/ImportController.php" \
        "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/InstanceController.php" \
        "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/ServiceController.php" \
        "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/forms/general.xml" \
        "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/forms/instance.xml" \
        "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/general.volt" \
        "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/modal_debug.volt" \
        "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/general_status.volt" \
        "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/scripts.volt" \
        "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/tab_diagnostics.volt" \
        "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/tab_instances.volt" \
        "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/tab_logs.volt"; do
        [ -f "$_req" ] || die "Required plugin source file is missing: $_req"
    done

    for _php in "$PLUGIN_DIR"/scripts/Xray/*.php \
                "$PLUGIN_DIR"/mvc/app/controllers/OPNsense/Xray/*.php \
                "$PLUGIN_DIR"/mvc/app/controllers/OPNsense/Xray/Api/*.php \
                "$PLUGIN_DIR"/mvc/app/models/OPNsense/Xray/*.php; do
        [ -f "$_php" ] || continue
        /usr/local/bin/php -l "$_php" >/dev/null || die "Source PHP syntax check failed: $_php"
    done

    for _xml in "$PLUGIN_DIR"/mvc/app/controllers/OPNsense/Xray/forms/*.xml \
                 "$PLUGIN_DIR"/mvc/app/models/OPNsense/Xray/*.xml \
                 "$PLUGIN_DIR"/mvc/app/models/OPNsense/Xray/ACL/*.xml \
                 "$PLUGIN_DIR"/mvc/app/models/OPNsense/Xray/Menu/*.xml; do
        [ -f "$_xml" ] || continue
        /usr/local/bin/php -r '
            libxml_use_internal_errors(true);
            $f = $argv[1];
            if (simplexml_load_file($f) === false) {
                foreach (libxml_get_errors() as $e) fwrite(STDERR, trim($e->message) . PHP_EOL);
                exit(1);
            }
        ' "$_xml" >/dev/null || die "Source XML parse failed: $_xml"
    done

    /bin/sh -n "$SCRIPT_DIR/install.sh" \
        || die "Installer shell syntax check failed."
    /bin/sh -n "$PLUGIN_DIR/etc/rc.syshook.d/start/50-xray" \
        || die "Source syshook shell syntax check failed."

    for _section in start stop restart reconfigure start_instance stop_instance restart_instance status status_instance statusall xraylog testconnect validate validate_instance ifstats version watchdog log watchdoglog; do
        grep -Fqx "[$_section]" "$PLUGIN_DIR/service/conf/actions.d/actions_xray.conf" \
            || die "Missing configd action section: $_section"
    done

    # Lifecycle regression guards: keeping configd pipes or a
    # flock descriptor across daemonization can make Start/Restart wait forever.
    grep -Fq "/usr/sbin/daemon -f -H" "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Service-control must launch FreeBSD daemon(8) with -f."
    grep -Fq '/usr/bin/timeout -f -k 5s "$CONTROL_TIMEOUT"' "$SCRIPT_DIR/install.sh" \
        || die "Installer control timeout must use -f so daemonized Xray descendants are not reaped/waited for."
    ! grep -Eq '^[[:space:]]*@?flock[[:space:]]*\(' "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Descriptor-based flock lifecycle locks are forbidden."
    grep -Fq "XRAY_LOCK_DIR" "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Atomic lifecycle lock directory implementation is missing."
    _compat_calls=$(grep -Fc 'check_saved_config_compatibility' "$SCRIPT_DIR/install.sh" || true)
    [ "$_compat_calls" -ge 2 ] \
        || die "Saved-config compatibility preflight is defined but not invoked."
    grep -Fq "function xray_read_pidfile" "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Non-destructive pidfile tracking helper is missing."
    ! sed -n '/^function xray_read_pidfile/,/^}/p' "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" | grep -Fq '@unlink(' \
        || die "xray_read_pidfile() must never unlink pidfiles while reading them."
    grep -Fq "Never delete that" "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "daemon(8) pre-exec pidfile race guard is missing."
    grep -Fq "escapeshellarg('xray:' . \$uuid)" "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "UUID-specific Xray daemon supervisor title is missing."
    grep -Fq "escapeshellarg('xray-hev:' . \$uuid)" "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "UUID-specific HEV daemon supervisor title is missing."
    grep -Fq 'function xray_iface_marked' "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Separate non-destructive TUN marker helper is missing."
    grep -Fq 'function hev_process_matches' "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "HEV child tracking helper is missing."
    grep -Fq 'xray_iface_opened_by_pid($iface, $pid)' "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Runtime TUN ownership must use FreeBSD Opened by PID proof."
    grep -Fq 'Opened by PID' "$PLUGIN_DIR/scripts/Xray/xray-ifstats.php" \
        || die "Interface statistics must recognize assigned TUN ownership by tracked PID."
    grep -Fq 'Opened by PID' "$PLUGIN_DIR/scripts/Xray/xray-testconnect.php" \
        || die "Connectivity diagnostics must recognize assigned TUN ownership by tracked PID."
    grep -Fq 'function output(array $data): void' "$PLUGIN_DIR/scripts/Xray/xray-testconnect.php" \
        || die "Connectivity diagnostics must return structured JSON independently of path readiness."
    ! grep -Eq 'output\([^;]*,[[:space:]]*[1-9][0-9]*\);' "$PLUGIN_DIR/scripts/Xray/xray-testconnect.php" \
        || die "Connectivity diagnostics must not turn a normal failed readiness result into configd Execute error."
    grep -Fq "getAttributes()['uuid']" "$PLUGIN_DIR/etc/inc/plugins.inc.d/xray.inc" \
        || die "xray_services() must read ArrayField UUID from the model-node attributes."
    ! grep -Fq "\$node['uuid']" "$PLUGIN_DIR/etc/inc/plugins.inc.d/xray.inc" \
        || die "xray_services() must not use array access on MVC model nodes."

    # GUI/runtime regression guards. The General page does not render the
    # Clients grid, therefore UIBootgrid must never be invoked on an empty selection.
    grep -Fq 'id="reconfigureAct"' "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/general.volt" \
        || die "General Save button id is missing."
    grep -Fq '> {{ lang._('"'"'Save'"'"') }}' "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/general.volt" \
        || die "General Save button must contain visible text."
    grep -Fq 'data-endpoint="/api/xray/service/reconfigure"' "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/general.volt" \
        || die "General Save must use the proven service/reconfigure action."
    grep -Fq '$("#reconfigureAct").SimpleActionButton' "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/scripts.volt" \
        || die "General Save must use SimpleActionButton."
    grep -Fq 'saveFormToEndpoint("/api/xray/general/set"' "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/scripts.volt" \
        || die "General Save persistence endpoint is missing."
    grep -Fq "'frm_general_settings'" "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/scripts.volt" \
        || die "General Save form id is missing."
    grep -Fq 'if ($("#grid-instances").length) {' "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/scripts.volt" \
        || die "Clients UIBootgrid initialization must be guarded when the grid is absent."
    _grid_guard_line=$(grep -nF 'if ($("#grid-instances").length) {' "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/scripts.volt" | head -1 | cut -d: -f1)
    _grid_init_line=$(grep -nF '$("#grid-instances").UIBootgrid({' "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/scripts.volt" | head -1 | cut -d: -f1)
    [ -n "$_grid_guard_line" ] && [ -n "$_grid_init_line" ] && [ "$_grid_guard_line" -lt "$_grid_init_line" ] \
        || die "UIBootgrid guard must precede Clients grid initialization."
    grep -Fq '/usr/local/etc/opnsense-xray' "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Private Xray config path is missing from service-control."
    grep -Fq '/usr/local/share/opnsense-xray' "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Private Xray asset path is missing from service-control."
    ! grep -Fq '/var/log/xray-*.log' "$PLUGIN_DIR/etc/newsyslog.conf.d/xray.conf" \
        || die "Broad newsyslog wildcard would double-match watchdog/service logs."

    # Monitoring/UI regression guards.
    grep -Fq "HEALTH_HOST = 'cp.cloudflare.com'" "$PLUGIN_DIR/scripts/Xray/xray-health.php" \
        || die "End-to-end proxy health probe target is missing."
    grep -Fq "HEALTH_STREAM_BYTES = 16384" "$PLUGIN_DIR/scripts/Xray/xray-health.php" \
        || die "Long-stream health integrity probe is missing."
    grep -Fq "FAILURES_BEFORE_RESTART', 3" "$PLUGIN_DIR/scripts/Xray/xray-watchdog.php" \
        || die "Watchdog consecutive-failure guard is missing."
    grep -Fq "RESTART_COOLDOWN', 600" "$PLUGIN_DIR/scripts/Xray/xray-watchdog.php" \
        || die "Watchdog restart cooldown is missing."
    grep -Fq 'cmd-inst-restart' "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/scripts.volt" \
        || die "Per-client Restart control is missing."
    ! grep -Fq 'Test All' "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/general_status.volt" \
        || die "Obsolete General Test All control was reintroduced."
    grep -Fq '/var/log/xray-service.log' "$PLUGIN_DIR/service/conf/actions.d/actions_xray.conf" \
        || die "Persistent service log action is missing."

    grep -Fq '<gateway_health_sync type="BooleanField">' "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.xml" \
        || die "Gateway health synchronization model field is missing."
    grep -Fq 'force_down' "$PLUGIN_DIR/scripts/Xray/xray-gateway-sync.php" \
        || die "Native gateway Force Down bridge is missing."
    grep -Fq "gs_synthetic_gateway_for_instance" "$PLUGIN_DIR/scripts/Xray/xray-gateway-sync.php" \
        || die "Synthetic Far Gateway derivation is missing."
    grep -Fq "'fargw'=>'1'" "$PLUGIN_DIR/scripts/Xray/xray-gateway-sync.php" \
        || die "Static Far Gateway creation is missing."
    grep -Fq 'rc.routing_configure alarm' "$PLUGIN_DIR/scripts/Xray/xray-gateway-sync.php" \
        || die "Native gateway routing alarm integration is missing."
    grep -Fq 'gateway-health-sync.json' "$PLUGIN_DIR/scripts/Xray/xray-gateway-sync.php" \
        || die "Gateway sync ownership registry is missing."
    grep -Fq "if (\$arg1 === 'release')" "$PLUGIN_DIR/scripts/Xray/xray-gateway-sync.php" \
        || die "Per-client Gateway Health Sync release path is missing."
    grep -Fq 'releaseGatewayHealthOwnership' "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/InstanceController.php" \
        || die "Client deletion must release Gateway Health Sync ownership first."
    grep -Fq 'Missing instances cannot legitimately keep a plugin-managed gateway' "$PLUGIN_DIR/scripts/Xray/xray-gateway-sync.php" \
        || die "Gateway Health Sync orphan reconciliation is missing."

    # XHTTP/transport/allocator/UI guards. Keep these source-level checks so a
    # packaging regression cannot silently drop fields that validate in the GUI
    # but never reach the generated Xray configuration.
    grep -Fq '<xhttp_padding_enabled type="BooleanField">' "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.xml" \
        || die "XHTTP padding model field is missing."
    grep -Fq "'xPaddingBytes'" "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Runtime XHTTP padding generation is missing."
    grep -Fq "'sessionIDPlacement'" "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Runtime XHTTP session placement generation is missing."
    grep -Fq 'setupCollapsedAdvancedSections' "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/scripts.volt" \
        || die "Collapsed Advanced client sections are missing."
    grep -Fq '<vless_flow type="OptionField">' "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.xml" \
        || die "VLESS flow model field is missing."
    grep -Fq "\$user['flow'] = \$flow" "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Runtime VLESS flow generation is missing."
    grep -Fq '<raw>raw</raw>' "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.xml" \
        || die "RAW transport model option is missing."
    grep -Fq '<grpc>grpc</grpc>' "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.xml" \
        || die "gRPC transport model option is missing."
    ! grep -Fq '<websocket>websocket</websocket>' "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.xml" \
        || die "WebSocket must not be offered with REALITY."
    ! grep -Fq '<httpupgrade>httpupgrade</httpupgrade>' "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.xml" \
        || die "HTTPUpgrade must not be offered with REALITY."
    ! grep -Fq '<ws_path ' "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.xml" \
        || die "Dead WebSocket model fields must not be packaged."
    ! grep -Fq '<httpupgrade_path ' "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray/Instance.xml" \
        || die "Dead HTTPUpgrade model fields must not be packaged."
    grep -Fq "'method' => \$transport" "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Runtime transport selector generation is missing."
    grep -Fq "'grpcSettings'" "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Runtime gRPC settings generation is missing."
    grep -Fq 'if ($paddingEnabled && !in_array($paddingPlacement' "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Disabled XHTTP padding must not validate stale placement values."
    grep -Fq "if (\$uplinkMethod !== '') \$settings['uplinkHTTPMethod']" "$PLUGIN_DIR/scripts/Xray/xray-service-control.php" \
        || die "Optional XHTTP uplink method must be omitted when None is selected."
    grep -Fq 'updateTransportFields' "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray/partials/scripts.volt" \
        || die "Transport-specific client UI switching is missing."
    grep -Fq 'runtimeInterfaces(): array' "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/InstanceController.php" \
        || die "Runtime TUN allocator inspection is missing."
    grep -Fq 'runtimeListeningPorts(): array' "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/InstanceController.php" \
        || die "Runtime SOCKS5 allocator inspection is missing."
    grep -Fq 'occupiedIpv4Ranges(): array' "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/InstanceController.php" \
        || die "Runtime TUN address allocator inspection is missing."
    ! grep -Fq '$existingUuid = (string)($node->__reference' "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/InstanceController.php" \
        || die "Allocator must not skip persisted instances based on __reference format."
    grep -Fq '(?:\s+-->\s+\d+\.\d+\.\d+\.\d+)?\s+netmask' "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray/Api/InstanceController.php" \
        || die "FreeBSD point-to-point IPv4 allocator parsing is missing."

    echo "[OK]  Plugin source tree syntax/manifest checks passed"
}

check_external_xray_processes_off() {
    # A separately installed FreeBSD Xray binary may remain on disk, but an
    # independently started process can still race xray for tunN names even
    # when its rc.d service is disabled. Refuse that ambiguous state.
    _psfile=$(mktemp /tmp/xray-external.XXXXXX) || die "mktemp failed while checking external Xray processes"
    /bin/ps -axww -o pid= -o command= > "$_psfile" 2>/dev/null || {
        rm -f "$_psfile"
        die "Could not inspect running processes safely."
    }
    _bad=0
    while IFS= read -r _line; do
        _pid=$(printf '%s\n' "$_line" | awk '{print $1}')
        case "$_pid" in ''|*[!0-9]*) continue ;; esac
        _cmd=${_line#*$_pid}
        _cmd=$(printf '%s' "$_cmd" | sed 's/^[[:space:]]*//')
        # Pad with spaces and require the binary path as a command-line token.
        # This also catches /usr/sbin/daemon ... /usr/local/bin/xray ... .
        case " $_cmd " in
            *" $OLD_XRAY_BIN "*)
                warn "External FreeBSD Xray process is running: PID $_pid: $_cmd"
                _bad=1
                ;;
        esac
    done < "$_psfile"
    rm -f "$_psfile"
    [ "$_bad" = "0" ] || die "Stop the separately managed /usr/local/bin/xray process before installing/upgrading xray."
}

check_system_xray_service_off() {
    # A separately installed FreeBSD xray-core package is allowed and remains
    # untouched, but its rc.d service/process must not race us for generic tunN
    # names.
    if [ -e /usr/local/etc/rc.d/xray ]; then
        _enabled=$(sysrc -n xray_enable 2>/dev/null || echo "NO")
        case "$_enabled" in
            ""|NO|no|No|false|FALSE|0) ;;
            *) die "FreeBSD rc.d xray service is enabled (xray_enable=$_enabled). Disable it before using xray." ;;
        esac
        if service xray onestatus >/dev/null 2>&1; then
            die "FreeBSD rc.d xray service is running. Stop it before using xray. The package itself may remain installed."
        fi
    fi
    check_external_xray_processes_off
}

check_conflicting_artifacts() {
    # Refuse paths from incompatible/manual installations that would make
    # runtime ownership ambiguous.
    [ ! -e /usr/local/bin/xray-core ] || die "Conflicting /usr/local/bin/xray-core exists. Remove or relocate that manual binary first."
    [ ! -e /usr/local/tun2socks ] || die "Conflicting /usr/local/tun2socks exists. Remove the old tun2socks tree first."
    for _p in /var/run/xray_core.pid /var/run/tun2socks.pid /var/run/xray_core_*.pid /var/run/tun2socks_*.pid; do
        [ -e "$_p" ] || continue
        die "Conflicting runtime artifact exists: $_p. Finish cleaning the previous Xray installation first."
    done
}

check_saved_config_compatibility() {
    # Reinstall/upgrade must never mutate an incompatible saved configuration.
    # HEV requires /32 TUN addresses and every local SOCKS listener must be unique.
    [ ! -r /conf/config.xml ] || /usr/local/bin/php -r '
        $x = @simplexml_load_file("/conf/config.xml");
        if ($x === false || !isset($x->OPNsense->xray->instances)) {
            exit(0);
        }
        $rows = $x->OPNsense->xray->instances->instance;
        $count = count($rows);
        foreach ($rows as $row) {
            $addr = trim((string)$row->tun_address);
            if ($addr !== "" && !preg_match("~/32$~D", $addr)) {
                fwrite(STDERR, "Existing Xray configuration uses HEV-incompatible TUN address: {$addr}. The HEV architecture requires /32.\n");
                exit(2);
            }
            $transport = strtolower(trim((string)$row->transport));
            if (in_array($transport, ["websocket", "httpupgrade", "ws"], true)) {
                fwrite(STDERR, "Existing Xray client uses {$transport} with REALITY. Current Xray supports REALITY only with RAW, XHTTP and gRPC; change or remove that client before upgrading.\n");
                exit(4);
            }
        }
        if ($count > 1) {
            $seen = [];
            foreach ($rows as $row) {
                $listen = trim((string)$row->socks5_listen);
                $port = trim((string)$row->socks5_port);
                if ($listen === "") $listen = "127.0.0.1";
                if ($port === "") $port = "10808";
                $key = $listen . ":" . $port;
                if (isset($seen[$key])) {
                    fwrite(STDERR, "Existing Xray instances would collide on SOCKS5 listener {$key}. Assign unique SOCKS5 ports before installing the plugin.\n");
                    exit(3);
                }
                $seen[$key] = true;
            }
        }
    ' || die "Existing Xray configuration is not compatible with this release. Fix or purge the preserved Xray configuration first."
}
valid_uuid() {
    printf '%s\n' "$1" | grep -Eq '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
}

pid_matches_old_or_new_instance() {
    _uuid="$1"
    _pid="$2"
    valid_uuid "$_uuid" || return 1
    case "$_pid" in ''|*[!0-9]*) return 1 ;; esac
    [ "$_pid" -gt 1 ] 2>/dev/null || return 1
    _cmd=$(/bin/ps -ww -o command= -p "$_pid" 2>/dev/null || true)
    [ -n "$_cmd" ] || return 1
    case "$_cmd" in
        *"$XRAY_BIN"*"$XRAY_CONF_DIR/config-$_uuid.json"*) return 0 ;;
        *"$XRAY_BIN"*"$LEGACY_SHARED_CONF_DIR/config-$_uuid.json"*) return 0 ;;
        *"$OLD_XRAY_BIN"*"$OLD_XRAY_CONF_DIR/config-$_uuid.json"*) return 0 ;;
        *) return 1 ;;
    esac
}

scan_managed_processes() {
    # Print PID|UUID|kind for plugin-owned children and supervisors. FreeBSD
    # daemon(8) rewrites the supervisor title after startup, so the live title
    # no longer contains the original config path. Current supervisors carry
    # a UUID-specific title; older native builds are associated through the
    # PPID of a positively identified Xray child.
    _psfile=$(mktemp /tmp/xray-ps.XXXXXX) || return 1
    /bin/ps -axww -o pid= -o ppid= -o command= > "$_psfile" 2>/dev/null || {
        rm -f "$_psfile"
        return 1
    }
    _out=$(mktemp /tmp/xray-psout.XXXXXX) || { rm -f "$_psfile"; return 1; }
    : > "$_out"
    while IFS= read -r _line; do
        _pid=$(printf '%s\n' "$_line" | awk '{print $1}')
        _ppid=$(printf '%s\n' "$_line" | awk '{print $2}')
        case "$_pid:$_ppid" in *[!0-9:]*|:*) continue ;; esac
        _rest=${_line#*$_pid}
        _rest=$(printf '%s' "$_rest" | sed 's/^[[:space:]]*//')
        _cmd=${_rest#*$_ppid}
        _cmd=$(printf '%s' "$_cmd" | sed 's/^[[:space:]]*//')

        # UUID-specific rewritten supervisor title.
        _uuid=$(printf '%s\n' "$_cmd" | sed -nE 's|.*daemon: xray:([0-9a-fA-F-]{36})\[[0-9]+\].*|\1|p')
        if [ -n "$_uuid" ] && valid_uuid "$_uuid"; then
            printf '%s|%s|supervisor\n' "$_pid" "$_uuid" >> "$_out"
            continue
        fi

        _uuid=''
        case "$_cmd" in
            *"$XRAY_CONF_DIR/config-"*.json*)
                _uuid=$(printf '%s\n' "$_cmd" | sed -nE 's|.*'"$XRAY_CONF_DIR"'/config-([0-9a-fA-F-]{36})\.json.*|\1|p')
                ;;
            *"$LEGACY_SHARED_CONF_DIR/config-"*.json*)
                _uuid=$(printf '%s\n' "$_cmd" | sed -nE 's|.*'"$LEGACY_SHARED_CONF_DIR"'/config-([0-9a-fA-F-]{36})\.json.*|\1|p')
                ;;
            *"$OLD_XRAY_CONF_DIR/config-"*.json*)
                _uuid=$(printf '%s\n' "$_cmd" | sed -nE 's|.*'"$OLD_XRAY_CONF_DIR"'/config-([0-9a-fA-F-]{36})\.json.*|\1|p')
                ;;
        esac
        [ -n "$_uuid" ] && valid_uuid "$_uuid" || continue
        case "$_cmd" in
            *"$XRAY_BIN"*|*"$OLD_XRAY_BIN"*) ;;
            *) continue ;;
        esac

        printf '%s|%s|child\n' "$_pid" "$_uuid" >> "$_out"

        # Legacy rewritten supervisor titles did not carry the UUID or
        # config path. Bind such a parent to this UUID only through this
        # positively identified child's PPID.
        if [ "$_ppid" -gt 1 ] 2>/dev/null; then
            _pcmd=$(/bin/ps -ww -o command= -p "$_ppid" 2>/dev/null || true)
            case "$_pcmd" in
                *'daemon:'*"$XRAY_BIN"*|*'daemon:'*"$OLD_XRAY_BIN"*)
                    printf '%s|%s|supervisor\n' "$_ppid" "$_uuid" >> "$_out"
                    ;;
            esac
        fi
    done < "$_psfile"
    sort -u "$_out"
    rm -f "$_psfile" "$_out"
}

assert_managed_processes_tracked() {
    _list=$(mktemp /tmp/xray-procs.XXXXXX) || die "mktemp failed while checking Xray processes"
    scan_managed_processes > "$_list" || { rm -f "$_list"; die "Could not inspect running processes safely."; }
    _bad=0
    while IFS='|' read -r _pid _uuid _kind; do
        [ -n "$_pid" ] || continue
        if [ "$_kind" = "supervisor" ]; then
            _pf="/var/run/xray-daemon-${_uuid}.pid"
        else
            _pf="/var/run/xray-${_uuid}.pid"
        fi
        _tracked=$(cat "$_pf" 2>/dev/null || true)
        if [ "$_tracked" != "$_pid" ]; then
            warn "Untracked plugin-owned Xray $_kind detected: UUID $_uuid PID $_pid (expected pidfile $_pf)."
            _bad=1
        fi
    done < "$_list"
    rm -f "$_list"
    [ "$_bad" = "0" ] || die "Refusing to mutate Xray while an untracked plugin-owned Xray process is alive. Stop it manually first."
}

assert_no_managed_processes() {
    _list=$(mktemp /tmp/xray-procs.XXXXXX) || die "mktemp failed while checking Xray processes"
    scan_managed_processes > "$_list" || { rm -f "$_list"; die "Could not inspect running processes safely."; }
    if [ -s "$_list" ]; then
        while IFS='|' read -r _pid _uuid _kind; do
            [ -n "$_pid" ] || continue
            warn "Plugin-owned Xray $_kind is still alive: UUID $_uuid PID $_pid."
        done < "$_list"
        rm -f "$_list"
        die "Refusing to remove Xray while plugin-owned Xray runtime is still alive."
    fi
    rm -f "$_list"
    _hev=$(/bin/ps -axww -o pid= -o command= 2>/dev/null | grep -F "$HEV_BIN" | grep -v '[g]rep' || true)
    [ -z "$_hev" ] || {
        printf '%s\n' "$_hev" >&2
        die "Refusing to mutate Xray while a plugin-owned HEV process is still alive."
    }
}

assert_no_service_controls() {
    _tries=0
    while [ "$_tries" -lt 30 ]; do
        _found=$(/bin/ps -axww -o pid= -o command= 2>/dev/null | \
            awk '/[x]ray-service-control\.php/ {print $1 "|" substr($0, index($0,$2))}')
        [ -z "$_found" ] && return 0
        _tries=$((_tries + 1))
        sleep 0.1
    done
    printf '%s
' "$_found" | while IFS='|' read -r _pid _cmd; do
        [ -n "$_pid" ] || continue
        warn "Lingering Xray service-control process: PID $_pid: $_cmd"
    done
    die "Refusing to mutate Xray while a previous service-control action is still alive."
}

scan_managed_ifaces() {
    # Print IFACE|UUID for tun(4) devices explicitly marked by xray.
    # The description is our ownership proof; unmarked generic tunN devices are
    # never considered ours and are never touched by the installer.
    _iflist=$(/sbin/ifconfig -l 2>/dev/null) || return 1
    for _iface in $_iflist; do
        printf '%s
' "$_iface" | grep -Eq '^tun(0|[1-9][0-9]{0,2})$' || continue
        _ifout=$(/sbin/ifconfig "$_iface" 2>/dev/null) || continue
        _uuid=$(printf '%s
' "$_ifout" | sed -nE 's/^[[:space:]]*description:[[:space:]]+xray:([0-9a-fA-F-]{36})[[:space:]]*$/\1/p' | head -n 1)
        [ -n "$_uuid" ] && valid_uuid "$_uuid" || continue
        printf '%s|%s
' "$_iface" "$_uuid"
    done
}

assert_no_managed_ifaces() {
    _list=$(mktemp /tmp/xray-ifaces.XXXXXX) || die "mktemp failed while checking Xray TUN interfaces"
    scan_managed_ifaces > "$_list" || { rm -f "$_list"; die "Could not inspect TUN interfaces safely."; }
    if [ -s "$_list" ]; then
        while IFS='|' read -r _iface _uuid; do
            [ -n "$_iface" ] || continue
            warn "Plugin-owned TUN is still present: $_iface (UUID $_uuid)."
        done < "$_list"
        rm -f "$_list"
        die "Refusing to mutate/remove Xray while a plugin-owned TUN interface is still present. Stop it cleanly first."
    fi
    rm -f "$_list"
}

assert_no_configured_assignments() {
    # Removing xray.inc while a configured tunN is still assigned would leave
    # config.xml referencing a volatile device provider that no longer exists.
    # Refuse that state instead of risking interface-mismatch handling at boot.
    _out=$(/usr/local/bin/php -r '
        set_include_path("/usr/local/etc/inc" . PATH_SEPARATOR . get_include_path());
        require_once("config.inc");
        $cfg = OPNsense\Core\Config::getInstance()->object();
        $wanted = [];
        $instances = $cfg->OPNsense->xray->instances ?? null;
        if ($instances) {
            foreach ($instances->instance as $i) {
                $tun = (string)($i->tun_interface ?? "");
                if (preg_match("/^tun(?:0|[1-9][0-9]{0,2})$/D", $tun)) {
                    $wanted[$tun] = true;
                }
            }
        }
        if (!isset($cfg->interfaces)) exit(0);
        foreach ($cfg->interfaces->children() as $key => $ifcfg) {
            $dev = (string)($ifcfg->if ?? "");
            if (isset($wanted[$dev])) {
                $descr = trim((string)($ifcfg->descr ?? ""));
                printf("%s|%s|%s\n", $dev, (string)$key, $descr);
            }
        }
    ' 2>/dev/null) || die "Could not inspect OPNsense interface assignments safely."
    [ -z "$_out" ] || {
        printf '%s\n' "$_out" | while IFS='|' read -r _dev _key _descr; do
            [ -n "$_dev" ] || continue
            [ -n "$_descr" ] || _descr="$_key"
            warn "Configured Xray device $_dev is still assigned as OPNsense interface $_key ($_descr)."
        done
        die "Unassign every Xray tunN under Interfaces -> Assignments before uninstalling xray."
    }
}

instance_tracked_running() {
    _uuid="$1"
    valid_uuid "$_uuid" || return 1
    _pf="/var/run/xray-${_uuid}.pid"
    _pid=$(cat "$_pf" 2>/dev/null || true)
    pid_matches_old_or_new_instance "$_uuid" "$_pid"
}

wait_instance_tracked_running() {
    _uuid="$1"
    _tries=0
    while [ "$_tries" -lt 50 ]; do
        instance_tracked_running "$_uuid" && return 0
        _tries=$((_tries + 1))
        sleep 0.1
    done
    return 1
}

capture_running_instances() {
    : > "$STATE_DIR/running.instances"
    for _pf in /var/run/xray-????????-????-????-????-????????????.pid; do
        [ -f "$_pf" ] || continue
        _base=$(basename "$_pf")
        _uuid=${_base#xray-}
        _uuid=${_uuid%.pid}
        valid_uuid "$_uuid" || continue
        _pid=$(cat "$_pf" 2>/dev/null || true)
        if pid_matches_old_or_new_instance "$_uuid" "$_pid"; then
            printf '%s\n' "$_uuid" >> "$STATE_DIR/running.instances"
        fi
    done
    sort -u "$STATE_DIR/running.instances" -o "$STATE_DIR/running.instances"
}

stop_captured_instances() {
    [ -s "$STATE_DIR/running.instances" ] || return 0
    [ -f "$SERVICE_CONTROL" ] || die "Installed service-control is missing; cannot stop running instances safely."
    echo "==> Stopping running Xray instances for transactional upgrade..."
    RUNTIME_STOP_ATTEMPTED=1
    # From this point rollback must restore the complete pre-upgrade running
    # set even if stopping a later UUID fails midway.
    RUNTIME_STOPPED_FOR_UPGRADE=1
    # Use the installed controller for teardown because it knows the runtime
    # paths of the version that is actually running.
    _upgrade_control="$SERVICE_CONTROL"
    while IFS= read -r _uuid; do
        [ -n "$_uuid" ] || continue
        run_control_bounded "$_upgrade_control" stop_instance "$_uuid" || die "Could not stop Xray instance $_uuid safely (controller busy/timeout/failure)."
        _pf="/var/run/xray-${_uuid}.pid"
        if [ -f "$_pf" ]; then
            _pid=$(cat "$_pf" 2>/dev/null || true)
            pid_matches_old_or_new_instance "$_uuid" "$_pid" && die "Instance $_uuid is still running after stop."
        fi
    done < "$STATE_DIR/running.instances"
}

restart_captured_instances_with() {
    _control="$1"
    [ "$RUNTIME_STOPPED_FOR_UPGRADE" = "1" ] || return 0
    [ -s "$STATE_DIR/running.instances" ] || return 0
    [ -x "$_control" ] || return 1
    _ok=0
    while IFS= read -r _uuid; do
        [ -n "$_uuid" ] || continue
        _out=$(mktemp /tmp/xray-restore.XXXXXX) || return 1
        _rc=0
        run_control_bounded "$_control" start_instance "$_uuid" >"$_out" 2>&1 || _rc=$?
        cat "$_out"

        # Successful restoration must leave the exact UUID tracked by its child
        # pidfile and matching Xray command. Do not trust only wrapper status:
        # timeout(1) without -f becomes a reaper and can return 124 after the
        # controller has already daemonized a healthy long-lived Xray child.
        if [ "$_rc" -eq 0 ] && wait_instance_tracked_running "$_uuid"; then
            rm -f "$_out"
            continue
        fi

        if [ "$_rc" -ne 0 ] \
            && ! grep -Eqi '(^|[[:space:]])ERROR:' "$_out" \
            && grep -Fq "OK: Xray [$_uuid] running on " "$_out" \
            && wait_instance_tracked_running "$_uuid"; then
            warn "Controller wrapper returned rc=$_rc for $_uuid, but the exact Xray runtime is verified running; accepting restored state."
            rm -f "$_out"
            continue
        fi

        warn "Could not restore Xray instance $_uuid (controller rc=$_rc or runtime verification failed)."
        rm -f "$_out"
        _ok=1
    done < "$STATE_DIR/running.instances"
    return "$_ok"
}

snapshot_file() {
    _src="$1"; _name="$2"
    if [ -f "$_src" ]; then
        cp -p "$_src" "$STATE_DIR/$_name"
        echo present > "$STATE_DIR/$_name.state"
    else
        echo absent > "$STATE_DIR/$_name.state"
    fi
}

file_matches_snapshot() {
    _src="$1"; _name="$2"
    _state=$(cat "$STATE_DIR/$_name.state" 2>/dev/null || echo unknown)
    case "$_state" in
        present) [ -f "$_src" ] && cmp -s "$_src" "$STATE_DIR/$_name" ;;
        absent)  [ ! -e "$_src" ] ;;
        *) return 1 ;;
    esac
}

backup_path() {
    _src="$1"; _dst="$ROLLBACK_DIR/${_src#/}"
    if [ -e "$_src" ]; then
        mkdir -p "$(dirname "$_dst")"
        cp -Rp "$_src" "$_dst"
    fi
}

restore_path() {
    _dst="$1"; _src="$ROLLBACK_DIR/${_dst#/}"
    rm -rf "$_dst" || return 1
    if [ -e "$_src" ]; then
        mkdir -p "$(dirname "$_dst")" || return 1
        cp -Rp "$_src" "$_dst" || return 1
    fi
    return 0
}

prepare_rollback() {
    echo "==> Creating rollback snapshot..."
    backup_path /usr/local/opnsense/scripts/Xray
    backup_path /usr/local/opnsense/service/conf/actions.d/actions_xray.conf
    backup_path /usr/local/opnsense/mvc/app/models/OPNsense/Xray
    backup_path /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray
    backup_path /usr/local/opnsense/mvc/app/views/OPNsense/Xray
    backup_path /usr/local/etc/inc/plugins.inc.d/xray.inc
    backup_path /usr/local/etc/rc.syshook.d/start/50-xray
    backup_path /etc/newsyslog.conf.d/xray.conf
    backup_path "$XRAY_HOME"
    backup_path "$XRAY_ASSET_DIR"
    backup_path "$XRAY_CONF_DIR"
}

rollback_install() {
    warn "Installation did not commit; restoring previous Xray state."

    # Only the final restore-of-runtime step can have started NEW processes.
    # If that step was attempted, stop exactly the UUIDs that were running
    # before the upgrade. Do not issue a global stop here: that would create
    # manual-stop flags for unrelated instances and mutate rollback state.
    if [ "$NEW_RUNTIME_RESTART_ATTEMPTED" = "1" ] && [ -x "$SERVICE_CONTROL" ] && [ -s "$STATE_DIR/running.instances" ]; then
        while IFS= read -r _uuid; do
            [ -n "$_uuid" ] || continue
            run_control_bounded "$SERVICE_CONTROL" stop_instance "$_uuid" >/dev/null 2>&1 || true
        done < "$STATE_DIR/running.instances"
    fi
    _live=$(mktemp /tmp/xray-rollback-procs.XXXXXX) || return 1
    if ! scan_managed_processes > "$_live"; then
        rm -f "$_live"
        warn "Rollback could not inspect live Xray processes."
        return 1
    fi
    if [ -s "$_live" ]; then
        while IFS='|' read -r _pid _uuid _kind; do
            [ -n "$_pid" ] || continue
            warn "Rollback blocked by live Xray $_kind: UUID $_uuid PID $_pid."
        done < "$_live"
        rm -f "$_live"
        return 1
    fi
    rm -f "$_live"
    _restore_failed=0
    for _path in \
        /usr/local/opnsense/scripts/Xray \
        /usr/local/opnsense/service/conf/actions.d/actions_xray.conf \
        /usr/local/opnsense/mvc/app/models/OPNsense/Xray \
        /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray \
        /usr/local/opnsense/mvc/app/views/OPNsense/Xray \
        /usr/local/etc/inc/plugins.inc.d/xray.inc \
        /usr/local/etc/rc.syshook.d/start/50-xray \
        /etc/newsyslog.conf.d/xray.conf \
        "$XRAY_HOME" \
        "$XRAY_ASSET_DIR" \
        "$XRAY_CONF_DIR"; do
        if ! restore_path "$_path"; then
            warn "Rollback failed while restoring $_path."
            _restore_failed=1
        fi
    done
    if [ "$_restore_failed" = "1" ]; then
        warn "Rollback file restore was incomplete; configd/runtime restore was not attempted."
        return 1
    fi
    rm -f /var/lib/php/tmp/opnsense_menu_cache.xml 2>/dev/null || true
    service configd restart >/dev/null 2>&1 || {
        warn "Rollback restored files but configd restart failed."
        return 1
    }
    if ! restart_captured_instances_with /usr/local/opnsense/scripts/Xray/xray-service-control.php; then
        warn "Rollback restored files but one or more previously running instances could not be restarted."
        return 1
    fi
    return 0
}

installer_cleanup() {
    _rc=$?
    set +e
    if [ "$INSTALL_COMMITTED" != "1" ]; then
        if [ "$INSTALL_MUTATION_STARTED" = "1" ]; then
            if ! rollback_install; then
                ROLLBACK_FAILED=1
            fi
        elif [ "$RUNTIME_STOP_ATTEMPTED" = "1" ]; then
            # No plugin files were replaced yet. Restore only the exact runtime
            # set that was running before the attempted upgrade.
            if ! restart_captured_instances_with "$SERVICE_CONTROL"; then
                warn "Could not fully restore the pre-upgrade Xray runtime after an early installer failure."
                ROLLBACK_FAILED=1
            fi
        fi
    fi
    if [ "$ROLLBACK_FAILED" = "1" ]; then
        warn "CRITICAL: rollback was incomplete. Recovery snapshot preserved at: $ROLLBACK_DIR"
    else
        rm -rf "$ROLLBACK_DIR" 2>/dev/null || true
    fi
    rm -rf "$STATE_DIR" 2>/dev/null || true
    trap - EXIT HUP INT TERM
    exit "$_rc"
}

extract_dgst_sha256() {
    _file="$1"
    # Upstream Xray release digests are produced with OpenSSL.
    # OpenSSL 3 labels SHA-256 as "SHA2-256", while older versions may
    # use "SHA256".  Do not depend on the label; the .dgst asset contains
    # MD5/SHA1/SHA256/SHA512 on separate lines, so the SHA-256 value is
    # the hexadecimal field whose length is exactly 64 characters.
    awk '{
        for (i = 1; i <= NF; i++) {
            if ($i ~ /^[0123456789abcdefABCDEF]+$/ && length($i) == 64) {
                print tolower($i)
                exit
            }
        }
    }' "$_file" 2>/dev/null
}

stage_upstream_xray() {
    XRAY_STAGE_DIR="$STATE_DIR/upstream"
    mkdir -p "$XRAY_STAGE_DIR"

    if [ "$NEED_XRAY" = "1" ]; then
        echo "==> Downloading Xray v$XRAY_VERSION..."
        XRAY_ZIP="$STATE_DIR/$XRAY_ARCHIVE"
        XRAY_DGST="$STATE_DIR/$XRAY_ARCHIVE_DGST"
        fetch -q -T 60 -o "$XRAY_ZIP" "$XRAY_URL" || die "Could not download $XRAY_URL"
        fetch -q -T 60 -o "$XRAY_DGST" "$XRAY_DGST_URL" || die "Could not download $XRAY_DGST_URL"
        [ -s "$XRAY_ZIP" ] || die "Downloaded Xray archive is empty."
        [ -s "$XRAY_DGST" ] || die "Downloaded Xray digest file is empty."

        _actual=$(sha256 -q "$XRAY_ZIP" | tr 'A-F' 'a-f')
        [ "$_actual" = "$XRAY_ARCHIVE_SHA256" ] || die "Xray archive SHA256 mismatch: expected $XRAY_ARCHIVE_SHA256, got $_actual"
        _dgst_actual=$(sha256 -q "$XRAY_DGST" | tr 'A-F' 'a-f')
        [ "$_dgst_actual" = "$XRAY_DGST_SHA256" ] || die "Xray digest asset SHA256 mismatch: expected $XRAY_DGST_SHA256, got $_dgst_actual"
        _upstream=$(extract_dgst_sha256 "$XRAY_DGST")
        [ -n "$_upstream" ] || die "Could not parse SHA256 from upstream $XRAY_ARCHIVE_DGST"
        [ "$_upstream" = "$XRAY_ARCHIVE_SHA256" ] || die "Upstream .dgst SHA256 disagrees with GitHub release metadata."

        tar -tf "$XRAY_ZIP" > "$STATE_DIR/archive.list" || die "Xray release archive is not readable."
        grep -qx 'xray' "$STATE_DIR/archive.list" || die "Xray release archive has no root xray binary."
        grep -qx 'geoip.dat' "$STATE_DIR/archive.list" || die "Xray release archive has no geoip.dat."
        grep -qx 'geosite.dat' "$STATE_DIR/archive.list" || die "Xray release archive has no geosite.dat."
        if grep -Eq '(^/|(^|/)\.\.(/|$))' "$STATE_DIR/archive.list"; then die "Unsafe path detected inside Xray release archive."; fi

        tar -xOf "$XRAY_ZIP" xray > "$XRAY_STAGE_DIR/xray" || die "Could not extract Xray binary."
        tar -xOf "$XRAY_ZIP" geoip.dat > "$XRAY_STAGE_DIR/geoip.dat" || die "Could not extract geoip.dat."
        tar -xOf "$XRAY_ZIP" geosite.dat > "$XRAY_STAGE_DIR/geosite.dat" || die "Could not extract geosite.dat."
        chmod 0755 "$XRAY_STAGE_DIR/xray"
        chmod 0644 "$XRAY_STAGE_DIR/geoip.dat" "$XRAY_STAGE_DIR/geosite.dat"

        XRAY_BINARY_SHA256=$(sha256 -q "$XRAY_STAGE_DIR/xray" | tr 'A-F' 'a-f')
        XRAY_GEOIP_SHA256=$(sha256 -q "$XRAY_STAGE_DIR/geoip.dat" | tr 'A-F' 'a-f')
        XRAY_GEOSITE_SHA256=$(sha256 -q "$XRAY_STAGE_DIR/geosite.dat" | tr 'A-F' 'a-f')

        _line=$("$XRAY_STAGE_DIR/xray" version 2>/dev/null | head -n 1 || true)
        printf '%s\n' "$_line" | grep -Fq "Xray $XRAY_VERSION" || die "Downloaded Xray binary reports unexpected version: $_line"
    else
        echo "==> Reusing verified installed Xray v$XRAY_VERSION."
    fi

    if [ "$NEED_HEV" = "1" ]; then
        echo "==> Downloading HEV $HEV_VERSION..."
        HEV_STAGE="$XRAY_STAGE_DIR/hev-socks5-tunnel"
        fetch -q -T 60 -o "$HEV_STAGE" "$HEV_URL" || die "Could not download $HEV_URL"
        [ -s "$HEV_STAGE" ] || die "Downloaded HEV binary is empty."
        _hev_actual=$(sha256 -q "$HEV_STAGE" | tr 'A-F' 'a-f')
        [ "$_hev_actual" = "$HEV_SHA256" ] || die "HEV SHA256 mismatch: expected $HEV_SHA256, got $_hev_actual"
        chmod 0755 "$HEV_STAGE"

        _hev_probe_out="$STATE_DIR/hev-probe.out"
        _hev_probe_rc=0
        "$HEV_STAGE" "$STATE_DIR/does-not-exist-hev.yaml" >"$_hev_probe_out" 2>&1 || _hev_probe_rc=$?
        case "$_hev_probe_rc" in
            126|127)
                cat "$_hev_probe_out" >&2 || true
                die "HEV binary cannot execute on this FreeBSD userland (rc=$_hev_probe_rc)."
                ;;
        esac
        if grep -Eqi 'exec format error|unsupported.*elf|shared object .* not found|cannot open shared object|ld-elf.*not found' "$_hev_probe_out"; then
            cat "$_hev_probe_out" >&2 || true
            die "HEV binary failed the FreeBSD loader/ABI probe."
        fi
    else
        echo "==> Reusing verified installed HEV $HEV_VERSION."
    fi

    cat > "$STATE_DIR/socks-test.json" <<'EOF'
{
  "log": {"loglevel": "none"},
  "inbounds": [{"listen":"127.0.0.1","port":10808,"protocol":"socks","settings":{"auth":"noauth","udp":true,"ip":"127.0.0.1"}}],
  "outbounds": [{"tag":"direct","protocol":"freedom"}]
}
EOF
    _test_xray="$XRAY_BIN"
    _test_assets="$XRAY_ASSET_DIR"
    if [ "$NEED_XRAY" = "1" ]; then
        _test_xray="$XRAY_STAGE_DIR/xray"
        _test_assets="$XRAY_STAGE_DIR"
    fi
    XRAY_LOCATION_ASSET="$_test_assets" "$_test_xray" run -test -c "$STATE_DIR/socks-test.json" >/dev/null 2>&1 \
        || die "Xray SOCKS5 config validation failed."

    echo "[OK]  Xray v$XRAY_VERSION release metadata and runtime validation passed"
    echo "[OK]  HEV $HEV_VERSION release metadata/runtime validation passed"
}

install_upstream_xray() {
    echo "==> Synchronizing plugin-owned upstream components..."
    install -d -o root -g wheel -m 0755 "$XRAY_HOME"
    install -d -o root -g wheel -m 0755 "$XRAY_ASSET_DIR"
    install -d -o root -g wheel -m 0750 "$XRAY_CONF_DIR"

    if [ "$NEED_XRAY" = "1" ]; then
        install -o root -g wheel -m 0755 "$XRAY_STAGE_DIR/xray" "$XRAY_BIN"
        install -o root -g wheel -m 0644 "$XRAY_STAGE_DIR/geoip.dat" "$XRAY_ASSET_DIR/geoip.dat"
        install -o root -g wheel -m 0644 "$XRAY_STAGE_DIR/geosite.dat" "$XRAY_ASSET_DIR/geosite.dat"
        cat > "$XRAY_UPSTREAM_INFO" <<EOF
version=$XRAY_VERSION
asset=$XRAY_ARCHIVE
archive_sha256=$XRAY_ARCHIVE_SHA256
dgst_sha256=$XRAY_DGST_SHA256
binary_sha256=$XRAY_BINARY_SHA256
geoip_sha256=$XRAY_GEOIP_SHA256
geosite_sha256=$XRAY_GEOSITE_SHA256
source=$XRAY_URL
channel=$XRAY_CHANNEL
EOF
        chown root:wheel "$XRAY_UPSTREAM_INFO"
        chmod 0644 "$XRAY_UPSTREAM_INFO"
    fi

    if [ "$NEED_HEV" = "1" ]; then
        install -o root -g wheel -m 0555 "$XRAY_STAGE_DIR/hev-socks5-tunnel" "$HEV_BIN"
        cat > "$HEV_UPSTREAM_INFO" <<EOF
version=$HEV_VERSION
asset=$HEV_ASSET
sha256=$HEV_SHA256
source=$HEV_URL
channel=$HEV_CHANNEL
EOF
        chown root:wheel "$HEV_UPSTREAM_INFO"
        chmod 0644 "$HEV_UPSTREAM_INFO"
    fi
}

verify_installed_upstream() {
    [ -x "$XRAY_BIN" ] || die "Plugin-owned Xray binary missing: $XRAY_BIN"
    [ -x "$HEV_BIN" ] || die "Plugin-owned HEV binary missing: $HEV_BIN"
    [ -r "$XRAY_ASSET_DIR/geoip.dat" ] || die "Missing Xray asset: $XRAY_ASSET_DIR/geoip.dat"
    [ -r "$XRAY_ASSET_DIR/geosite.dat" ] || die "Missing Xray asset: $XRAY_ASSET_DIR/geosite.dat"
    [ -r "$XRAY_UPSTREAM_INFO" ] || die "Missing Xray upstream metadata."
    [ -r "$HEV_UPSTREAM_INFO" ] || die "Missing HEV upstream metadata."

    _line=$("$XRAY_BIN" version 2>/dev/null | head -n 1 || true)
    printf '%s\n' "$_line" | grep -Fq "Xray $XRAY_VERSION" || die "Installed Xray reports unexpected version: $_line"
    [ "$(sha256 -q "$XRAY_BIN" | tr 'A-F' 'a-f')" = "$XRAY_BINARY_SHA256" ] || die "Installed Xray binary SHA256 mismatch."
    [ "$(sha256 -q "$XRAY_ASSET_DIR/geoip.dat" | tr 'A-F' 'a-f')" = "$XRAY_GEOIP_SHA256" ] || die "Installed geoip.dat SHA256 mismatch."
    [ "$(sha256 -q "$XRAY_ASSET_DIR/geosite.dat" | tr 'A-F' 'a-f')" = "$XRAY_GEOSITE_SHA256" ] || die "Installed geosite.dat SHA256 mismatch."
    [ "$(sha256 -q "$HEV_BIN" | tr 'A-F' 'a-f')" = "$HEV_SHA256" ] || die "Installed HEV SHA256 mismatch."
    [ "$(metadata_value "$XRAY_UPSTREAM_INFO" version 2>/dev/null || true)" = "$XRAY_VERSION" ] || die "Installed Xray metadata version mismatch."
    [ "$(metadata_value "$HEV_UPSTREAM_INFO" version 2>/dev/null || true)" = "$HEV_VERSION" ] || die "Installed HEV metadata version mismatch."
    check_system_xray_service_off
}

install_tree() {
    _src="$1"; _dst="$2"; _mode="$3"
    [ -d "$_src" ] || return 0
    find "$_src" -type d | while IFS= read -r _dir; do
        _rel=${_dir#"$_src"}
        install -d -o root -g wheel -m 0755 "$_dst$_rel"
    done
    find "$_src" -type f | while IFS= read -r _file; do
        _rel=${_file#"$_src"}
        install -o root -g wheel -m "$_mode" "$_file" "$_dst$_rel"
    done
}

install_plugin_files() {
    echo "==> Installing Xray plugin files..."
    # Replace plugin-owned trees, do not overlay them. Otherwise files removed
    # from a newer source tree could survive an upgrade and remain executable.
    rm -rf /usr/local/opnsense/scripts/Xray \
           /usr/local/opnsense/mvc/app/models/OPNsense/Xray \
           /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray \
           /usr/local/opnsense/mvc/app/views/OPNsense/Xray
    rm -f /usr/local/opnsense/service/conf/actions.d/actions_xray.conf \
          /usr/local/etc/inc/plugins.inc.d/xray.inc \
          /usr/local/etc/rc.syshook.d/start/50-xray \
          /etc/newsyslog.conf.d/xray.conf

    install -d -o root -g wheel -m 0755 /usr/local/opnsense/scripts/Xray
    install_tree "$PLUGIN_DIR/scripts/Xray" /usr/local/opnsense/scripts/Xray 0755
    install -d -o root -g wheel -m 0755 /usr/local/opnsense/service/conf/actions.d
    install -o root -g wheel -m 0644 "$PLUGIN_DIR/service/conf/actions.d/actions_xray.conf" /usr/local/opnsense/service/conf/actions.d/actions_xray.conf
    install -d -o root -g wheel -m 0755 /usr/local/opnsense/mvc/app/models/OPNsense/Xray
    install_tree "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray" /usr/local/opnsense/mvc/app/models/OPNsense/Xray 0644
    install -d -o root -g wheel -m 0755 /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray
    install_tree "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray" /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray 0644
    install -d -o root -g wheel -m 0755 /usr/local/opnsense/mvc/app/views/OPNsense/Xray
    install_tree "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray" /usr/local/opnsense/mvc/app/views/OPNsense/Xray 0644
    install -d -o root -g wheel -m 0755 /usr/local/etc/inc/plugins.inc.d
    install -o root -g wheel -m 0644 "$PLUGIN_DIR/etc/inc/plugins.inc.d/xray.inc" /usr/local/etc/inc/plugins.inc.d/xray.inc
    install -d -o root -g wheel -m 0755 /usr/local/etc/rc.syshook.d/start
    install -o root -g wheel -m 0755 "$PLUGIN_DIR/etc/rc.syshook.d/start/50-xray" /usr/local/etc/rc.syshook.d/start/50-xray
    install -d -o root -g wheel -m 0755 /etc/newsyslog.conf.d
    install -o root -g wheel -m 0644 "$PLUGIN_DIR/etc/newsyslog.conf.d/xray.conf" /etc/newsyslog.conf.d/xray.conf
    touch /var/log/xray-service.log /var/log/xray-watchdog.log
    chown root:wheel /var/log/xray-service.log /var/log/xray-watchdog.log
    chmod 0640 /var/log/xray-service.log /var/log/xray-watchdog.log
    printf '%s\n' "$PLUGIN_VERSION" > "$VERSION_FILE"
    chown root:wheel "$VERSION_FILE"
    chmod 0644 "$VERSION_FILE"

    # Clear runtime lock state only after managed runtime has been stopped.
    rm -f /var/run/xray.lock /var/run/xray-gateway-sync.lock 2>/dev/null || true
    rm -f /var/run/xray.lock.d/owner 2>/dev/null || true
    rmdir /var/run/xray.lock.d 2>/dev/null || true
    rm -f /var/lib/php/tmp/opnsense_menu_cache.xml 2>/dev/null || true
}

verify_plugin_install() {
    echo "==> Verifying installed plugin..."
    service configd restart >/dev/null 2>&1 || die "configd restart failed; installation will be rolled back."

    for _php in /usr/local/opnsense/scripts/Xray/*.php \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/Api/*.php \
                /usr/local/opnsense/mvc/app/models/OPNsense/Xray/*.php; do
        [ -f "$_php" ] || continue
        /usr/local/bin/php -l "$_php" >/dev/null || die "Installed PHP syntax check failed: $_php"
    done

    _version_json=$(/usr/local/sbin/configctl xray version 2>/dev/null || true)
    printf '%s\n' "$_version_json" | grep -Fq '"version":"'"$PLUGIN_VERSION"'"' \
        || die "Installed configd action/version check failed: $_version_json"
    printf '%s\n' "$_version_json" | grep -Fq "$XRAY_VERSION" \
        || die "Installed backend does not report Xray v$XRAY_VERSION: $_version_json"
    printf '%s\n' "$_version_json" | grep -Fq "$HEV_VERSION" \
        || die "Installed backend does not report HEV $HEV_VERSION: $_version_json"

    _validate="$STATE_DIR/validate.out"
    _vrc=0
    /usr/local/sbin/configctl xray validate >"$_validate" 2>&1 || _vrc=$?
    if [ "$_vrc" -ne 0 ] && ! grep -Fq 'no instance found to validate' "$_validate"; then
        cat "$_validate" >&2
        die "Installed backend validation failed."
    fi

    verify_installed_upstream
}

verify_repo_files_untouched() {
    file_matches_snapshot /usr/local/etc/pkg/repos/OPNsense.conf OPNsense.conf \
        || die "OPNsense.conf changed during installation even though this installer does not use pkg repositories."
    file_matches_snapshot /usr/local/etc/pkg/repos/FreeBSD.conf FreeBSD.conf \
        || die "FreeBSD.conf changed during installation even though this installer does not use pkg repositories."
}

uninstall_plugin() {
    require_root_opnsense
    _installed_version="not installed"
    [ ! -f "$VERSION_FILE" ] || _installed_version=$(cat "$VERSION_FILE" 2>/dev/null || echo "unknown")
    check_system_xray_service_off

    echo "==> Releasing native gateway health state..."
    _gateway_sync="/usr/local/opnsense/scripts/Xray/xray-gateway-sync.php"
    [ -f "$_gateway_sync" ] || _gateway_sync="$PLUGIN_DIR/scripts/Xray/xray-gateway-sync.php"
    if [ -f "$_gateway_sync" ] && [ -f /usr/local/etc/opnsense-xray/gateway-health-sync.json ]; then
        _gs_rc=0
        _gs_out=$(/usr/local/bin/php "$_gateway_sync" release_all 2>&1) || _gs_rc=$?
        printf '%s\n' "$_gs_out"
        [ "$_gs_rc" -eq 0 ] || die "Could not restore native gateway Force Down state safely; refusing to uninstall."
    fi

    assert_no_configured_assignments

    echo "==> Stopping Xray+HEV instances..."
    _uninstall_control="$SERVICE_CONTROL"
    [ -f "$_uninstall_control" ] || _uninstall_control="$PLUGIN_DIR/scripts/Xray/xray-service-control.php"
    if [ -f "$_uninstall_control" ]; then
        _stop_rc=0
        # Prefer the installed controller because it knows the actual runtime
        # paths of the installed build. Fall back to this source tree only when
        # the installed controller is already missing.
        _out=$(run_control_bounded "$_uninstall_control" stop 2>&1) || _stop_rc=$?
        printf '%s\n' "$_out"
        if [ "$_stop_rc" -ne 0 ] || printf '%s\n' "$_out" | grep -Eqi '(^|[[:space:]])ERROR:'; then
            die "Xray teardown was not confirmed. Refusing to uninstall while plugin-owned runtime may still be live."
        fi
    fi

    assert_no_managed_processes
    assert_no_managed_ifaces
    assert_no_service_controls

    PURGE_CONFIG=0
    if ask_yes_no "Purge saved Xray configuration from config.xml too?" n; then
        PURGE_CONFIG=1
        /usr/local/bin/php -r '
            set_include_path("/usr/local/etc/inc" . PATH_SEPARATOR . get_include_path());
            require_once("config.inc");
            $cfg = OPNsense\Core\Config::getInstance();
            $obj = $cfg->object();
            if (isset($obj->OPNsense->xray)) {
                unset($obj->OPNsense->xray);
                $cfg->save(["description" => "Purge Xray configuration"]);
            }
        ' || die "Could not purge Xray configuration from config.xml. Plugin files were not removed."
    fi

    echo "==> Removing plugin files and plugin-owned upstream Xray..."
    rm -rf /usr/local/opnsense/scripts/Xray
    rm -f  /usr/local/opnsense/service/conf/actions.d/actions_xray.conf
    rm -rf /usr/local/opnsense/mvc/app/models/OPNsense/Xray
    rm -rf /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray
    rm -rf /usr/local/opnsense/mvc/app/views/OPNsense/Xray
    rm -f  /usr/local/etc/inc/plugins.inc.d/xray.inc
    rm -f  /usr/local/etc/rc.syshook.d/start/50-xray
    rm -f  /etc/newsyslog.conf.d/xray.conf

    rm -rf "$XRAY_HOME"
    rm -rf "$XRAY_ASSET_DIR"
    rm -rf "$XRAY_CONF_DIR"

    # Generic upstream Xray directories are never removed: they may belong to a
    # separately installed/manual Xray deployment.
    [ ! -e "$LEGACY_SHARED_CONF_DIR" ] || warn "$LEGACY_SHARED_CONF_DIR was preserved because it may contain external Xray configuration."
    [ ! -e "$LEGACY_SHARED_ASSET_DIR" ] || warn "$LEGACY_SHARED_ASSET_DIR was preserved because it may contain external Xray assets."

    rm -f /var/run/xray-????????-????-????-????-????????????.pid 2>/dev/null || true
    rm -f /var/run/xray-daemon-????????-????-????-????-????????????.pid 2>/dev/null || true
    rm -f /var/run/xray-hev-????????-????-????-????-????????????.pid 2>/dev/null || true
    rm -f /var/run/xray-hev-daemon-????????-????-????-????-????????????.pid 2>/dev/null || true
    rm -f /var/run/xray-stopped-????????-????-????-????-????????????.flag 2>/dev/null || true
    rm -f /var/run/xray-health-????????-????-????-????-????????????.json 2>/dev/null || true
    rm -f /var/run/xray.lock /var/run/xray-gateway-sync.lock 2>/dev/null || true
    rm -f /var/run/xray.lock.d/owner 2>/dev/null || true
    rmdir /var/run/xray.lock.d 2>/dev/null || true
    if [ "$PURGE_CONFIG" = "1" ]; then
        rm -f /var/log/xray-????????-????-????-????-????????????.log* 2>/dev/null || true
        rm -f /var/log/xray-watchdog.log* 2>/dev/null || true
        rm -f /var/log/xray-startup.log* 2>/dev/null || true
        rm -f /var/log/xray-service.log* 2>/dev/null || true
    fi

    CONFIGD_FAILED=0
    if ! service configd restart >/dev/null 2>&1; then
        CONFIGD_FAILED=1
        warn "configd restart failed; restart configd manually."
    fi
    rm -f /var/lib/php/tmp/opnsense_menu_cache.xml 2>/dev/null || true

    echo ""
    echo "==========================================="
    echo "  Xray plugin removed"
    echo "==========================================="
    [ "$PURGE_CONFIG" = "1" ] || echo "Saved config.xml data and logs were preserved."
    echo "Plugin-owned Xray under $XRAY_HOME was removed."
    echo "Any separately installed FreeBSD xray-core package was NOT touched."
    [ "$CONFIGD_FAILED" = "0" ] || exit 1
    exit 0
}

# ---------------------------------------------------------------------------
# ENTRY
# ---------------------------------------------------------------------------
require_root_opnsense

if [ "${1:-}" = "uninstall" ]; then
    uninstall_plugin
fi
[ "$#" -eq 0 ] || die "Unknown argument: ${1:-}. Usage: sh install.sh [uninstall]"

validate_source_tree
check_system_xray_service_off
check_conflicting_artifacts
# Validate preserved MVC state before any download/runtime stop/disk mutation.
check_saved_config_compatibility

CURRENT_VERSION="not installed"
[ ! -f "$VERSION_FILE" ] || CURRENT_VERSION=$(cat "$VERSION_FILE" 2>/dev/null || echo "unknown")
if [ "$CURRENT_VERSION" != "not installed" ] && [ "$CURRENT_VERSION" != "$PLUGIN_VERSION" ]; then
    if ! { [ "$CURRENT_VERSION" = "1.0.0" ] && [ "$PLUGIN_VERSION" = "1.0.1" ]; }; then
        die "$PLUGIN_VERSION has no supported in-place migration from installed plugin version $CURRENT_VERSION. Uninstall that version first."
    fi
fi

STATE_DIR=$(mktemp -d /tmp/xray-state.XXXXXX) || die "mktemp failed"
ROLLBACK_DIR=$(mktemp -d /tmp/xray-rollback.XXXXXX) || { rm -rf "$STATE_DIR"; die "mktemp failed"; }
chmod 0700 "$STATE_DIR" "$ROLLBACK_DIR"
INSTALL_MUTATION_STARTED=0
INSTALL_COMMITTED=0
ROLLBACK_FAILED=0
RUNTIME_STOP_ATTEMPTED=0
RUNTIME_STOPPED_FOR_UPGRADE=0
NEW_RUNTIME_RESTART_ATTEMPTED=0

trap installer_cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

resolve_upstream_releases
assess_installed_upstream

echo "============================================================"
echo "  opnsense-xray-plugin installer"
echo "============================================================"
echo "  Current plugin : $CURRENT_VERSION"
echo "  New plugin     : $PLUGIN_VERSION"
echo "  Upstream Xray  : v$XRAY_VERSION ($XRAY_ARCHIVE, $XRAY_CHANNEL)"
echo "  Upstream HEV   : $HEV_VERSION ($HEV_ASSET, $HEV_CHANNEL)"
echo "  Xray download  : $([ "$NEED_XRAY" = "1" ] && echo required || echo skipped-current)"
echo "  HEV download   : $([ "$NEED_HEV" = "1" ] && echo required || echo skipped-current)"
echo "  Xray path      : $XRAY_BIN"
echo "  Package manager: NOT USED"
echo ""

if [ "$CURRENT_VERSION" = "$PLUGIN_VERSION" ]; then
    ask_yes_no "Reinstall $PLUGIN_VERSION?" n || { echo "Cancelled."; exit 0; }
elif [ "$CURRENT_VERSION" = "not installed" ]; then
    ask_yes_no "Install $PLUGIN_VERSION?" y || { echo "Cancelled."; exit 0; }
else
    ask_yes_no "Upgrade $CURRENT_VERSION to $PLUGIN_VERSION?" y || { echo "Cancelled."; exit 0; }
fi

snapshot_file /usr/local/etc/pkg/repos/OPNsense.conf OPNsense.conf
snapshot_file /usr/local/etc/pkg/repos/FreeBSD.conf FreeBSD.conf

# Download and fully verify BEFORE stopping any running instance or mutating disk.
stage_upstream_xray
capture_running_instances
assert_managed_processes_tracked
prepare_rollback
stop_captured_instances
assert_no_managed_processes
assert_no_managed_ifaces
assert_no_service_controls

INSTALL_MUTATION_STARTED=1
install_upstream_xray
install_plugin_files
verify_plugin_install
verify_repo_files_untouched

NEW_RUNTIME_RESTART_ATTEMPTED=1
if ! restart_captured_instances_with /usr/local/opnsense/scripts/Xray/xray-service-control.php; then
    die "New build installed but one or more previously running instances failed to restart; rolling back."
fi

if ! run_control_bounded /usr/local/opnsense/scripts/Xray/xray-service-control.php cleanup >/dev/null 2>&1; then
    die "New build installed but derived-artifact cleanup failed; rolling back."
fi

INSTALL_COMMITTED=1
rm -rf "$ROLLBACK_DIR" 2>/dev/null || true

echo ""
echo "============================================================"
echo "  opnsense-xray-plugin $PLUGIN_VERSION installed successfully"
echo "============================================================"
echo "  Xray: $("$XRAY_BIN" version 2>/dev/null | head -n 1)"
echo "  HEV: $HEV_VERSION ($HEV_BIN)"
echo "  Binary: $XRAY_BIN"
echo "  Assets: $XRAY_ASSET_DIR"
echo "  Configs: $XRAY_CONF_DIR"
echo "  Architecture: Xray SOCKS5 -> HevSocks5Tunnel -> FreeBSD tun(4)"
echo "  FreeBSD package/repositories: untouched"
echo ""
echo "Next steps:"
echo "  1. Open VPN -> Xray -> Clients and add/import one client (tun0 + unique /32 + unique SOCKS5 port)."
echo "  2. Save on General; enabled clients are synchronized immediately (Xray SOCKS5, then HEV TUN)."
echo "  3. Assign tunN, keep interface IP configuration at None and Dynamic Gateway Policy disabled; Gateway Health Sync can create/adopt a static Far Gateway for policy routing."
echo "  4. Verify Diagnostics health status and enable Watchdog if automatic recovery is desired."
echo ""
echo "For Gateway Groups, use the static Far Gateway created/adopted by Gateway Health Sync; do not route the router default route through tun0."
echo "Refresh the GUI (Ctrl+F5) if the Xray menu is not visible immediately."
