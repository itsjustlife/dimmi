#!/usr/bin/env python3
import os
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent / 'O'
START = ROOT / 'START.md'
HUB = ROOT / 'HUB' / 'README.md'
ARKHIVE_INDEX = ROOT / 'Arkhive' / 'Arkhive.md'


def compute_rel(from_path: Path, to_path: Path) -> str:
    return os.path.relpath(to_path, from_path.parent).replace('\\', '/')


def atomic_write(path: Path, content: str) -> None:
    tmp = path.with_suffix(path.suffix + '.tmp')
    with open(tmp, 'w', encoding='utf-8') as f:
        f.write(content)
    os.replace(tmp, path)


def ensure_readmes() -> None:
    branch_root = ROOT / 'Arkhive'
    for sub in branch_root.iterdir():
        if not sub.is_dir():
            continue
        md_files = sorted([p for p in sub.glob('*.md') if p.name.lower() != 'readme.md'])
        lines = [f"- [{p.stem}]({p.name})" for p in md_files]
        readme_path = sub / 'README.md'
        crumb = breadcrumb(readme_path)
        content = f"{crumb}\n\n# {sub.name}\n\n" + "\n".join(lines) + ("\n" if lines else "")
        if readme_path.exists():
            current = readme_path.read_text(encoding='utf-8')
            if current == content:
                continue
        atomic_write(readme_path, content)


def update_start(branches: list[Path]) -> None:
    lines = []
    for readme in sorted(branches, key=lambda p: p.parent.name.lower()):
        rel = compute_rel(START, readme)
        name = readme.parent.name
        lines.append(f"- [{name}]({rel})")
    crumb = breadcrumb(START)
    content = f"{crumb}\n\n# START\n\n## Branches\n" + "\n".join(lines) + ("\n" if lines else "")
    atomic_write(START, content)


def breadcrumb(path: Path) -> str:
    rel_start = compute_rel(path, START)
    rel_hub = compute_rel(path, HUB)
    rel_arkhive = compute_rel(path, ARKHIVE_INDEX)
    return f"[⟵ START]({rel_start}) · [Arkhive]({rel_arkhive}) · [Hub]({rel_hub})"


def process_file(path: Path) -> bool:
    orig = path.read_text(encoding='utf-8')
    rel_start = compute_rel(path, START)
    rel_hub = compute_rel(path, HUB)
    rel_arkhive = compute_rel(path, ARKHIVE_INDEX)
    crumb = f"[⟵ START]({rel_start}) · [Arkhive]({rel_arkhive}) · [Hub]({rel_hub})"

    content = orig.replace('[[START]]', rel_start).replace('[[HUB]]', rel_hub)
    lines = content.splitlines()
    first_idx = next((i for i, l in enumerate(lines) if l.strip()), None)
    if first_idx is None:
        lines.insert(0, crumb)
    elif lines[first_idx].startswith('[⟵ START]'):
        lines[first_idx] = crumb
    else:
        lines.insert(first_idx, crumb)
    new_content = "\n".join(lines) + ("\n" if content.endswith('\n') else '')

    if new_content != orig:
        atomic_write(path, new_content)
        return True
    return False


def main() -> None:
    ensure_readmes()
    branches = list((ROOT / 'Arkhive').glob('*/README.md'))
    update_start(branches)

    changed = []
    for md in ROOT.rglob('*.md'):
        if process_file(md):
            changed.append(md)
    if changed:
        print('Updated:')
        for p in changed:
            print(' -', p.relative_to(ROOT.parent))
    else:
        print('No changes')


if __name__ == '__main__':
    main()
