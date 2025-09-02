"""
Template module for DOOR Actions.
MENU = metadata the DOOR UI can read to attach buttons automatically.
"""

MENU = {
    "title": "Template Module",
    "description": "Example action that prints context and writes a note.",
    "tags": ["action", "template"]
}

def run(context):
    """
    context: dict with keys
      - repo_root: path to O/
      - current_item: dict for the selected item
      - log: callable(str) to append to the action log
    """
    log = context.get("log", print)
    log(f"[Template] Running with item: {context.get('current_item', {}).get('title','(none)')}")
    # Example side effect: create a note file if missing.
    import os, time
    p = os.path.join(context["repo_root"], "content", "template_run_note.txt")
    with open(p, "a", encoding="utf-8") as f:
        f.write(time.strftime("%Y-%m-%d %H:%M:%S") + " — Template run\n")
    return {"status": "ok"}
