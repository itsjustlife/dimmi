import os
import json
import html
import webbrowser
import tkinter as tk
from tkinter import ttk, filedialog, messagebox
from pathlib import Path
import xml.etree.ElementTree as ET

# -------------------------
# Utilities
# -------------------------

def sanitize_filename(name: str) -> str:
    """
    Make a safe filename: keep letters, numbers, spaces, _ and - .
    Trim trailing dots/spaces (Windows-safe).
    """
    name = name.strip().replace(os.sep, "_")
    safe = []
    for ch in name:
        if ch.isalnum() or ch in (" ", "_", "-"):
            safe.append(ch)
        else:
            safe.append("_")
    # collapse multiple underscores
    s = "".join(safe)
    s = "_".join(filter(None, s.split("_")))
    s = s.rstrip(" .")
    return s or "page"

def ensure_html_ext(filename: str) -> str:
    base = Path(filename).stem if filename else ""
    base = sanitize_filename(base)
    return f"{base}.html"

def count_nodes(struct: dict) -> int:
    total = 0
    for _, node in struct.items():
        total += 1
        total += count_nodes(node.get("subfolders", {}))
    return total

def json_load(path: Path) -> dict:
    with path.open("r", encoding="utf-8") as f:
        return json.load(f)

def opml_load(path: Path) -> dict:
    # Parse OPML -> the same dict shape as JSON path
    tree = ET.parse(path)
    root = tree.getroot()
    body = root.find("body")
    if body is None:
        return {}
    return parse_opml_outline(body)

def parse_opml_outline(element) -> dict:
    """
    OPML outline -> {
      'Folder Name': {
         '_note': str,
         '_note_filename': str,
         'subfolders': {...}
      }, ...
    }
    We also support a legacy pattern: _note may contain "filename - content"
    """
    out = {}
    for outline in element.findall("outline"):
        folder_name = outline.attrib.get("text", "Untitled")
        note_attr = outline.attrib.get("_note", "")
        _note_filename = outline.attrib.get("_note_filename", "")

        # Legacy "filename - content"
        note_text = note_attr
        if not _note_filename and " - " in note_attr:
            potential, rest = note_attr.split(" - ", 1)
            # If the left part looks like a filename, treat it as such
            if "." in potential or len(potential.split()) <= 3:
                _note_filename = potential.strip()
                note_text = rest

        subfolders = parse_opml_outline(outline)
        out[folder_name] = {
            "_note": note_text,
            "_note_filename": _note_filename,
            "subfolders": subfolders
        }
    return out

def transform_structure_for_generation(struct: dict) -> dict:
    """
    Convert from Arkhivist-style keys to internal keys and normalize filenames/content.
    Input nodes: {'_note', '_note_filename', 'subfolders'}
    Output nodes: {'note', 'note_filename', 'subfolders'} with .html enforced.
    """
    result = {}
    for folder_name, content in struct.items():
        note = content.get("_note", "") or ""
        note_filename = content.get("_note_filename", "") or ""

        # Enforce .html extension and sanitize
        if note_filename:
            note_filename = ensure_html_ext(note_filename)
        else:
            # default from folder name
            note_filename = ensure_html_ext(folder_name)

        sub = transform_structure_for_generation(content.get("subfolders", {}))
        result[folder_name] = {
            "note": note,
            "note_filename": note_filename,
            "subfolders": sub
        }
    return result

# -------------------------
# HTML generation
# -------------------------

