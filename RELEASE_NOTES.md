# opnsense-xray-plugin 1.2.0

Minor release adding native Prometheus monitoring.

## What changed

- Added a read-only Prometheus endpoint at `/api/xray/service/metrics`.
- Exposes Xray/SOCKS5/HEV/TUN runtime, cached end-to-end health, watchdog and Gateway Health Sync state.
- Scrapes do not run `testconnect` or otherwise initiate network probes.
- Added a dedicated **Xray: Prometheus metrics** ACL privilege.
- Sensitive configuration such as server addresses, VLESS UUIDs, REALITY material, health targets and internal UUIDs is not exported as metric labels.
- Installer now invalidates the OPNsense ACL cache after install, rollback and uninstall so newly installed privileges are visible immediately.

## Upgrade

In-place upgrade from 1.1.0 is supported. Existing clients, TUN assignments, gateways, health state and policy-routing configuration are preserved.
