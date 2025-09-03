import json
import os
import subprocess
import sys
import tkinter as tk
from tkinter import ttk, messagebox
from pathlib import Path

from STARTHERE.dimmi_parser import build_graph
from STARTHERE.refresh import main as refresh_main

ROOT = Path(__file__).parent
SETTINGS = ROOT / "out" / "settings.json"
PROMPT_TYPES = ["ASK", "TODO", "GOTO", "LINK", "TAG", "APPEND", "REFRESH", "LOG"]

def open_path(path: Path):
    if os.name == "nt":
        os.startfile(path)
    elif sys.platform == "darwin":
        subprocess.Popen(["open", str(path)])
    else:
        subprocess.Popen(["xdg-open", str(path)])


class DoorApp(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("DOOR")
        self.graph = {}
        self.current_file = None
        self.create_widgets()
        self.bind_events()
        self.load_settings()
        self.refresh_graph()

    def create_widgets(self):
        toolbar = tk.Frame(self)
        toolbar.pack(side=tk.TOP, fill=tk.X)
        tk.Button(toolbar, text="START", command=self.go_start).pack(side=tk.LEFT)
        tk.Button(toolbar, text="REFRESH", command=self.refresh_graph).pack(side=tk.LEFT)
        tk.Button(toolbar, text="EXPORT OPML", command=self.export_opml).pack(side=tk.LEFT)
        tk.Button(toolbar, text="OPEN Out/", command=lambda: open_path(ROOT / "out")).pack(side=tk.LEFT)

        main = tk.PanedWindow(self, sashrelief=tk.RAISED, sashwidth=5)
        main.pack(fill=tk.BOTH, expand=True)

        left = tk.Frame(main)
        tk.Label(left, text="FILES").pack(anchor="w")
        self.filter_var = tk.StringVar()
        self.filter_entry = tk.Entry(left, textvariable=self.filter_var)
        self.filter_entry.pack(fill=tk.X)
        self.files_list = tk.Listbox(left)
        self.files_list.pack(fill=tk.BOTH, expand=True)
        main.add(left, width=150)

        middle = tk.Frame(main)
        tk.Label(middle, text="INFO").pack(anchor="w")
        self.items_list = tk.Listbox(middle)
        self.items_list.pack(fill=tk.BOTH, expand=True)
        add_frame = tk.Frame(middle)
        add_frame.pack(fill=tk.X)
        self.type_var = tk.StringVar(value="TODO")
        ttk.Combobox(add_frame, textvariable=self.type_var, values=PROMPT_TYPES, width=8).pack(side=tk.LEFT)
        self.body_var = tk.StringVar()
        tk.Entry(add_frame, textvariable=self.body_var).pack(side=tk.LEFT, fill=tk.X, expand=True)
        tk.Button(add_frame, text="Add", command=self.add_prompt).pack(side=tk.LEFT)
        main.add(middle, width=250)

        right = tk.Frame(main)
        tk.Label(right, text="CONTENT").pack(anchor="w")
        self.preview = tk.Text(right)
        self.preview.pack(fill=tk.BOTH, expand=True)
        main.add(right, width=400)

    def bind_events(self):
        self.files_list.bind("<<ListboxSelect>>", lambda e: self.on_file_select())
        self.bind("<F5>", lambda e: self.refresh_graph())
        self.bind("<Control-s>", lambda e: self.save_file())
        self.bind("<Control-k>", lambda e: self.focus_filter())

    def focus_filter(self):
        self.filter_entry.focus_set()

    def load_settings(self):
        if SETTINGS.exists():
            try:
                data = json.loads(SETTINGS.read_text())
                geom = data.get("geometry")
                if geom:
                    self.geometry(geom)
                self.last_file = data.get("last_file")
            except Exception:
                self.last_file = None
        else:
            self.last_file = None

    def save_settings(self):
        SETTINGS.parent.mkdir(exist_ok=True)
        data = {"geometry": self.geometry(), "last_file": self.current_file}
        SETTINGS.write_text(json.dumps(data, indent=2))

    def refresh_graph(self):
        self.graph = build_graph(ROOT)
        self.files_list.delete(0, tk.END)
        for fname in sorted(self.graph["files"].keys()):
            if self.filter_var.get().lower() in fname.lower():
                self.files_list.insert(tk.END, fname)
        if self.current_file and self.current_file in self.graph["files"]:
            idx = list(sorted(self.graph["files"].keys())).index(self.current_file)
            self.files_list.selection_set(idx)
            self.on_file_select()
        elif getattr(self, "last_file", None) and self.last_file in self.graph["files"]:
            idx = list(sorted(self.graph["files"].keys())).index(self.last_file)
            self.files_list.selection_set(idx)
            self.on_file_select()

    def on_file_select(self):
        sel = self.files_list.curselection()
        if not sel:
            return
        fname = self.files_list.get(sel[0])
        self.current_file = fname
        data = self.graph["files"].get(fname, {})
        self.items_list.delete(0, tk.END)
        for sec in data.get("sections", []):
            self.items_list.insert(tk.END, f"# {sec['title']}")
            for p in sec.get("prompts", []):
                self.items_list.insert(tk.END, f"  {p['type']}: {p['body']}")
        for p in data.get("prompts", []):
            if p["section"] == "":
                self.items_list.insert(tk.END, f"{p['type']}: {p['body']}")
        try:
            text = (ROOT / fname).read_text(encoding="utf-8")
        except Exception as e:
            text = str(e)
        self.preview.delete("1.0", tk.END)
        self.preview.insert("1.0", text)

    def add_prompt(self):
        if not self.current_file:
            return
        ptype = self.type_var.get().strip().upper()
        body = self.body_var.get().strip()
        if not body:
            return
        with open(ROOT / self.current_file, "a", encoding="utf-8") as f:
            f.write(f"\n!!{ptype} :: {body}\n")
        self.body_var.set("")
        self.refresh_graph()
        self.on_file_select()

    def save_file(self):
        if not self.current_file:
            return
        text = self.preview.get("1.0", tk.END)
        (ROOT / self.current_file).write_text(text, encoding="utf-8")
        messagebox.showinfo("Saved", f"Saved {self.current_file}")

    def export_opml(self):
        refresh_main()
        messagebox.showinfo("Export", f"OPML written to {ROOT / 'out' / 'structure.opml'}")

    def go_start(self):
        try:
            idx = list(sorted(self.graph["files"].keys())).index("start.txt")
            self.files_list.selection_clear(0, tk.END)
            self.files_list.selection_set(idx)
            self.on_file_select()
        except ValueError:
            pass

    def on_closing(self):
        self.save_settings()
        self.destroy()

def main():
    app = DoorApp()
    app.protocol("WM_DELETE_WINDOW", app.on_closing)
    app.mainloop()

if __name__ == "__main__":
    main()