CSS_INLINE = """
/* Basic, clean styles */
* { box-sizing: border-box; }
body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
.header { background: #222; color: #fff; padding: 12px 16px; }
.wrapper { display: flex; min-height: 100vh; }
nav { width: 280px; background: #111; color: #ddd; overflow-y: auto; }
nav ul { list-style: none; margin: 0; padding: 8px 0 24px; }
nav li { margin: 0; padding: 0; }
nav a { display: block; padding: 10px 14px; color: #ddd; text-decoration: none; font-size: 14px; }
nav a:hover { background: #222; color: #fff; }
nav a.active { background: #0a84ff; color: #fff; }
nav .folder { padding: 10px 14px; font-weight: 600; color: #9ad; }
nav ul ul { padding-left: 10px; border-left: 1px solid #222; margin-left: 8px; }
main { flex: 1; padding: 24px; background: #f7f7f8; color: #111; }
main h1 { margin-top: 0; }
pre.note { white-space: pre-wrap; line-height: 1.45; background: #fff; padding: 16px; border-radius: 8px; border: 1px solid #ddd; }
.footer { padding: 12px 16px; font-size: 12px; color: #666; }
"""

HTML_TEMPLATE = """<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>{title}</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
{css}
</style>
</head>
<body>
<div class="header">{root_title}</div>
<div class="wrapper">
  <nav>
    {menu}
  </nav>
  <main>
    <h1>{title}</h1>
    <pre class="note">{content}</pre>
    <div class="footer">Generated by HTML Generator</div>
  </main>
</div>
</body>
</html>
"""

def build_menu_html(struct_root: dict, output_root: Path, current_dir: Path, current_file: Path) -> str:
    """
    Build a nested <ul> menu with links that are RELATIVE to the current_dir.
    Highlight the current_file link.
    """
    def node_target_path(path_list, node) -> Path:
        # output_root / <folders...> / <note_filename>
        return output_root.joinpath(*path_list, node["note_filename"])

    def mk_list(struct: dict, path_list: list) -> str:
        # path_list is the sequence of folder names from root to this level
        items_html = []
        for folder_name, node in struct.items():
            target = node_target_path(path_list + [folder_name], node)
            rel = os.path.relpath(target, start=current_dir).replace("\\", "/")
            active = ' class="active"' if target.resolve() == current_file.resolve() else ""
            # Menu entry anchor
            anchor = f'<a href="{rel}"{active}>{html.escape(folder_name)}</a>'
            # Recurse for children
            if node.get("subfolders"):
                child_html = mk_list(node["subfolders"], path_list + [folder_name])
                li = f'<li><div class="folder">{html.escape(folder_name)}</div><ul>{child_html}</ul></li>'
                # Also include direct link to the page itself above children:
                # Put the page link as first child item (optional)
                page_link_li = f'<li>{anchor}</li>'
                li = f'<li><ul>{page_link_li}{child_html}</ul></li>'
            else:
                li = f"<li>{anchor}</li>"
            items_html.append(li)
        return "".join(items_html)

    return f"<ul>{mk_list(struct_root, [])}</ul>"

def generate_all_pages(struct_root: dict, output_root: Path, progress_cb=None, status_cb=None):
    """
    struct_root shape:
      { 'FolderName': {'note': str, 'note_filename': str, 'subfolders': {...}}, ... }
    """
    total = count_nodes(struct_root)
    done = 0

    def walk(path_list, struct):
        nonlocal done
        for folder_name, node in struct.items():
            folder_dir = output_root.joinpath(*path_list, folder_name)
            folder_dir.mkdir(parents=True, exist_ok=True)

            note_text = node.get("note", "") or ""
            # Escape HTML; preserve line breaks via <pre>
            safe_note = html.escape(note_text)

            current_file = folder_dir / node["note_filename"]
            # Build menu (relative to this page)
            menu_html = build_menu_html(struct_root, output_root, current_dir=folder_dir, current_file=current_file)

            # Compose page
            page = HTML_TEMPLATE.format(
                css=CSS_INLINE,
                root_title=html.escape(output_root.name),
                menu=menu_html,
                title=html.escape(folder_name),
                content=safe_note
            )

            current_file.write_text(page, encoding="utf-8")

            done += 1
            if progress_cb:
                progress_cb(done, total)
            if status_cb:
                status_cb(f"Wrote: {current_file}")

            # Recurse
            if node.get("subfolders"):
                walk(path_list + [folder_name], node["subfolders"])

    walk([], struct_root)

