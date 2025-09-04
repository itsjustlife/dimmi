# WebEditor

A single-file PHP WebEditor for browsing and editing files inside the repo.

## Features
- Session-based login (defaults admin/admin; edit credentials at top of `ui/index.php`)
- Path jail that prevents escaping the repository root
- Breadcrumb navigation with a quick-jump path input
- Rename confirmation that allows moving files by entering a new path
- Formatting helpers for JSON and OPML (YAML support still pending)
- Links pane that saves cross-document links in `.links.json` files

## Usage
Place `ui/index.php` on a PHP-enabled host and set the `$ROOT` variable to your repository path. No extra dependencies are required.
