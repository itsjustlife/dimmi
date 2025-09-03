from xml.etree.ElementTree import Element, SubElement, ElementTree
from pathlib import Path
try:
    from .dimmi_parser import build_graph
except ImportError:  # running as script
    from dimmi_parser import build_graph

PROMPT_EMOJI = {
    "TODO": "[TODO]",
    "ASK": "[ASK]",
}


def prompts_to_note(prompts):
    lines = []
    for p in prompts:
        if p["type"] in PROMPT_EMOJI:
            lines.append(f"{PROMPT_EMOJI[p['type']]} {p['body']}")
    return "\n".join(lines)


def add_links(outline, prompts):
    i = 1
    for p in prompts:
        if p["type"] == "LINK":
            outline.set(f"link{i}_type", "url")
            outline.set(f"link{i}_to", p["args"].get("url", ""))
            i += 1
        elif p["type"] == "GOTO":
            outline.set(f"link{i}_type", "goto")
            outline.set(f"link{i}_to", p["args"].get("path", ""))
            i += 1


def write_opml(graph, out_path: Path):
    opml = Element("opml", version="2.0")
    head = SubElement(opml, "head")
    title = SubElement(head, "title")
    title.text = "STARTHERE"
    body = SubElement(opml, "body")

    for fname, data in graph["files"].items():
        fnode = SubElement(body, "outline", text=fname)
        add_links(fnode, data["prompts"])
        note = prompts_to_note(data["prompts"])
        if note:
            fnode.set("_note", note)
        for sec in data["sections"]:
            snode = SubElement(fnode, "outline", text=sec["title"])
            add_links(snode, sec.get("prompts", []))
            snote = prompts_to_note(sec.get("prompts", []))
            if snote:
                snode.set("_note", snote)
    tree = ElementTree(opml)
    tree.write(out_path, encoding="utf-8", xml_declaration=True)


if __name__ == "__main__":
    root = Path(__file__).parent
    graph = build_graph(root)
    write_opml(graph, root / "out" / "structure.opml")
