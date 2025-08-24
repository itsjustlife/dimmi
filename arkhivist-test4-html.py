import tkinter as tk
from tkinter import ttk, filedialog, messagebox
import os
import json
import xml.etree.ElementTree as ET
import re
from html import escape as html_escape_builtin

# ---------------------------
# Helpers
# ---------------------------

INVALID_FS_CHARS = r'<>:"/\\|?*'

def safe_name(name: str) -> str:
    """
    Make a folder/file safe for the filesystem:
    - strip whitespace
    - replace invalid characters with underscore
    - collapse repeated underscores
    - keep unicode letters/numbers
    """
    if not isinstance(name, str):
        name = str(name)
    name = name.strip()
    name = ''.join('_' if ch in INVALID_FS_CHARS else ch for ch in name)
    name = re.sub(r'\s+', '_', name)
    name = re.sub(r'_+', '_', name)
    return name or "untitled"

def to_href(path: str) -> str:
    """Convert OS path to an href with forward slashes."""
    return path.replace('\\', '/')

def html_escape(s: str) -> str:
    """HTML-escape content safely."""
    return html_escape_builtin(s, quote=True)

# ---------------------------
# App
# ---------------------------

class MenuGeneratorApp:
    def __init__(self, root):
        self.root = root
        self.root.title("Arkhivist Menu Generator")
        self.root.geometry("820x520")
        self.root.minsize(740, 480)

        # Data
        self.structure = {}          # {display_name: {'_note': str, 'subfolders': {...}}}
        self.output_dir = None       # absolute path

        # UI
        self._build_ui()

    # ---------- UI ----------

    def _build_ui(self):
        # Top controls
        top = ttk.Frame(self.root)
        top.pack(fill=tk.X, padx=8, pady=8)

        ttk.Button(top, text="Load Structure (OPML/JSON)", command=self.load_structure).pack(side=tk.LEFT, padx=4)
        ttk.Button(top, text="Select Output Folder", command=self.select_output_dir).pack(side=tk.LEFT, padx=4)
        ttk.Button(top, text="Generate Site", command=self.generate_site).pack(side=tk.LEFT, padx=4)
        ttk.Button(top, text="Open Output", command=self.open_output).pack(side=tk.LEFT, padx=4)

        # Status
        self.status_var = tk.StringVar(value="Ready.")
        ttk.Label(self.root, textvariable=self.status_var, relief=tk.SUNKEN, anchor='w').pack(side=tk.BOTTOM, fill=tk.X)

        # Split: structure tree on left, preview summary on right
        paned = ttk.PanedWindow(self.root, orient=tk.HORIZONTAL)
        paned.pack(fill=tk.BOTH, expand=True, padx=8, pady=8)

        # Tree
        left = ttk.Frame(paned)
        paned.add(left, weight=1)

        self.tree = ttk.Treeview(left, columns=("note",), show="tree headings", selectmode="browse")
        self.tree.heading("#0", text="Folder")
        self.tree.heading("note", text="Note (preview)")
        self.tree.column("#0", width=240, stretch=True)
        self.tree.column("note", width=320, stretch=True)
        self.tree.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)

        yscroll = ttk.Scrollbar(left, orient=tk.VERTICAL, command=self.tree.yview)
        yscroll.pack(side=tk.RIGHT, fill=tk.Y)
        self.tree.configure(yscrollcommand=yscroll.set)

        # Right: details
        right = ttk.LabelFrame(paned, text="Selected Folder")
        paned.add(right, weight=1)

        self.sel_name = tk.StringVar()
        self.sel_note = tk.Text(right, height=12, wrap="word")
        ttk.Label(right, textvariable=self.sel_name, font=("Segoe UI", 11, "bold")).pack(anchor="w", padx=8, pady=(8, 4))
        self.sel_note.pack(fill=tk.BOTH, expand=True, padx=8, pady=(0, 8))

        self.tree.bind("<<TreeviewSelect>>", self._on_tree_select)

    # ---------- Load structure ----------

    def load_structure(self):
        fp = filedialog.askopenfilename(
            title="Select structure file (OPML or JSON)",
            filetypes=[("OPML", "*.opml"), ("JSON", "*.json"), ("All files", "*.*")]
        )
        if not fp:
            return
        try:
            if fp.lower().endswith(".opml"):
                self.structure = self._load_opml(fp)
            elif fp.lower().endswith(".json"):
                self.structure = self._load_json(fp)
            else:
                raise ValueError("Unsupported file type. Use .opml or .json")

            if not isinstance(self.structure, dict):
                raise ValueError("Parsed structure must be a dict of folders.")

            self._populate_tree()
            self.status_var.set(f"Loaded structure from: {fp}")
        except Exception as e:
            messagebox.showerror("Load Error", f"Could not load structure:\n{e}")

    def _load_json(self, fp: str) -> dict:
        with open(fp, "r", encoding="utf-8") as f:
            data = json.load(f)
        # Expecting { "FolderName": {"_note": "...", "subfolders": {...}}, ...}
        # Normalize just in case.
        return self._normalize_structure(data)

    def _load_opml(self, fp: str) -> dict:
        tree = ET.parse(fp)
        root = tree.getroot()
        body = root.find("body")
        if body is None:
            return {}

        def parse_outline(elem) -> dict:
            out = {}
            for node in elem.findall("outline"):
                name = node.attrib.get("text") or node.attrib.get("title") or "Untitled"
                note = node.attrib.get("_note", "")
                # Some exports pack "file - note content" in _note; we keep the full note as content.
                out[name] = {
                    "_note": note,
                    "subfolders": parse_outline(node)
                }
            return out

        return parse_outline(body)

    def _normalize_structure(self, obj) -> dict:
        """
        Ensure structure is exactly:
        {display_name: {'_note': str, 'subfolders': {...}}, ...}
        """
        if not isinstance(obj, dict):
            raise ValueError("JSON root must be an object/dict")

        norm = {}
        for k, v in obj.items():
            if isinstance(v, dict):
                norm[k] = {
                    "_note": v.get("_note", "") if isinstance(v.get("_note", ""), str) else "",
                    "subfolders": self._normalize_structure(v.get("subfolders", {}))
                }
            else:
                # Leaf with non-dict value -> treat as empty folder, display the value as note
                norm[k] = {"_note": str(v), "subfolders": {}}
        return norm

    # ---------- Tree + selection ----------

    def _populate_tree(self):
        self.tree.delete(*self.tree.get_children())

        def add(parent, struct: dict):
            for name, content in struct.items():
                note_preview = (content.get("_note") or "").strip().replace("\n", " ")
                if len(note_preview) > 80:
                    note_preview = note_preview[:77] + "..."
                node = self.tree.insert(parent, "end", text=name, values=(note_preview,), open=True)
                add(node, content.get("subfolders", {}))

        add("", self.structure)

    def _on_tree_select(self, _evt):
        sel = self.tree.selection()
        if not sel:
            self.sel_name.set("")
            self.sel_note.delete("1.0", tk.END)
            return

        # Reconstruct path to find the node
        item = sel[0]
        parts = []
        while item:
            parts.insert(0, self.tree.item(item, "text"))
            item = self.tree.parent(item)

        node = self._get_node_at(parts)
        self.sel_name.set(" / ".join(parts))
        self.sel_note.delete("1.0", tk.END)
        if node:
            self.sel_note.insert("1.0", node.get("_note", ""))

    def _get_node_at(self, parts):
        node = self.structure
        for p in parts:
            node = node.get(p)
            if not node:
                return None
            node = node if isinstance(node, dict) else {}
        return node

    # ---------- Output dir ----------

    def select_output_dir(self):
        d = filedialog.askdirectory(title="Select output folder")
        if d:
            self.output_dir = d
            self.status_var.set(f"Output directory: {d}")

    def open_output(self):
        if self.output_dir and os.path.isdir(self.output_dir):
            try:
                os.startfile(self.output_dir)  # Windows
            except Exception:
                # Fallback
                messagebox.showinfo("Open Output", f"Output folder:\n{self.output_dir}")
        else:
            messagebox.showwarning("Open Output", "No valid output folder selected.")

    # ---------- Site generation ----------

    def generate_site(self):
        if not self.structure:
            messagebox.showwarning("Generate Site", "Load a structure first.")
            return
        if not self.output_dir:
            messagebox.showwarning("Generate Site", "Select an output folder first.")
            return

        try:
            self._write_site()
            self.status_var.set(f"Site generated in: {self.output_dir}")
            messagebox.showinfo("Done", "Site generated successfully.")
        except Exception as e:
            messagebox.showerror("Generate Error", f"Failed to generate site:\n{e}")

    def _write_site(self):
        # 1) Write index.html at root
        index_menu_html = self._nav_html_for_depth(depth=0)
        index_html = self._wrap_html(
            title="Index",
            menu_html=index_menu_html,
            heading="Index",
            content="<p>Select a page from the menu.</p>"
        )
        with open(os.path.join(self.output_dir, "index.html"), "w", encoding="utf-8") as f:
            f.write(index_html)

        # 2) Write every folder page recursively
        for name, node in self.structure.items():
            self._write_folder_pages([name], node)

    def _write_folder_pages(self, path_parts, node):
        """
        path_parts: list of display names from root (e.g., ['WHO','People','Artists'])
        node: {'_note': str, 'subfolders': {...}}
        """
        safe_parts = [safe_name(p) for p in path_parts]
        folder_dir = os.path.join(self.output_dir, *safe_parts)
        os.makedirs(folder_dir, exist_ok=True)

        depth = len(path_parts)  # how many levels below root
        menu_html = self._nav_html_for_depth(depth)

        display_name = path_parts[-1]
        note = node.get("_note", "") or ""
        content_html = f"<p>{self._render_note(note)}</p>" if note.strip() else "<p><em>No content.</em></p>"

        page_fname = f"{safe_name(display_name)}.html"
        page_path = os.path.join(folder_dir, page_fname)
        html = self._wrap_html(
            title=display_name,
            menu_html=menu_html,
            heading=display_name,
            content=content_html
        )
        with open(page_path, "w", encoding="utf-8") as f:
            f.write(html)

        # Recurse
        for child_name, child_node in (node.get("subfolders") or {}).items():
            self._write_folder_pages(path_parts + [child_name], child_node)

    # ---------- Navigation (same structure, depth-adjusted links) ----------

    def _nav_html_for_depth(self, depth: int) -> str:
        """
        Build the SAME site-wide menu structure, but with hrefs adjusted
        to be correct relative to the current page depth.
        """
        prefix = "../" * depth  # how to get from current page directory back to site root
        return f"""
<nav class="site-nav">
    {self._build_menu(self.structure, prefix, parent_parts=[])}
</nav>
        """.strip()

    def _build_menu(self, struct: dict, prefix: str, parent_parts: list) -> str:
        """
        Recursive <ul> menu:
        - Links are always computed as: prefix + <root-relative path to target page>
        - Root-relative path: path_parts + [file.html]
        """
        html = ["<ul>"]
        for name, node in struct.items():
            target_parts = parent_parts + [name]
            safe_parts = [safe_name(p) for p in target_parts]
            href_rel = "/".join(safe_parts + [f"{safe_name(name)}.html"])
            link = to_href(prefix + href_rel)

            html.append(f'<li><a href="{html_escape(link)}">{html_escape(name)}</a>')
            subs = node.get("subfolders") or {}
            if subs:
                html.append(self._build_menu(subs, prefix, target_parts))
            html.append("</li>")
        html.append("</ul>")
        return "".join(html)

    # ---------- HTML templates ----------

    def _wrap_html(self, title: str, menu_html: str, heading: str, content: str) -> str:
        """
        Returns a complete HTML page with a top dropdown menu.
        """
        return f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>{html_escape(title)}</title>
