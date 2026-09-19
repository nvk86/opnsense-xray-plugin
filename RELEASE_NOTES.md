# opnsense-xray-plugin 1.0.1

Maintenance release focused on native OPNsense Gateway Group compatibility.

## What changed

- Gateway Health Sync now uses a **static synthetic Far Gateway** instead of the addressless dynamic gateway used by 1.0.0.
- Xray TUN gateways can therefore participate in ordinary OPNsense Gateway Groups and PF `round-robin` pools.
- The synthetic gateway is derived from the HEV-owned TUN `/32` address. With the default allocator, for example:
  - `169.254.100.1/32` → `169.254.100.2`
- HEV remains the sole owner of the TUN address. Interface IPv4/IPv6 configuration stays at **None**.
- **Dynamic Gateway Policy must be disabled** for Xray interfaces in 1.0.1.
- Native gateway monitoring remains disabled; the existing end-to-end Xray health probe drives `Force Down` after the same three-consecutive-failure debounce.
- A matching user-created Far Gateway is adopted without taking permanent ownership. Its original `Force Down` state is restored when synchronization is released.
- A Far Gateway created solely by the plugin is removed when synchronization is released, the client is deleted, or the plugin is uninstalled.
- Legacy 1.0.0 plugin-owned dynamic gateways are released and replaced after Dynamic Gateway Policy is disabled. Their old name is reused when available so Gateway Group references can continue to resolve.
- Diagnostics now show the expected/native Far Gateway and treat Dynamic Gateway Policy **disabled** as the correct state.

## Upgrade from 1.0.0

In-place upgrade from 1.0.0 is supported.

For every assigned Xray `tunN`:

1. Keep the assigned interface enabled with IPv4/IPv6 configuration set to **None**.
2. Disable **Dynamic Gateway Policy**.
3. Enable or leave enabled **Gateway Health Sync**.
4. Let the next conclusive health probe create/adopt the static Far Gateway.
5. Verify the Gateway Group contains the Xray gateway and that the loaded PF rule contains the expected `route-to` member.

If a 1.0.0 plugin-owned dynamic gateway is still tracked, 1.0.1 releases it before creating the Far Gateway. A pre-existing user-owned gateway is not deleted.

No manual host route to the synthetic Far Gateway is required for PF `route-to` operation on the HEV point-to-point TUN.

## Gateway Group example

A location can now use two equal-priority transports in the same tier:

```text
Tier 1
  PRIMARY_VPN_GW
  XRAY_TUN_GW
Pool option: Round Robin
```

The resulting PF rule can contain both members, for example:

```text
route-to { (vpn0 192.0.2.254), (tun0 169.254.100.2) } round-robin
```

Existing states stay pinned to their selected gateway; new states are distributed by PF.

## Upstream components

The upstream component policy is unchanged from 1.0.0:

- Xray-core: newest suitable non-draft release, including pre-releases by project policy.
- HevSocks5Tunnel: newest suitable non-draft FreeBSD x86_64 release.
- Release assets are verified before installation.

## Platform

- OPNsense / FreeBSD 15+
- amd64

## Install / upgrade

```sh
sh install.sh
```

After installation, refresh the GUI and verify **VPN → Xray → Diagnostics** for every policy-routing client.
