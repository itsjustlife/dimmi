import os
import re
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).parent
ARKHIVE_SOURCE = ROOT / 'Arkhive'
DEST_BASE = ROOT / 'O' / 'Arkhive'

BRANCH_FILES = {
    'Who': 'Arkhive-Who.txt',
    'What': 'Arkhive-What.txt',
    'Where': 'Arkhive-Where.txt',
    'When': 'Arkhive-When.txt',
    'Why': 'Arkhive-Why.txt',
    'How': 'Arkhive-How.txt',
}


def slugify(text: str) -> str:
    text = re.sub(r'[^A-Za-z0-9]+', '_', text)
    return text.strip('_')


def extract_opml(path: Path):
    content = path.read_text(encoding='utf-8')
    start = content.find('<opml')
    if start == -1:
        return None
    end = content.find('</opml>', start)
    if end == -1:
        return None
    opml_content = content[start:end + len('</opml>')]
    # Escape bare ampersands which break XML parsing
    opml_content = re.sub(
        r'&(?!amp;|lt;|gt;|apos;|quot;|#\d+;|#x[0-9a-fA-F]+;)',
        '&amp;',
        opml_content,
    )
    return ET.fromstring(opml_content)


def write_note(directory: Path, note: str):
    if note:
        directory.mkdir(parents=True, exist_ok=True)
        (directory / 'README.md').write_text(note + '\n', encoding='utf-8')


def create_structure(node: ET.Element, dest: Path):
    note = node.attrib.get('_note')
    write_note(dest, note)
    for child in node.findall('outline'):
        name = slugify(child.attrib.get('text', ''))
        if not name:
            continue
        children = child.findall('outline')
        if children:
            dir_path = dest / name
            dir_path.mkdir(parents=True, exist_ok=True)
            note_child = child.attrib.get('_note')
            write_note(dir_path, note_child)
            create_structure(child, dir_path)
        else:
            dest.mkdir(parents=True, exist_ok=True)
            file_path = dest / f"{name}.md"
            note_child = child.attrib.get('_note', '')
            file_path.write_text(note_child + '\n', encoding='utf-8')


def main():
    for branch, filename in BRANCH_FILES.items():
        source = ARKHIVE_SOURCE / filename
        if not source.exists():
            continue
        root = extract_opml(source)
        if root is None:
            continue
        dest_dir = DEST_BASE / branch
        dest_dir.mkdir(parents=True, exist_ok=True)
        # Find first outline element (root of OPML body)
        body = root.find('body')
        if body is None:
            continue
        outline = body.find('outline')
        if outline is None:
            continue
        create_structure(outline, dest_dir)


if __name__ == '__main__':
    main()
