# dimmi

Exploratory prototype for Dimmi. The `UI/index.html` page provides a small
"DIMMI" widget that slides out a menu of project files.

To regenerate the menu tree, run:

```
node UI/generate-file-tree.js
```

This scans the repository (excluding `UI/` and hidden directories) and writes
`UI/fileTree.js`, which the UI uses to populate the expandable menu and load
file contents.

## Repo organizer

A helper script `scripts/repo_organizer.py` analyzes the repository and stages a simplified structure. Typical usage:

```
# 1) Look, don’t touch
python3 scripts/repo_organizer.py inventory --dry-run
python3 scripts/repo_organizer.py plan --dry-run

# 2) Stage symlinked Apps and the live map (safe)
python3 scripts/repo_organizer.py stage --dry-run
python3 scripts/repo_organizer.py map --dry-run

# 3) Apply ONLY knowledge moves
python3 scripts/repo_organizer.py apply --apply
python3 scripts/repo_organizer.py map
python3 scripts/repo_organizer.py history

# 4) (Later, optional) consider moving apps physically
python3 scripts/repo_organizer.py apply --move-apps --apply
```

## Standalone ProPrompts

Tasks now live as small Markdown cards in `MIND/ProPrompts`.
Each card uses a short header (`id`, `title`, `role`, `status`) followed by instructions.
Files that previously held full blocks now keep a pointer like:

```
/// === PROPROMPT:BEGIN ===
see: MIND/ProPrompts/queue/example-card.md
/// === PROPROMPT:END ===
```

Tools such as `DimmiD/runner/proprompt_parser.py` and the CLI spec help modules share these cards through the same menu system.
