#!/usr/bin/env python3
"""Repository organizer utilities.

This script analyses the repository and can stage a simplified layout as
specified in project guidelines.  It purposefully avoids touching code or
configuration files and focuses on Markdown/TXT knowledge documents.

The script exposes several subcommands:

inventory  - scan repository and write defrag/repo_map.json,
             defrag/repo_tree.txt and defrag/summary.md.
plan       - plan knowledge moves and app registry; writes
             defrag/move_plan.json and defrag/move_plan.md.
stage      - create Apps/ registry symlinks and the initial DIRECTORY.md.
apply      - apply planned knowledge moves (requires --apply).
map        - regenerate START/HUB maps.
history    - snapshot before/after trees and append to WHEN-LOG.md.

Each subcommand accepts --dry-run to preview actions where appropriate.

The implementation is intentionally lightweight.  It aims to provide a
solid starting point while staying safe to run on arbitrary repositories.
"""

from __future__ import annotations

import argparse
import datetime as _dt
import json
import os
import shutil
from pathlib import Path
from typing import Dict, List, Tuple

# ---------------------------------------------------------------------------
# Helpers

REPO_ROOT = Path(__file__).resolve().parents[1]
DEF_DIR = REPO_ROOT / "defrag"
APP_MARKERS = {
    "python": ["pyproject.toml", "setup.py", "requirements.txt", "manage.py"],
    "node": ["package.json"],
    "mobile": ["*.xcodeproj", "build.gradle", "settings.gradle"],
}
KNOWLEDGE_HINTS = [
    "arkhive",
    "dimmi",
    "start",
    "mind",
    "memory",
    "commands",
    "proprompt",
]


def ensure_dir(path: Path) -> None:
    path.mkdir(parents=True, exist_ok=True)


def rel(path: Path) -> str:
    return os.path.relpath(path, REPO_ROOT)


def write_json(path: Path, data) -> None:
    ensure_dir(path.parent)
    with path.open("w", encoding="utf-8") as fh:
        json.dump(data, fh, indent=2, sort_keys=True)


def write_text(path: Path, text: str) -> None:
    ensure_dir(path.parent)
    with path.open("w", encoding="utf-8") as fh:
        fh.write(text)


def iter_files() -> List[Path]:
    return [p for p in REPO_ROOT.rglob("*") if not p.is_symlink()]


def detect_apps() -> Dict[str, List[str]]:
    """Detect application roots based on marker files."""
    apps: Dict[str, List[str]] = {"python": [], "node": [], "mobile": []}
    for path in REPO_ROOT.iterdir():
        if not path.is_dir():
            continue
        for lang, markers in APP_MARKERS.items():
            for marker in markers:
                if list(path.glob(marker)):
                    apps[lang].append(rel(path))
                    break
    # prune empty lists
    return {k: v for k, v in apps.items() if v}


def build_tree() -> str:
    """Return an ASCII tree representation of the repository."""
    lines: List[str] = []
    prefix_stack: List[str] = []

    def walk(dir_path: Path, prefix: str = ""):
        entries = sorted([p.name for p in dir_path.iterdir()])
        for i, name in enumerate(entries):
            full = dir_path / name
            connector = "└── " if i == len(entries) - 1 else "├── "
            lines.append(f"{prefix}{connector}{name}")
            if full.is_dir():
                new_prefix = prefix + ("    " if i == len(entries) - 1 else "│   ")
                walk(full, new_prefix)

    lines.append(REPO_ROOT.name)
    walk(REPO_ROOT)
    return "\n".join(lines)


def find_knowledge_files() -> List[Path]:
    files: List[Path] = []
    for p in REPO_ROOT.rglob("*"):
        if p.is_file() and p.suffix.lower() in {".md", ".txt"}:
            name = p.name.lower()
            if any(h in name for h in KNOWLEDGE_HINTS):
                files.append(p)
    return files

# ---------------------------------------------------------------------------
# Subcommands


