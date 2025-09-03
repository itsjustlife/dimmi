#!/usr/bin/env python3
import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent / 'O'

LINK_RE = re.compile(r'\[[^\]]*\]\(([^)]+)\)')


def find_links(text: str):
    return LINK_RE.findall(text)


def main() -> None:
    broken = []
    for md in ROOT.rglob('*.md'):
        text = md.read_text(encoding='utf-8')
        for link in find_links(text):
            if re.match(r'^[a-z]+://', link):
                continue
            target = link.split('#', 1)[0]
            target_path = (md.parent / target).resolve()
            if not target_path.exists():
                broken.append((md.relative_to(ROOT.parent), link))
    if broken:
        print('File -> Broken Link -> Suggestion')
        for file, link in broken:
            print(f"{file} -> {link} -> check path or create file")
        sys.exit(1)
    else:
        print('No broken links found.')
        sys.exit(0)


if __name__ == '__main__':
    main()
