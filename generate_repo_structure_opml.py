import os
from pathlib import Path
from xml.etree.ElementTree import Element, SubElement, ElementTree, indent

IGNORES = {'.git', '.gradle', 'build', '__pycache__'}


def is_text_file(path: Path) -> bool:
    """Return True if the file appears to be text based."""
    try:
        chunk = path.open('rb').read(1024)
        chunk.decode('utf-8')
        return True
    except Exception:
        return False


def build_outline(parent, path: Path):
    for child in sorted(path.iterdir(), key=lambda p: (p.is_file(), p.name.lower())):
        if child.name in IGNORES or child.name.startswith('.'):
            continue
        if child.is_dir():
            node = SubElement(parent, 'outline', text=child.name)
            build_outline(node, child)
        else:
            note = ''
            if is_text_file(child):
                try:
                    with child.open('r', encoding='utf-8', errors='ignore') as f:
                        first = f.readline().strip()
                        if first:
                            note = first[:100]
                except Exception:
                    note = ''
            attrs = {'text': child.name}
            if note:
                attrs['_note'] = note
            SubElement(parent, 'outline', **attrs)


def write_repo_opml(root: Path, out_path: Path):
    opml = Element('opml', version='1.0')
    head = SubElement(opml, 'head')
    title = SubElement(head, 'title')
    title.text = root.name
    body = SubElement(opml, 'body')
    top = SubElement(body, 'outline', text=root.name)
    build_outline(top, root)
    tree = ElementTree(opml)
    indent(tree, space="  ")
    tree.write(out_path, encoding='utf-8', xml_declaration=True)


if __name__ == '__main__':
    repo_root = Path(__file__).resolve().parent
    out_file = repo_root / 'repo_structure.opml'
    write_repo_opml(repo_root, out_file)
    print(f'Wrote {out_file}')
