# opnsense-xray-plugin 1.2.1

Maintenance release focused on more reliable health checks and non-disruptive watchdog behavior.

## What changed

- Added fallback across three independent HTTPS health endpoints via the client's SOCKS5 proxy. Any HTTP 200/206 response with at least 16 KiB received confirms useful proxy connectivity.
- Restricted watchdog restarts to repeated local runtime failures, rather than remote HTTPS reachability timeouts.
- Preserved the three-consecutive-failure debounce for native Gateway Health Sync and restored gateway availability after a successful probe.
- Made manual GUI Test read-only with respect to production health counters, cache, and native gateway state.
- Prevented concurrent health probes and lock inheritance by OPNsense service processes.
- Added HTTPS fallback and lock-handling regression tests.

## Upgrade

In-place upgrade from **1.2.0** is supported with `sh install.sh`. The installer preserves plugin configuration and restores previously running instances after the upgrade. It also checks current upstream Xray-core and HevSocks5Tunnel releases, may update their binaries, and restarts previously running Xray instances.
