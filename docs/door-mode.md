# Door Mode Overview

This guide explains how Door mode behaves so future maintainers can keep the experience consistent.

## Mode Toggle
- Door mode can be turned on and off from the global mode toggle.
- When Door mode is active, Door-specific UI replaces the standard home context.
- Toggling back to classic mode restores the default layout without losing the user session.

## Content Drawer Workflow
1. Opening the drawer shows the curated Door content collection.
2. Users can search within the drawer using the Door search tile.
3. Selecting an item opens its detail view while keeping the drawer accessible for quick switching.
4. The attach flow lets users pin Door content into conversations or tasks; the item remains linked even after closing the drawer.

## Schema 1.1 Expectations
- Door mode reads from schema version 1.1, so new data must follow that contract.
- Entries must include a `doorId`, `title`, `summary`, and `contentUrl` fields.
- Attachments reference items by `doorId`; classic deep links continue to work because the schema keeps backward-compatible URLs.
- When updating the schema, maintain the 1.1 fields and add migrations before raising the version.
