import json
import re
import shlex
from collections import defaultdict
from pathlib import Path

SKIP_DIRS = {'.git', '.gradle', 'build', '__pycache__', 'node_modules'}

PROMPT_LONG_RE = re.compile(r"!!(?P<type>\w+)\s*(?P<args>.*?)::\s*(?P<body>.*)")
PROMPT_SHORT_RE = re.compile(r"@(?P<type>\w+)@\s*(?P<body>.*)")
HEADING_RE = re.compile(r"^(#+)\s+(.*)")
META_RE = re.compile(r"^:::meta\s+(.*)")
ANCHOR_RE = re.compile(r"\{#([A-Za-z0-9_-]+)\}")

PROMPT_TYPES = {"ASK", "TODO", "GOTO", "LINK", "TAG", "APPEND", "REFRESH", "LOG"}


def slugify(text: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")


def parse_meta(line: str) -> dict:
    meta = {}
    m = META_RE.match(line)
    if not m:
        return meta
    parts = shlex.split(m.group(1))
    for part in parts:
        if "=" in part:
            k, v = part.split("=", 1)
            meta[k] = v.strip("\"")
    return meta


def parse_args(arg_str: str) -> dict:
    args = {}
    for part in shlex.split(arg_str):
        if "=" in part:
            k, v = part.split("=", 1)
            args[k] = v.strip("\"")
    return args


def parse_file(path: Path, base: Path) -> dict:
    rel = path.relative_to(base).as_posix()
    lines = path.read_text(encoding="utf-8", errors="ignore").splitlines()
    meta = {}
    sections = []
    prompts = []
    current_section = None
    for idx, line in enumerate(lines, 1):
        if idx == 1 and line.startswith(":::meta"):
            meta = parse_meta(line)
            continue
        hm = HEADING_RE.match(line)
        if hm:
            level = len(hm.group(1))
            title = hm.group(2).strip()
            am = ANCHOR_RE.search(title)
            if am:
                anchor = am.group(1)
                title = ANCHOR_RE.sub("", title).strip()
            else:
                anchor = slugify(title)
            current_section = {
                "id": anchor,
                "level": level,
                "title": title,
                "line": idx,
                "prompts": []
            }
            sections.append(current_section)
            continue
        lm = PROMPT_LONG_RE.match(line.strip())
        if lm:
            ptype = lm.group("type").upper()
            args = parse_args(lm.group("args"))
            body = lm.group("body").strip()
            prompt = {
                "type": ptype,
                "args": args,
                "body": body,
                "file": rel,
                "line": idx,
                "section": current_section["id"] if current_section else ""
            }
            prompts.append(prompt)
            if current_section:
                current_section["prompts"].append(prompt)
            continue
        sm = PROMPT_SHORT_RE.match(line.strip())
        if sm:
            ptype = sm.group("type").upper()
            body = sm.group("body").strip()
            prompt = {
                "type": ptype,
                "args": {},
                "body": body,
                "file": rel,
                "line": idx,
                "section": current_section["id"] if current_section else ""
            }
            prompts.append(prompt)
            if current_section:
                current_section["prompts"].append(prompt)
    title = meta.get("title") or (sections[0]["title"] if sections else path.stem)
    return {"title": title, "meta": meta, "sections": sections, "prompts": prompts}


def build_graph(root: Path) -> dict:
    files = {}
    links = []
    tags = defaultdict(list)
    for path in sorted(root.rglob("*.txt")):
        if any(part in SKIP_DIRS or part.startswith('.') for part in path.relative_to(root).parts):
            continue
        try:
            data = parse_file(path, root)
        except Exception:
            continue
        rel = path.relative_to(root).as_posix()
        files[rel] = data
        for p in data["prompts"]:
            if p["type"] == "GOTO":
                links.append({
                    "from": f"{rel}#{p['section']}",
                    "to": p["args"].get("path", ""),
                    "type": "GOTO"
                })
            elif p["type"] == "LINK":
                links.append({
                    "from": f"{rel}#{p['section']}",
                    "to": p["args"].get("url", ""),
                    "type": "LINK"
                })
            elif p["type"] == "TAG":
                name = p["args"].get("name")
                if name:
                    tags[name].append(f"{rel}#{p['section']}")
    return {"files": files, "links": links, "tags": tags}


if __name__ == "__main__":
    root = Path(__file__).parent
    graph = build_graph(root)
    print(json.dumps(graph, indent=2))
