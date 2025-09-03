#!/usr/bin/env python3
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent / 'O' / 'Arkhive'

START = Path(__file__).resolve().parent.parent / 'O' / 'START.md'
HUB = Path(__file__).resolve().parent.parent / 'O' / 'HUB' / 'README.md'
ARKHIVE_INDEX = Path(__file__).resolve().parent.parent / 'O' / 'Arkhive' / 'Arkhive.md'


def compute_rel(from_path: Path, to_path: Path) -> str:
    return os.path.relpath(to_path, from_path.parent).replace('\\', '/')


def breadcrumb(path: Path) -> str:
    rel_start = compute_rel(path, START)
    rel_hub = compute_rel(path, HUB)
    rel_arkhive = compute_rel(path, ARKHIVE_INDEX)
    return f"[⟵ START]({rel_start}) · [Arkhive]({rel_arkhive}) · [Hub]({rel_hub})"


def atomic_write(path: Path, content: str) -> None:
    tmp = path.with_suffix(path.suffix + '.tmp')
    with open(tmp, 'w', encoding='utf-8') as f:
        f.write(content)
    os.replace(tmp, path)


def main() -> None:
    for txt_path in ROOT.rglob('*.txt'):
        md_path = txt_path.with_suffix('.md')
        if md_path.exists():
            continue
        try:
            text = txt_path.read_text(encoding='utf-8')
        except FileNotFoundError:
            # skip broken symlinks or missing files
            continue
        title = txt_path.stem.replace('_', ' ').replace('-', ' ').title()
        header = breadcrumb(md_path)
        content = f"{header}\n\n# {title}\n\n{text}\n"
        atomic_write(md_path, content)
        print(f"Converted {txt_path} -> {md_path}")


if __name__ == '__main__':
    main()