# -------------------------
# GUI App
# -------------------------

class HTMLGeneratorUI:
    def __init__(self, root):
        self.root = root
        self.root.title("HTML Generator (Arkhivist partner)")
        self.root.geometry("760x520")
        self.root.minsize(720, 520)

        self.input_path = tk.StringVar()
        self.output_dir = tk.StringVar()
        self.format_var = tk.StringVar(value="auto")  # auto/json/opml
        self.status_var = tk.StringVar(value="Pick an input and an output, then Generate.")

        # Layout
        pad = {"padx": 10, "pady": 8}

        top = ttk.Frame(root)
        top.pack(fill="x", **pad)

        # Input
        in_frame = ttk.LabelFrame(top, text="Input")
        in_frame.pack(fill="x", **pad)

        ttk.Label(in_frame, text="Input file (.json or .opml)").grid(row=0, column=0, sticky="w")
        in_entry = ttk.Entry(in_frame, textvariable=self.input_path)
        in_entry.grid(row=1, column=0, sticky="we", padx=(0, 8))
        in_btn = ttk.Button(in_frame, text="Browse…", command=self.choose_input)
        in_btn.grid(row=1, column=1, sticky="w")
        in_frame.columnconfigure(0, weight=1)

        # Format
        fmt_frame = ttk.Frame(in_frame)
        fmt_frame.grid(row=2, column=0, columnspan=2, sticky="w", pady=(6,0))
        ttk.Label(fmt_frame, text="Format:").pack(side="left")
        ttk.Radiobutton(fmt_frame, text="Auto", variable=self.format_var, value="auto").pack(side="left")
        ttk.Radiobutton(fmt_frame, text="JSON", variable=self.format_var, value="json").pack(side="left")
        ttk.Radiobutton(fmt_frame, text="OPML", variable=self.format_var, value="opml").pack(side="left")

        # Output
        out_frame = ttk.LabelFrame(top, text="Output")
        out_frame.pack(fill="x", **pad)

        ttk.Label(out_frame, text="Output directory").grid(row=0, column=0, sticky="w")
        out_entry = ttk.Entry(out_frame, textvariable=self.output_dir)
        out_entry.grid(row=1, column=0, sticky="we", padx=(0, 8))
        out_btn = ttk.Button(out_frame, text="Choose…", command=self.choose_output)
        out_btn.grid(row=1, column=1, sticky="w")
        out_frame.columnconfigure(0, weight=1)

        # Actions
        act_frame = ttk.Frame(top)
        act_frame.pack(fill="x", **pad)
        self.gen_btn = ttk.Button(act_frame, text="Generate HTML", command=self.on_generate)
        self.gen_btn.pack(side="left")
        ttk.Button(act_frame, text="Open Output Folder", command=self.open_output).pack(side="left", padx=(8,0))

        # Progress + status
        prog_frame = ttk.Frame(root)
        prog_frame.pack(fill="x", **pad)
        self.progress = ttk.Progressbar(prog_frame, mode="determinate")
        self.progress.pack(fill="x")
        self.status_label = ttk.Label(root, textvariable=self.status_var, anchor="w")
        self.status_label.pack(fill="x", padx=10, pady=(0,10))

        # Log
        log_frame = ttk.LabelFrame(root, text="Log")
        log_frame.pack(fill="both", expand=True, padx=10, pady=(0,10))
        self.log = tk.Text(log_frame, height=12, wrap="word")
        self.log.pack(fill="both", expand=True)
        self.log.configure(state="disabled")

    def choose_input(self):
        path = filedialog.askopenfilename(
            title="Choose JSON or OPML",
            filetypes=[("Arkhivist exports", "*.json *.opml"), ("All files", "*.*")]
        )
        if path:
            self.input_path.set(path)
            # auto-set format
            ext = Path(path).suffix.lower()
            if self.format_var.get() == "auto":
                if ext == ".json":
                    self.format_var.set("json")
                elif ext == ".opml":
                    self.format_var.set("opml")

    def choose_output(self):
        directory = filedialog.askdirectory(title="Choose output folder")
        if directory:
            self.output_dir.set(directory)

    def log_line(self, text):
        self.log.configure(state="normal")
        self.log.insert("end", text + "\n")
        self.log.see("end")
        self.log.configure(state="disabled")

    def set_status(self, text):
        self.status_var.set(text)
        self.root.update_idletasks()

    def open_output(self):
        out = self.output_dir.get().strip()
        if not out:
            messagebox.showinfo("Open Output", "No output directory selected.")
            return
        p = Path(out)
        if not p.exists():
            messagebox.showinfo("Open Output", "Output directory does not exist yet.")
            return
        # Open folder in OS file browser
        try:
            if os.name == "nt":
                os.startfile(str(p))
            elif sys.platform == "darwin":
                os.system(f'open "{p}"')
            else:
                os.system(f'xdg-open "{p}"')
        except Exception as e:
            messagebox.showwarning("Open Output", f"Couldn't open folder:\n{e}")

    def on_generate(self):
        in_path = Path(self.input_path.get().strip())
        out_dir = Path(self.output_dir.get().strip())
        fmt = self.format_var.get()

        if not in_path.exists():
            messagebox.showwarning("Generate", "Please choose a valid input file.")
            return
        if fmt not in ("json", "opml", "auto"):
            messagebox.showwarning("Generate", "Please choose a valid format.")
            return
        if fmt == "auto":
            ext = in_path.suffix.lower()
            if ext == ".json":
                fmt = "json"
            elif ext == ".opml":
                fmt = "opml"
            else:
                messagebox.showwarning("Generate", "Could not infer format. Choose JSON or OPML.")
                return

        if not out_dir:
            messagebox.showwarning("Generate", "Please choose an output directory.")
            return
        out_dir.mkdir(parents=True, exist_ok=True)

        # Load structure
        try:
            if fmt == "json":
                raw_struct = json_load(in_path)
            else:
                raw_struct = opml_load(in_path)
        except Exception as e:
            messagebox.showerror("Load Error", f"Failed to load {fmt.upper()}:\n{e}")
            return

        # Expect top-level dict
        if not isinstance(raw_struct, dict):
            messagebox.showerror("Format Error", "Input must be a dictionary of folders.")
            return

        # Normalize/transform to internal shape
        try:
            struct = transform_structure_for_generation(raw_struct)
        except Exception as e:
            messagebox.showerror("Transform Error", f"Failed to process structure:\n{e}")
            return

        # Generate pages
        total = max(1, count_nodes(struct))
        self.progress.configure(maximum=total, value=0)
        self.log_line(f"Generating into: {out_dir}")
        self.set_status("Generating HTML…")
        self.gen_btn.configure(state="disabled")

        def progress_cb(done, total_):
            self.progress.configure(value=done)
            if done % 5 == 0 or done == total_:
                self.root.update_idletasks()

        def status_cb(line):
            self.log_line(line)

        try:
            generate_all_pages(struct, out_dir, progress_cb=progress_cb, status_cb=status_cb)
        except Exception as e:
            self.gen_btn.configure(state="normal")
            self.set_status("Error.")
            messagebox.showerror("Generation Error", f"{e}")
            return

        self.gen_btn.configure(state="normal")
        self.set_status("Done.")
        self.log_line("✅ Finished.")
        # Optionally open the first top-level page
        try:
            first = next(iter(struct.items()))
            first_name, first_node = first
            first_path = out_dir / first_name / first_node["note_filename"]
            if first_path.exists():
                webbrowser.open(first_path.as_uri())
        except Exception:
            pass

def main():
    root = tk.Tk()
    # nice default ttk theme if available
    try:
        root.call("source", "sun-valley.tcl")  # ignored if missing
        ttk.Style().theme_use("sun-valley-dark")
    except Exception:
        pass
    app = HTMLGeneratorUI(root)
    root.mainloop()

if __name__ == "__main__":
    main()
