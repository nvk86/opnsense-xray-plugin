# Changelog

## 1.0.1 — 2026-09-19

Gateway Group compatibility and Far Gateway health synchronization.

- Replaced the 1.0.0 addressless dynamic-gateway integration with static synthetic **Far Gateways**, allowing Xray TUNs to participate in native OPNsense Gateway Groups and PF `round-robin` pools.
- Gateway Health Sync now derives an adjacent synthetic gateway address from each HEV-owned TUN `/32`, creates or adopts the matching Far Gateway, keeps native gateway monitoring disabled, and drives `force_down` from the existing end-to-end Xray health probe.
- Legacy plugin-owned 1.0.0 dynamic gateways are released after Dynamic Gateway Policy is disabled; their names are reused when available so existing Gateway Group references can survive the transition.
- Pre-existing user-created Far Gateways remain user-owned: only `Force Down` is synchronized and the original value is restored when ownership is released.
- Diagnostics now treat **Dynamic Gateway Policy disabled** as the correct state for Xray policy routing and report the expected/native Far Gateway address.
- Added supported in-place installer migration from 1.0.0 to 1.0.1.
- Updated installation and policy-routing documentation to keep interface IPv4/IPv6 configuration at `None`, leave HEV as the sole TUN-address owner, and use the static Far Gateway for Gateway Groups.
- No manual host route to the synthetic gateway is required for PF `route-to` operation on the HEV point-to-point TUN.

## 1.0.0 — 2026-09-14

Initial public release.

- Client-only OPNsense plugin for VLESS + REALITY using Xray-core SOCKS5 → HevSocks5Tunnel → FreeBSD `tun(4)`.
- RAW, XHTTP and gRPC transport configuration.
- Optional VLESS flow persisted end-to-end, including `xtls-rprx-vision` and `xtls-rprx-vision-udp443`.
- REALITY SNI, public key, short ID, SpiderX and supported TLS fingerprints.
- XHTTP mode/path/host and optional traffic-shaping controls; gRPC service name/authority/multi mode.
- Structured VLESS URI import rejects unsupported settings instead of silently discarding them; 1.0.0 imports REALITY with `encryption=none`.
- Automatic allocation of a free TUN interface, SOCKS5 port and link-local `/32` address.
- Per-client Start, Stop, Restart, Validate and end-to-end Test controls.
- Strict runtime ownership checks for process/PID files and generic FreeBSD `tunN` interfaces.
- End-to-end health monitoring through each client's own SOCKS5/Xray path, with latency and consecutive-failure tracking.
- Optional watchdog restart after three consecutive health failures with a ten-minute cooldown; a lifecycle-busy skip does not consume the cooldown.
- Optional per-client **Gateway Health Sync** drives native OPNsense dynamic gateway `force_down` state and routing alarms from verified Xray health; transient failures are debounced and only three consecutive failures assert `Force Down`.
- Gateway Health Sync restores pre-existing gateway state, binds plugin-created gateways to their persisted OPNsense UUID, removes a dynamic gateway materialized solely by the plugin when ownership is released, explicitly releases ownership before client deletion and reconciles stale/name-only registry entries. Legacy pre-release `.json.auto` files and stopped flags for deleted UUIDs are cleaned automatically.
- Diagnostics for Xray, SOCKS5, HEV, TUN ownership, interface assignment, Dynamic Gateway Policy, native gateway state and loaded PF `route-to` rules.
- Persistent service/watchdog/per-client logs with `newsyslog` rotation.
- Transactional installer/reinstaller with rollback and pre-mutation configuration checks.
- Installer intentionally permits Xray-core pre-releases, verifies GitHub release SHA256 metadata and the matching Xray `.dgst`, and reuses already-current verified components.
