# dimmi

Exploratory prototype for Dimmi. The `UI/index.html` page provides a small
"DIMMI" widget that slides out a menu of project files.

To regenerate the menu tree, run:

```
node generate-file-tree.js
```

This scans the `Art/` directory and writes `UI/fileTree.json`, which the UI uses
to populate the expandable menu and to open file contents.
