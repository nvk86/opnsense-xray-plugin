# opnsense-xray-plugin 1.0.0

First public release of `opnsense-xray-plugin`, a client-only Xray integration for OPNsense.

## Highlights

- VLESS + REALITY client management for RAW, XHTTP and gRPC.
- Optional VLESS Vision flow is stored, imported, validated and emitted into the generated Xray configuration instead of being dropped.
- Xray-core loopback SOCKS5 → HevSocks5Tunnel → FreeBSD `tun(4)` architecture, leaving OPNsense in control of policy routing.
- Per-client Start / Stop / Restart / Validate / Test operations.
- Automatic collision-aware allocation of TUN interface, local SOCKS5 port and link-local `/32` address.
- End-to-end health checks plus an optional watchdog with consecutive-failure and cooldown protection.
- Optional Gateway Health Sync maps verified proxy health to a native OPNsense dynamic gateway `Force Down` state for Gateway Group failover, with three-failure debounce to avoid flapping on transient probe loss.
- Strict ownership handling: unrelated processes and generic `tunN` devices are not stopped or destroyed merely because their names look similar.
- Transactional installer with verified upstream assets, rollback and restoration of the pre-install running set.

## Upstream Xray policy

The installer deliberately selects the newest suitable non-draft Xray-core release **including pre-releases**. This is intentional for 1.0.0.

GitHub release SHA256 metadata is verified and Xray is additionally checked against its matching upstream `.dgst`. HevSocks5Tunnel is also downloaded from a matching non-draft GitHub release asset.

Because an Xray pre-release can change transport behavior, run **Validate Config** and **Test** after an upstream Xray change before moving important policy-routing traffic.

## Supported import surface

The structured VLESS URI importer in 1.0.0 supports REALITY, `encryption=none`, RAW/TCP, XHTTP and gRPC, optional Vision flow, SNI/Public Key/Short ID/SpiderX/fingerprint, XHTTP host/path/mode and gRPC service/authority/multi mode.

Unsupported values are rejected instead of being accepted and silently omitted from the saved configuration.

## OPNsense integration boundaries

The plugin does not silently create firewall rules, Outbound NAT or policy-routing rules. Assign the generated `tunN` under **Interfaces → Assignments**, leave interface IP configuration at **None**, configure the native gateway/gateway group, and select it in the required firewall rules.

Gateway Health Sync is opt-in. It restores a pre-existing gateway's original `Force Down` state when released. If the plugin had to materialize a native dynamic gateway solely for synchronization, ownership is bound to the persisted OPNsense gateway UUID and that gateway is removed again when sync is disabled, the client is deleted, required interface/gateway-policy prerequisites disappear, or the plugin is uninstalled. Reconcile also cleans stale ownership from interrupted or older deletions and upgrades older name-only ownership records. Reinstall/start cleanup removes obsolete pre-release generated `.json.auto` files and stopped flags for UUIDs that no longer exist.

## Platform

- OPNsense / FreeBSD 15+
- amd64

## Install

```sh
sh install.sh
```

After installation configure clients under **VPN → Xray → Clients**, then enable the service under **VPN → Xray → General**.

Reinstalling the same 1.0.0 version is supported. In-place transitions between different plugin versions are permitted only when the target release explicitly supports that migration path.
