#!/usr/bin/env python3
"""Refresh the STARTHERE knowledge kernel."""
import json
import sys
from pathlib import Path

ROOT = Path(__file__).parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from dimmi_parser import build_graph
from generate_opml import write_opml

# PowerShell: python refresh.py
# Bash: python3 refresh.py

def build_report(graph):
    lines = ["# Post-Prompt Report"]
    for fname, data in graph["files"].items():
        lines.append(f"\n## {fname}")
        for p in data["prompts"]:
            lines.append(f"- {p['type']}: {p['body']}")
    actions = []
    for fname, data in graph["files"].items():
        for p in data["prompts"]:
            if p["type"] in {"ASK", "TODO"}:
                prio = p["args"].get("priority", "")
                actions.append((prio, fname, p))
    actions.sort(key=lambda x: (x[0], x[1]))
    lines.append("\n## Next actions")
    for prio, fname, p in actions:
        tag = f"[{prio}] " if prio else ""
        lines.append(f"- {tag}{fname}: {p['body']}")
    return "\n".join(lines)


def main():
    root = ROOT
    out = root / "out"
    out.mkdir(exist_ok=True)
    graph = build_graph(root)
    (out / "graph.json").write_text(json.dumps(graph, indent=2), encoding="utf-8")
    write_opml(graph, out / "structure.opml")
    (out / "report.md").write_text(build_report(graph), encoding="utf-8")
    summary = {ptype: 0 for ptype in ["ASK", "TODO", "LINK", "GOTO", "APPEND", "REFRESH"]}
    for data in graph["files"].values():
        for p in data["prompts"]:
            if p["type"] in summary:
                summary[p["type"]] += 1
    print(f"Parsed {len(graph['files'])} files")
    print("Items:", " ".join(f"{k}={v}" for k, v in summary.items()))
    print("OPML:", out / "structure.opml")


if __name__ == "__main__":
    main()
