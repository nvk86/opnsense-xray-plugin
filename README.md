# opnsense-xray-plugin

**Client-only Xray VPN plugin for OPNsense**

`opnsense-xray-plugin` provides managed VLESS + REALITY outbound clients and exposes them to OPNsense policy routing through FreeBSD `tun(4)` devices created by HevSocks5Tunnel.

```text
OPNsense policy routing
        ↓
      tunN
        ↓
HevSocks5Tunnel
        ↓ SOCKS5 on loopback
     Xray-core
        ↓
 VLESS + REALITY
        ↓
      server
```

> This is a third-party community plugin. It is not an official OPNsense, Xray-core or HevSocks5Tunnel component.

## Features

- Native OPNsense GUI under **VPN → Xray → General / Clients / Diagnostics**.
- Client-only design; no Xray server provisioning.
- VLESS + REALITY with `raw`, `xhttp` and `grpc` transports.
- Optional VLESS flow, including `xtls-rprx-vision`.
- REALITY SNI, public key, short ID, SpiderX and TLS fingerprint settings.
- XHTTP path/host/mode plus optional traffic-shaping fields exposed by current Xray builds.
- Structured client editor and VLESS URI import.
- Automatic allocation of a free `tunN`, loopback SOCKS5 port and `169.254.x.1/32` address for new clients.
- Per-client **Start / Stop / Restart / Test** actions.
- Dry-run validation through the installed Xray binary before saving an unsaved client configuration.
- End-to-end health probe through the client's own SOCKS5/Xray path.
- Optional watchdog with a consecutive-failure threshold and restart cooldown.
- Optional per-client **Gateway Health Sync** to feed verified proxy health into native OPNsense Gateway Group failover.
- Diagnostics for runtime state, TUN ownership, interface assignment, Dynamic Gateway Policy, gateway synchronization/native gateway state and loaded PF `route-to` rules.
- Persistent service, watchdog and per-client Xray logs with `newsyslog` rotation.
- Transactional install/reinstall with rollback.
- Plugin-owned upstream binaries; FreeBSD package repositories are not modified.

## Supported platform

- OPNsense on FreeBSD 15 or newer.
- amd64 only at present because upstream HevSocks5Tunnel does not publish a FreeBSD arm64 executable.

## Upstream components and release policy

The installer resolves upstream binaries directly from GitHub Releases. It selects the newest non-draft release that contains the required asset.

