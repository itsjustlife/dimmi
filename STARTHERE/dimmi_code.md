# dimmi-code

Files are chapters written in plain text.

Headings use `#` and may include anchors like `{#id}`.

Post-Prompts:

```
!!TYPE key=value :: body text
@TYPE@ body text
```

Core types:

- `!!GOTO path=arkhiver.txt#ui` – teleport/link to file/anchor.
- `!!LINK url=https://... label=OPML` – add external link.
- `!!ASK to=dimmi :: question` – question for Online Dimmi.
- `!!TODO priority=high :: task` – structured task.
- `!!TAG name=arkhive` – add a tag.
- `!!APPEND node="Some / Path" :: - Child A\n- Child B` – OPML subtree to insert.
- `!!REFRESH` – marker to trigger pipeline.
- `!!LOG :: note` – freeform note.

Metadata block (optional) on the first line:

```
:::meta title="Door" kind="module" updated="2025-08-29"
```
