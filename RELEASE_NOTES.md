# opnsense-xray-plugin 1.1.0

Minor release focused on UI consistency and selective Apply behavior.

## What changed

- Unified General and Clients around the standard Apply workflow.
- Compact per-client action controls.
- Apply now restarts only changed or unhealthy clients while leaving unchanged healthy clients untouched and preserving manual Stop state.
- Added clear help text across General and client configuration fields.

## Upgrade

In-place upgrade from 1.0.1 is supported. Existing clients, TUN assignments, gateways, health state and policy-routing configuration are preserved.
