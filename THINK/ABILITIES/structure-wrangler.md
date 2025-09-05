# ABILITY: structure-wrangler
_version: 0.3 • scope: OPML ingest → normalize → dedupe → patch → integrate_

**What this ability does (short + punchy)**  
- Turn raw content (chat threads, outlines, OPML) into **clean OPML structures**.  
- **Combine** overlapping structures without re‑nesting or duplication.  
- Emit **paste‑ready** OPML: either full‑wrapper files or **child‑only** payloads.  
- Use **path + mode** instructions so edits in SimpleMind/Arkhivist are predictable.

---

## Conventions (keep these tight)
- **Titles go in `text` only.** Put explanations in `_note`.  
- Prefer **short, clean titles**; avoid punctuation like `: - ()` in `text`.  
- Use `_note` for details, sources, and Post‑Prompts.  
- Track provenance with `x_src="filename.ext"`; cross‑references with `x_link="/PATH/TO/NODE"`.  
- **Idempotent edits**: never assume prior context—always state **Target path** + **Mode** + **Payload**.  
- **OPML wrapper** (xml/opml/head/body) is required for **imports**; for **in‑place edits**, use **child‑only** payloads.

---

## Modes (single source of truth)
- **INSERT** – append children to the target.  
- **REPLACE CHILDREN** – clear target’s children, then paste new children.  
- **RENAME** – change a node’s `text`.  
- **MOVE from→to** – relocate a subtree by path.  
- **DELETE** – remove the node at path (use sparingly; prefer linking or archiving).  

> Always include: **Target path** and **Mode** above the payload.

---

## Workflow (recipe Dimmi follows every time)
1. **Intent ping (1 line)** – “Fresh Arkhive?” or “Integrate into existing path X?”  
2. **Ingest & Normalize**  
   - Trim whitespace, decode HTML entities, collapse duplicate spaces.  
   - Split any “Title — description” into `text=Title`, `_note=description`.  
3. **Extract Top‑Level Themes** (for fresh builds)  
   - Cluster headings/keywords; choose 3–12 top categories.  
   - **Name the root from context** (file title, dominant theme). Don’t inject WHO/WHAT/WHY unless explicitly requested for Uni‑Arkhive.  
4. **Deduplicate / Canonicalize**  
   - Normalize titles for compare: lowercase, strip punctuation/emoji, singularize; fuzzy ≥0.85 → candidate dupe.  
   - Keep one canonical node; for others: create stubs with `x_link="…"` back to the canonical.  
5. **Patch Strategy**  
   - If user will **import** into SimpleMind → emit **full OPML wrapper** around the branch.  
   - If user will **edit in place** → emit **child‑only** `<outline>` payload.  
6. **Output**  
   - Provide **Target path** + **Mode** + **Payload**.  
   - Keep payload minimal (no redundant ancestors).  
7. **Log & Review**  
   - Add `@@review` in `_note` when merge is non‑trivial.  
   - Add `x_src` on canonical nodes to remember where ideas came from.

---

## Templates (copy–paste)

### A) Fresh Arkhive (full wrapper)
```xml
<?xml version="1.0"?>
<opml version="1.0">
  <head><title>{TITLE}</title></head>
  <body>
    <outline text="{TITLE}" _note="{one‑line context}">
      {top‑level branches here}
    </outline>
  </body>
</opml>
```

### B) Branch import (full wrapper for one branch)
```xml
<?xml version="1.0"?>
<opml version="1.0">
  <head><title>{BRANCH_TITLE}</title></head>
  <body>
    <outline text="{BRANCH_TITLE}" _note="{purpose}">
      {children}
    </outline>
  </body>
</opml>
```

### C) In‑place edit (child‑only payload)
```
Target: /{ROOT}/{PATH}
Mode: INSERT | REPLACE CHILDREN | RENAME | MOVE from A to B | DELETE

<!-- Paste only the children below; do not wrap with root/path -->
<outline text="Child A" _note="..."/>
<outline text="Child B" _note="..."/>
```

### Combining structures (merges without mess)
Map candidates: For each incoming node B, find closest A in target by normalized title + parent context.

If match ≥0.85 → merge:

