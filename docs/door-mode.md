# Door Mode Overview

This guide explains how Door mode works today so future maintainers can keep the experience consistent.

## Mode Toggle
- Door mode can be turned on and off from the global mode toggle.
- When Door mode is active, the Door layout replaces the classic editor panes.
- Switching back to classic mode restores the default layout without losing the session.

## Door Builder Workflow
1. Open the builder from any screen with the **Open Door Builder** button. The builder loads in a full-page layout with Atlas navigation on the left and an editor on the right.
2. Pick a room in the Atlas tree to load its details. The title and note fields can be edited directly.
3. Use the preview drawer to double-check changes. The **Web** tab shows the rendered Markdown, while **Raw** lets you tweak the source. Save from the drawer or the main save button to keep everything in sync.
4. Create new rooms with **Add Child**. The dialog asks for a name and automatically wires the room under the current parent.
5. Attach teleports with the **Attach** button. The wizard walks through five link types:
   - **File** – points at a Markdown or text file.
   - **Folder** – jumps into a directory. Paths should match the normalized folder path (see below).
   - **URL** – opens an external site.
   - **Structure** – previews OPML/JSON outlines before linking them.
   - **Relation** – connects one room to another by selecting from the Atlas search results.
6. Every teleport collects a label, a target, and a type. The builder keeps the data tidy and deduplicated before saving.

## Door Dataset Structure
Door content lives in `DATA/door/door.json`. The file keeps a simple, explicit schema:
- Top-level fields:
  - `schemaVersion` – currently `"1.1"`.
  - `metadata` – general info like the overall title.
  - `root` – an array that contains the full room tree.
- Each room entry contains:
  - `id` – UUID or slug used inside the builder.
  - `title` and `note` – the text shown in Door mode.
  - `icon`, `color`, and `tileKind` – presentation hints for the UI.
  - `created` / `modified` – ISO timestamps.
  - `children` – nested room objects.
  - `links` – teleport objects saved by the builder. Each teleport keeps an `id`, `target`, `label`, optional `title`, and a `type` such as `file`, `folder`, `url`, `structure`, or `relation`.

Keep this structure when editing by hand. Adding new fields is fine as long as the existing keys stay backwards compatible.

## Normalizing Markdown Links
Door teleports lean on consistent link paths inside `ARKHIVE`. Use the helper script whenever Markdown files change:

```bash
# Dry run: lists files that still need clean-up
php scripts/normalize_links.php --check

# Rewrite in-place after reviewing the report
php scripts/normalize_links.php
```

The script scans every `ARKHIVE/**/*.md` file and normalizes inline links so they:
- use clean relative paths (Windows backslashes, duplicate slashes, `.` segments, and extra `..` hops are removed),
- treat README-style files (`README.md`, `index.md`, `overview.md`) as folder links with a trailing `/`,
- keep anchors and query strings attached to the cleaned path.

Running it regularly keeps Markdown, Door teleports, and future automation in sync.
