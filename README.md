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