Keep A’s title; keep B’s _note by appending to A’s _note (prefix Src: {x_src}), or create child `<outline text="Notes" _note="…"/>`.

Move B’s unique children under A; for duplicates, add x_link to canonical subpaths.

If no match → insert under the specified target path.

Never re‑nest ancestors: emit only children for the target, not the whole path again.

Conflicts: If two nodes must remain distinct, suffix context into _note (not the title).

---

## Style rules (menu cleanliness)
- Titles: no descriptions, no trailing punctuation.
- Descriptions → `_note`.
- Emojis allowed on leaf items; avoid in canonical category titles.
- Ampersands & special chars escaped: `&amp;`, `&lt;`, `&gt;`.

---

## SimpleMind specifics (what bites, how to avoid)
- Importing requires full OPML wrapper; pasting bare `<outline>` is unreliable.
- In‑place pasting inside an existing node: use child‑only payload; do not include the path’s ancestors.
- Common gotcha: double nesting (pasting ARKHIVE/CANON/Systems inside Systems). Fix by using child‑only payload.

---

## Examples (short, real)
1) Convert a chat thread into a fresh Arkhive (context‑named)
```xml
<?xml version="1.0"?>
<opml version="1.0">
  <head><title>Universal UI</title></head>
  <body>
    <outline text="Universal UI" _note="Seeded from UI standardization thread.">
      <outline text="Vision" _note="Universal interaction layer; cross‑platform."/>
      <outline text="Pipeline" _note="Ingest → Normalize → Semantics → Render → Learn">
        <outline text="Ingest"/><outline text="Normalize"/><outline text="Semantics"/><outline text="Render"/><outline text="Learn"/>
      </outline>
      <outline text="Data Sources" _note="Accessibility APIs + OCR fallback"/>
      <outline text="Ontology (Uni‑UI Schema)"/>
      <outline text="Rendering"/>
      <outline text="MVP Path"/>
    </outline>
  </body>
</opml>
```

2) Integrate a branch (Connectivity) into UniSettings (child‑only)
```
Target: /UniSettings
Mode: INSERT

<outline text="2. Connectivity &amp; Networks" _note="Configures network connections and sharing options.">
  <outline text="Wi‑Fi &amp; Ethernet" _note="Manage wireless and wired connections."/>
  <outline text="Bluetooth" _note="Pair and manage Bluetooth devices."/>
  <outline text="Mobile Plans &amp; Data Usage" _note="Monitor and configure cellular settings."/>
  <outline text="NFC &amp; Payment" _note="Manage payment and NFC settings."/>
  <outline text="Flight Mode" _note="Toggle airplane mode."/>
  <outline text="Hotspot, Tethering &amp; Personal Hotspot" _note="Share device’s internet connection."/>
</outline>
```

3) Canonicalize duplicates via links (Device Management)
```
Target: /ARKHIVE/CANON/Systems
Mode: INSERT

<outline text="Device Management" _note="Canonicalized from multiple sources." x_src="amal.opml">
  <outline text="System Settings" x_link="/ARKHIVE/CANON/Systems/System Settings"/>
  <outline text="Navigation &amp; Gestures" x_link="/ARKHIVE/CANON/Systems/Navigation &amp; Gestures"/>
</outline>
```

---

## Checks (fast sanity before handing off)
- XML header present; `<opml version="1.0">` used.
- Exactly one root `<outline>` in wrapper files.
- No ancestor wrappers in child‑only payload.
- Descriptions live in `_note` (not in text).
- Special chars properly escaped.

---

## Post‑Prompts (wire it into the workflow)
@@create path:"abilities/structure-wrangler.md" _note:"New ability file."

@@ask What’s the target path and mode? (INSERT / REPLACE CHILDREN / MOVE / RENAME / DELETE)

@@todo canonicalize: System Settings, File Management

@@review merge: /UniSettings (items 7–17) into existing root

@@commit applied: Connectivity & Networks → /UniSettings

---

## Changelog
- 0.3 – Added SimpleMind import rules; clarified wrapper vs child‑only; added conflict/merge recipe.
- 0.2 – Introduced path+mode protocol; provenance/linking (x_src, x_link).
- 0.1 – Base ability: ingest → normalize → emit OPML.
