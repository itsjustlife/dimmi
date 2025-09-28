# PROPROMPT · DOOR_SEEDS_REFRESH

/// === PROPROMPT:BEGIN ===
id: PP-${now}-DOOR-SEEDS
title: Refresh DOOR seed dataset
role: Curator
priority: P1
scope: door
inputs:
  source: ARKHIVE/
  dataset: DATA/door/
steps:
  - Run `php scripts/build_door_nodes.php` to rebuild nodes and content files.
  - Review `DATA/door/index.json` and `DATA/door/nodes.json` for anomalies.
  - Spot-check regenerated markdown under `DATA/door/content/`.
  - Commit and sync updates to keep DOOR mode aligned with ARKHIVE.
checks:
  - nodes.json loads without JSON schema warnings.
  - Teleport links resolve to valid targets in the UI.
  - Stub rooms are noted for follow-up.
status: pending
owner: auto
created: ${ISO-UTC}
updated: ${ISO-UTC}
notes: |
  Trigger via the "Rebuild Seeds" action inside DOOR mode (cloud.php) when the Arkhive tree changes.
/// === PROPROMPT:END ===