<style>
    :root {{
        --bg: #0f172a;
        --bg2: #111827;
        --fg: #e5e7eb;
        --muted: #9ca3af;
        --accent: #3b82f6;
    }}
    * {{ box-sizing: border-box; }}
    body {{
        margin: 0;
        font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji","Segoe UI Emoji";
        background: var(--bg);
        color: var(--fg);
    }}
    .site-nav {{
        background: #1f2937;
        border-bottom: 1px solid #374151;
        position: sticky;
        top: 0;
        z-index: 100;
    }}
    .site-nav > ul {{
        list-style: none;
        margin: 0;
        padding: 0 12px;
        display: flex;
        gap: 8px;
    }}
    .site-nav li {{
        position: relative;
    }}
    .site-nav a {{
        display: block;
        padding: 10px 12px;
        text-decoration: none;
        color: var(--fg);
        white-space: nowrap;
    }}
    .site-nav a:hover {{
        background: #374151;
        color: #fff;
    }}
    /* Dropdowns */
    .site-nav li > ul {{
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        min-width: 220px;
        background: #1f2937;
        border: 1px solid #374151;
        padding: 6px 0;
        z-index: 999;
    }}
    .site-nav li:hover > ul {{
        display: block;
    }}
    .site-nav li > ul li {{
        display: block;
    }}
    /* Third level flyout */
    .site-nav li > ul li > ul {{
        top: 0;
        left: 100%;
    }}

    main {{
        max-width: 1100px;
        margin: 0 auto;
        padding: 20px 16px 40px;
    }}
    h1 {{
        margin: 18px 0 12px;
        font-size: 1.6rem;
    }}
    p {{ line-height: 1.6; color: var(--fg); }}
    .muted {{ color: var(--muted); }}
    .card {{
        background: var(--bg2);
        border: 1px solid #1f2937;
        border-radius: 10px;
        padding: 16px;
    }}
</style>
</head>
<body>
{menu_html}
<main>
  <h1>{html_escape(heading)}</h1>
  <div class="card">
    {content}
  </div>
</main>
</body>
</html>
"""

    def _render_note(self, note: str) -> str:
        # Simple note → HTML (preserve newlines)
        return html_escape(note).replace("\n", "<br>\n")


def main():
    root = tk.Tk()
    app = MenuGeneratorApp(root)
    root.mainloop()

if __name__ == "__main__":
    main()
