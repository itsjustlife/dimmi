# Door mode

Door is a touch-friendly wrapper around the classic file tools. It lives at [`cloud.php?mode=door`](../CLOUD/cloud.php) and reuses the same backend endpoints that power the four-pane editor.

## Getting around
- **Breadcrumb** – tap any crumb to jump to that folder. The “Home” crumb brings you back to the repo root.
- **Refresh** – reloads the current folder using the shared `?mode=door&fs=list` wrapper around the classic `list` endpoint.
- **Search** – filters the current folder tiles client-side. Clearing the box restores the full listing.
- **Tiles** – folders open the next level; supported files (`.json`, `.opml`, `.md`, `.markdown`, `.html`, `.htm`) open in the drawer. Other files show an informational message.
- **Keyboard** – arrow keys step through tiles, `Enter` activates the focused tile, and `Esc` closes the drawer.

## Preview drawer
The drawer slides over the right edge and reuses the shared preview module:
- **WEB tab** calls `PreviewService.renderWeb` for the selected path.
- **RAW tab** uses `PreviewService.loadRaw` / `saveRaw` and prompts before navigation when unsaved changes exist.
- The drawer path mirrors the repo-relative location so it’s easy to copy into the classic panels.

## Notes
- Door enforces the same jail rules (`safe_abs`) as the classic editor; all operations stay inside the repo root.
- POST requests include `window.__csrf`, matching the classic CSRF model.