def cmd_inventory(args) -> None:
    apps = detect_apps()
    all_paths = [rel(p) for p in iter_files()]
    repo_tree = build_tree()
    summary = {
        "files": sum(1 for p in iter_files() if p.is_file()),
        "dirs": sum(1 for p in iter_files() if p.is_dir()),
        "apps": apps,
    }
    write_json(DEF_DIR / "repo_map.json", {
        "apps": apps,
        "paths": all_paths,
    })
    write_text(DEF_DIR / "repo_tree.txt", repo_tree + "\n")
    write_text(DEF_DIR / "summary.md", json.dumps(summary, indent=2))
    print("Inventory written to defrag/ directory")


def cmd_plan(args) -> None:
    apps = detect_apps()
    knowledge = find_knowledge_files()
    moves = {}
    for src in knowledge:
        dest = REPO_ROOT / "O" / src.name
        moves[rel(src)] = rel(dest)
    plan = {
        "apps": apps,
        "moves": moves,
    }
    write_json(DEF_DIR / "move_plan.json", plan)
    lines = ["# Move Plan", "", "## Knowledge Moves"]
    for s, d in moves.items():
        lines.append(f"- {s} → {d}")
    if apps:
        lines.extend(["", "## Apps"])
        for lang, items in apps.items():
            for item in items:
                lines.append(f"- {lang}: {item}")
    write_text(DEF_DIR / "move_plan.md", "\n".join(lines) + "\n")
    print("Plan written to defrag/move_plan.*")


def cmd_stage(args) -> None:
    apps = detect_apps()
    apps_dir = REPO_ROOT / "Apps"
    if args.dry_run:
        print("[dry-run] would create Apps registry and DIRECTORY")
        return
    for lang, paths in apps.items():
        for p in paths:
            target = REPO_ROOT / p
            link_dir = apps_dir / lang
            ensure_dir(link_dir)
            link = link_dir / Path(p).name
            if link.exists() or link.is_symlink():
                continue
            os.symlink(target, link)
    # Apps README
    readme_lines = ["# Apps", ""]
    for lang, paths in apps.items():
        readme_lines.append(f"## {lang.capitalize()}")
        for p in paths:
            readme_lines.append(f"- [{p}]({p})")
        readme_lines.append("")
    write_text(apps_dir / "README.md", "\n".join(readme_lines))
    # DIRECTORY
    directory_lines = ["# DIRECTORY", ""]
    for p in sorted(iter_files()):
        if p.is_file():
            directory_lines.append(f"- [{rel(p)}]({rel(p)})")
    write_text(REPO_ROOT / "O" / "HUB" / "DIRECTORY.md", "\n".join(directory_lines) + "\n")
    print("Stage complete")


def convert_txt_to_md(path: Path) -> Path:
    text = path.read_text(encoding="utf-8")
    title = path.stem.replace("-", " ").title()
    md_path = path.with_suffix(".md")
    breadcrumbs = "[⟵ START](../START.md) · [Hub](../HUB/README.md)\n\n"
    footer = "\n\n[Back to START](../START.md)\n"
    write_text(md_path, f"# {title}\n\n{breadcrumbs}{text}{footer}")
    path.unlink()
    return md_path


def cmd_apply(args) -> None:
    if not args.apply:
        print("--apply flag required to move files")
        return
    plan_path = DEF_DIR / "move_plan.json"
    if not plan_path.exists():
        print("No move_plan.json found. Run plan first.")
        return
    plan = json.loads(plan_path.read_text())
    moves: Dict[str, str] = plan.get("moves", {})
    origins_dir = REPO_ROOT / "O" / "Dimmi" / "Memory" / "origins"
    ensure_dir(origins_dir)
    for src_rel, dest_rel in moves.items():
        src = REPO_ROOT / src_rel
        dest = REPO_ROOT / dest_rel
        if not src.exists():
            continue
        if src.suffix.lower() == ".txt":
            src = convert_txt_to_md(src)
            dest = dest.with_suffix(".md")
        ensure_dir(dest.parent)
        shutil.copy2(src, origins_dir / src.name)
        shutil.move(str(src), str(dest))
        # ensure breadcrumbs
        breadcrumbs = "[⟵ START](../START.md) · [Hub](../HUB/README.md)\n\n"
        content = dest.read_text(encoding="utf-8")
        if not content.startswith("[⟵ START]"):
            content = breadcrumbs + content + "\n[Back to START](../START.md)\n"
            write_text(dest, content)
    print("Applied knowledge moves")


