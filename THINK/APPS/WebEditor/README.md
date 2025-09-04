# DIMMI CLOUD

A single-file PHP editor for browsing and editing files inside the repo.

## Features
- Session-based login (defaults admin/admin; edit credentials at top of `CLOUD/index.php`)
- Path jail that prevents escaping the repository root
- Breadcrumb navigation with a quick-jump path input and a Root button
- Rename confirmation that allows moving files by entering a new path
- Delete buttons for files and empty folders
- Formatting helpers for JSON and OPML (YAML support still pending)
- Links pane that saves cross-document links in `.links.json` files

## Usage
Place `CLOUD/index.php` on a PHP-enabled host. No extra dependencies are required.
The root jail automatically points to the repository directory.
