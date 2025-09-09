# GUI Overview

Brief names for the main parts of the Arkhivist window.

## Top Buttons
- **New** – start a fresh outline. [`arkhiver/app.py` lines 240-241]
- **Load** – open files or folders. [`arkhiver/app.py` lines 243-256]
- **Send** – export or save data. [`arkhiver/app.py` lines 258-285]
- **Settings** – set home folder, refresh, view help. [`arkhiver/app.py` lines 287-304]
- **ID** – placeholder for future ID tools. [`arkhiver/app.py` lines 306-313]

## Find Pane
- Buttons: Back, Up, Home, Load Structure. [`arkhiver/file_browser.py` lines 51-55]
- Address bar for typing a path. [`arkhiver/file_browser.py` lines 57-61]
- Folder tree with right-click menu (Open, Reveal, Attach Link, etc.). [`arkhiver/file_browser.py` lines 66-119]

## Structure Pane
- Add Sub / Add Same / Add Link buttons. [`arkhiver/app.py` lines 315-318]
- Tree showing the outline. [`arkhiver/app.py` lines 320-333]
- Copy, Cut, Paste, Save Structure, Delete buttons. [`arkhiver/app.py` lines 333-345]

## Content Pane
- Mode buttons: Preview, Edit, Code. [`arkhiver/app.py` lines 432-444]
- Title field and text editor for node content. [`arkhiver/app.py` lines 447-487]
- Code mode editor for raw files. [`arkhiver/app.py` lines 489-512]
- Link area at bottom. [`arkhiver/app.py` lines 519-520]

## Preview Pane
- Shows rendered HTML version of the current node. [`arkhiver/app.py` lines 197-203]

## Status Bar
- Message, progress bar, Start and Open buttons. [`arkhiver/app.py` lines 666-677]