def cmd_map(args) -> None:
    if getattr(args, "dry_run", False):
        print("[dry-run] would regenerate START and HUB maps")
        return
    start_path = REPO_ROOT / "O" / "START.md"
    lines = ["# START", "", "- [Hub](HUB/README.md)", "- [Arkhive](Arkhive/Arkhive.md)", "- [Dimmi](Dimmi/README.md)", "- [Apps](../Apps/README.md)"]
    write_text(start_path, "\n".join(lines) + "\n")
    hub_dir = REPO_ROOT / "O" / "HUB"
    ensure_dir(hub_dir)
    hub_readme = ["# Hub", "", "- [DIRECTORY](DIRECTORY.md)", "- [WHEN-LOG](WHEN-LOG.md)", "- [TODO](TODO.md)", "- [SUGGESTIONS](SUGGESTIONS.md)"]
    write_text(hub_dir / "README.md", "\n".join(hub_readme) + "\n")
    # simple branch READMEs
    for branch in ["Arkhive", "Dimmi"]:
        bdir = REPO_ROOT / "O" / branch
        if not bdir.exists():
            continue
        items = sorted(p.name for p in bdir.iterdir())
        lines = [f"# {branch}", ""] + [f"- [{name}]({name})" for name in items]
        lines.append("\n[Back to START](../START.md)")
        write_text(bdir / "README.md", "\n".join(lines))
    print("Map regenerated")


def cmd_history(args) -> None:
    if getattr(args, "dry_run", False):
        print("[dry-run] would record repository tree snapshot")
        return
    when_dir = REPO_ROOT / "O" / "Dimmi" / "Memory" / "When"
    ensure_dir(when_dir)
    timestamp = _dt.datetime.now().strftime("%Y%m%d%H%M%S")
    tree = build_tree() + "\n"
    before = when_dir / f"BEFORE-{timestamp}.tree.txt"
    after = when_dir / f"AFTER-{timestamp}.tree.txt"
    write_text(before, tree)
    write_text(after, tree)
    log = REPO_ROOT / "O" / "HUB" / "WHEN-LOG.md"
    entry = f"| {timestamp} | snapshot |\n"
    if log.exists():
        with log.open("a", encoding="utf-8") as fh:
            fh.write(entry)
    else:
        write_text(log, "| time | action |\n|---|---|\n" + entry)
    print("History recorded")

# ---------------------------------------------------------------------------


def main(argv: List[str] | None = None) -> None:
    parser = argparse.ArgumentParser(description="Repository organizer")
    sub = parser.add_subparsers(dest="cmd")

    p_inv = sub.add_parser("inventory", help="scan repository")
    p_inv.add_argument("--dry-run", action="store_true")
    p_inv.set_defaults(func=cmd_inventory)

    p_plan = sub.add_parser("plan", help="plan moves and app registry")
    p_plan.add_argument("--dry-run", action="store_true")
    p_plan.set_defaults(func=cmd_plan)

    p_stage = sub.add_parser("stage", help="create Apps registry and DIRECTORY")
    p_stage.add_argument("--dry-run", action="store_true")
    p_stage.set_defaults(func=cmd_stage)

    p_apply = sub.add_parser("apply", help="apply knowledge moves")
    p_apply.add_argument("--apply", action="store_true", help="confirm moves")
    p_apply.add_argument("--move-apps", action="store_true", help="also move apps")
    p_apply.set_defaults(func=cmd_apply)

    p_map = sub.add_parser("map", help="regenerate START/HUB pages")
    p_map.add_argument("--dry-run", action="store_true")
    p_map.set_defaults(func=cmd_map)

    p_hist = sub.add_parser("history", help="record repository tree snapshot")
    p_hist.add_argument("--dry-run", action="store_true")
    p_hist.set_defaults(func=cmd_history)

    args = parser.parse_args(argv)
    if not hasattr(args, "func"):
        parser.print_help()
        return
    args.func(args)


if __name__ == "__main__":
    main()