- [XTLS/Xray-core](https://github.com/XTLS/Xray-core) — **pre-releases are intentionally eligible**. This is a deliberate project policy, not an accidental release-channel setting.
- [heiher/hev-socks5-tunnel](https://github.com/heiher/hev-socks5-tunnel) — newest non-draft release containing the FreeBSD x86_64 binary.

Release-asset SHA256 values are taken from GitHub release metadata. Xray is additionally verified against its matching upstream `.dgst` asset. If an installed component already matches the selected upstream version and its recorded hashes, the installer verifies and reuses it instead of downloading it again.

Because Xray pre-releases may change protocol or transport behavior, validate a client and run **Test** before moving production policy-routing traffic to a newly selected upstream Xray build.

## Code lineage

Some OPNsense MVC/UI and VLESS import code originated in [MrTheory/os-xray](https://github.com/MrTheory/os-xray) and remains covered by its BSD 2-Clause copyright notice. The notice is retained in [LICENSE](LICENSE).

`os-xray` is **not** a runtime dependency or update source for this project. The current backend is maintained independently and uses Xray SOCKS5 → HevSocks5Tunnel → FreeBSD `tun(4)`; it does not use tun2socks.

## Installation

Clone or download the release source on OPNsense, then run the installer as root:

```sh
sh install.sh
```

The installer validates the source tree and preserved configuration before changing the system, resolves and verifies upstream assets, snapshots plugin-owned state, stops only plugin-managed running instances, installs the new files, validates the installed backend and restores the instances that were running before the transaction. A failure after mutation triggers rollback.

After installation refresh the OPNsense GUI and open:

**VPN → Xray**

### Reinstall / future upgrades

Running `install.sh` again on an installed **1.0.0** performs a guarded reinstall while preserving configuration and restoring previously running instances. The installer refuses an in-place version transition unless that migration path is explicitly supported by the release being installed.

### Uninstall

```sh
sh install.sh uninstall
```

Uninstall stops plugin-managed runtime, releases Gateway Health Sync state, removes gateways that were materialized solely by the plugin, restores pre-existing gateways' original `Force Down` value, and removes plugin-owned files and binaries. Generic `/usr/local/etc/xray` and `/usr/local/share/xray` paths are deliberately preserved because they may belong to another Xray installation.

Saved OPNsense Xray configuration can optionally be purged during uninstall. The installer refuses destructive teardown while a plugin TUN is still assigned in OPNsense.

## Client configuration

1. Open **VPN → Xray → Clients**.
2. Add a client manually or use **Import VLESS link → Parse & Fill**.
3. Configure server, port, UUID and optional VLESS flow.
4. Configure REALITY SNI, public key, short ID, optional SpiderX and fingerprint.
5. Select `raw`, `xhttp` or `grpc`. Transport-specific fields appear automatically.
6. Use **Validate Config** before saving when desired.
7. Save the client. New clients receive a free TUN name, SOCKS5 port and link-local `/32` address automatically; change them only when needed.
8. Open **VPN → Xray → General**, enable Xray and click **Save**.

### VLESS URI import scope

The 1.0.0 structured importer intentionally accepts the configuration surface that the plugin can persist and regenerate without silently losing parameters:

- security: `reality`;
- encryption: `none`;
- transports: `raw`/`tcp`, `xhttp`, `grpc`;
- flow: empty, `xtls-rprx-vision`, `xtls-rprx-vision-udp443`;
- REALITY: SNI, public key, short ID, SpiderX and supported fingerprint;
- XHTTP: mode, path and optional host;
- gRPC: service name, authority and multi mode.

Unsupported URI settings are rejected instead of being imported and then silently discarded.

## OPNsense interface and policy routing

The plugin intentionally does **not** create interface assignments, firewall rules, Outbound NAT rules or change the firewall's default route. Those remain native OPNsense configuration.

For each client used for policy routing:

1. Assign its `tunN` under **Interfaces → Assignments**.
2. Enable the assigned interface and leave IPv4/IPv6 configuration as **None**; HEV owns the TUN address.
3. Enable **Dynamic gateway policy** for the assigned TUN when using it as a policy-routing gateway.
4. Create the required gateway or gateway group using normal OPNsense configuration.
5. Apply that gateway or gateway group to the desired LAN/VLAN firewall rule.
6. Confirm **VPN → Xray → Diagnostics** sees the assignment and, when applicable, a loaded PF `route-to` rule.

Do not route the firewall's own default route through the Xray TUN unless that is explicitly part of your design.

## Health monitoring and watchdog

An existing process and TUN do not prove that the remote proxy is usable. The plugin therefore performs an end-to-end health check through each client's loopback SOCKS5 listener and Xray outbound path.

Diagnostics separates local runtime state from proxy connectivity and reports the last health result, latency and consecutive failures. When the watchdog is enabled, it restarts a client only after three consecutive failed health checks and enforces a ten-minute restart cooldown. A restart skipped because another lifecycle operation holds the lock does not consume the cooldown.

## Gateway Health Sync

Gateway Health Sync is opt-in. When enabled for a client, the plugin mirrors verified end-to-end Xray health into the corresponding native OPNsense dynamic gateway `force_down` state and invokes OPNsense's routing alarm path when that state changes. A transient first or second failed probe is treated as inconclusive for gateway state; `Force Down` is asserted only after three consecutive failures and is cleared on the next successful probe.

Enable it only after the client's TUN is assigned under **Interfaces → Assignments** and **Dynamic gateway policy** is enabled. The plugin records ownership and the pre-existing gateway state:

- a pre-existing persisted gateway is never taken over permanently; its original `Force Down` value is restored when sync is released;
- a native dynamic gateway materialized solely so the plugin can manage health state is removed again when sync is released, its client is deleted, its required interface/gateway-policy prerequisites disappear, or the plugin is uninstalled; stale ownership records are reconciled on later lifecycle operations.

This mechanism is separate from `dpinger`: the Xray end-to-end probe is the health source for a synchronized Xray gateway, while ordinary gateways continue to use their normal OPNsense monitoring configuration.

## Logs

Important files include:

```text
/var/log/xray-service.log
/var/log/xray-watchdog.log
/var/log/xray-<client-uuid>.log
```

Logs are available in the GUI and integrated with `newsyslog`.

## Runtime paths

```text
/usr/local/libexec/xray/              Xray and HEV binaries
/usr/local/share/opnsense-xray/       Xray geo assets
/usr/local/etc/opnsense-xray/         generated client/HEV configs and sync registry
/usr/local/opnsense/scripts/Xray/     backend scripts
```

## Security and ownership boundaries

- No FreeBSD repository is enabled or changed.
- No `pkg` Xray package is installed, upgraded or removed.
- A separately installed Xray package may remain present, but its rc.d service/process must be disabled to avoid runtime collisions.
- Runtime processes are acted on only after PID/command ownership verification.
- A generic `tunN` is destroyed only when the plugin can prove ownership; unrelated TUN interfaces are not claimed by a broad wildcard.
- Generated client configuration is stored under the plugin-owned configuration directory with restricted permissions.
- Reinstall/start cleanup removes obsolete pre-release `config-<uuid>.json.auto` files and stopped flags for client UUIDs that no longer exist.

## License

BSD 2-Clause License. See [LICENSE](LICENSE).


Xray-core and HevSocks5Tunnel are separate upstream projects distributed under their own licenses.
