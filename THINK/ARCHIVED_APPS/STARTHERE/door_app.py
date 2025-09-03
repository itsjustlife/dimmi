import json
import os
import subprocess
import sys
import tkinter as tk
from tkinter import ttk, messagebox, filedialog
from pathlib import Path
from dimmi_core import DimmiRunner

from dimmi_parser import build_graph
from refresh import main as refresh_main

APP_ROOT = Path(__file__).parent
SETTINGS = APP_ROOT / "out" / "settings.json"
PROMPT_TYPES = ["ASK", "TODO", "GOTO", "LINK", "TAG", "APPEND", "REFRESH", "LOG"]
IGNORE_NAMES = {'.git', '.gradle', 'build', '__pycache__', 'node_modules'}


def is_text_file(path: Path) -> bool:
    try:
        chunk = path.open('rb').read(1024)
        chunk.decode('utf-8')
        return True
    except Exception:
        return False

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
        self.home_dir = APP_ROOT
        self.current_dir = self.home_dir
        self.create_widgets()
        self.bind_events()
        self.load_settings()
        self.refresh_graph()
        # Initialize DimmiRunner (set your model path here)
        self.dimmi = DimmiRunner(r"C:\Users\itsju\AppData\Local\nomic.ai\GPT4All\mistral-7b-instruct-v0.1.Q4_0.gguf")

    # (Removed duplicate definitions of create_widgets, bind_events, focus_filter, load_settings, save_settings, refresh_graph, on_file_select, and add_prompt)

    def create_widgets(self):
        toolbar = tk.Frame(self)
        toolbar.pack(side=tk.TOP, fill=tk.X)
        tk.Button(toolbar, text="REFRESH", command=self.refresh_graph).pack(side=tk.LEFT)
        tk.Button(toolbar, text="HOME", command=self.go_home).pack(side=tk.LEFT)
        tk.Button(toolbar, text="UP", command=self.go_up).pack(side=tk.LEFT)
        tk.Button(toolbar, text="SET ROOT", command=self.choose_home_dir).pack(side=tk.LEFT)
        tk.Button(toolbar, text="OPEN Out/", command=lambda: open_path(APP_ROOT / "out")).pack(side=tk.LEFT)
        
        # Add Ask AI field directly in the main window
        ai_frame = tk.Frame(self)
        ai_frame.pack(side=tk.TOP, fill=tk.X, padx=8, pady=4)
        tk.Label(ai_frame, text="Ask AI:").pack(side=tk.LEFT)
        self.ai_entry = tk.Entry(ai_frame, width=60)
        self.ai_entry.pack(side=tk.LEFT, padx=4)
        self.ai_btn = tk.Button(ai_frame, text="Ask", command=self.ask_ai)
        self.ai_btn.pack(side=tk.LEFT, padx=4)
        tk.Label(ai_frame, text="AI Reply:").pack(side=tk.LEFT, padx=8)
        # Make AI Reply field scrollable
        ai_output_frame = tk.Frame(ai_frame)
        ai_output_frame.pack(side=tk.LEFT, padx=4)
        self.ai_output = tk.Text(ai_output_frame, height=4, width=60, state="disabled", wrap="word")
        self.ai_output.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        ai_scroll = tk.Scrollbar(ai_output_frame, command=self.ai_output.yview)
        ai_scroll.pack(side=tk.RIGHT, fill=tk.Y)
        self.ai_output.config(yscrollcommand=ai_scroll.set)
        # Add Copy button
        self.copy_btn = tk.Button(ai_frame, text="Copy", command=self.copy_ai_reply)
        self.copy_btn.pack(side=tk.LEFT, padx=4)

        main = tk.PanedWindow(self, sashrelief=tk.RAISED, sashwidth=5)
        main.pack(fill=tk.BOTH, expand=True)

        left = tk.Frame(main)
        tk.Label(left, text="FILES").pack(anchor="w")
        self.path_label = tk.Label(left, text=str(self.current_dir))
        self.path_label.pack(anchor="w")
        self.filter_var = tk.StringVar()
        self.filter_entry = tk.Entry(left, textvariable=self.filter_var)
        self.filter_entry.pack(fill=tk.X)
        self.files_list = tk.Listbox(left)
        self.files_list.pack(fill=tk.BOTH, expand=True)
        main.add(left, width=200)

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
                home = data.get("home_dir")
                if home:
                    self.home_dir = Path(home)
                self.current_dir = self.home_dir
            except Exception:
                self.current_dir = self.home_dir
        else:
            self.current_dir = self.home_dir

    def save_settings(self):
        SETTINGS.parent.mkdir(exist_ok=True)
        data = {"geometry": self.geometry(), "home_dir": str(self.home_dir)}
        SETTINGS.write_text(json.dumps(data, indent=2))

    def refresh_graph(self):
        self.graph = build_graph(self.home_dir)
        self.refresh_file_list()

    def on_file_select(self):
        sel = self.files_list.curselection()
        if not sel:
            return
        path = self.items[sel[0]]
        if path.is_dir():
            self.current_dir = path
            self.refresh_file_list()
            return
        self.current_file = path
        rel = self.current_file.relative_to(self.home_dir).as_posix()
        data = self.graph["files"].get(rel, {})
        self.items_list.delete(0, tk.END)
        for sec in data.get("sections", []):
            self.items_list.insert(tk.END, f"# {sec['title']}")
            for p in sec.get("prompts", []):
                self.items_list.insert(tk.END, f"  {p['type']}: {p['body']}")
        for p in data.get("prompts", []):
            if p["section"] == "":
                self.items_list.insert(tk.END, f"{p['type']}: {p['body']}")
        if is_text_file(self.current_file):
            try:
                text = self.current_file.read_text(encoding="utf-8")
            except Exception as e:
                text = f"Error: {e}"
        else:
            text = "[binary file]"
        self.preview.delete("1.0", tk.END)
        self.preview.insert("1.0", text)

    def add_prompt(self):
        if not self.current_file:
            return
        ptype = self.type_var.get().strip().upper()
        body = self.body_var.get().strip()
        if not body:
            return
        with open(self.current_file, "a", encoding="utf-8") as f:
            f.write(f"\n!!{ptype} :: {body}\n")
        self.body_var.set("")
        self.refresh_graph()
        self.on_file_select()

    def copy_ai_reply(self):
        self.ai_output.config(state="normal")
        text = self.ai_output.get("1.0", tk.END).strip()
        self.ai_output.config(state="disabled")
        self.clipboard_clear()
        self.clipboard_append(text)
        self.copy_btn.config(text="Copied!")
        self.after(1200, lambda: self.copy_btn.config(text="Copy"))

    def ask_ai(self):
        prompt = self.ai_entry.get().strip()
        if not prompt:
            return
        self.ai_output.config(state="normal")
        self.ai_output.delete("1.0", tk.END)
        self.ai_output.insert(tk.END, "Thinking...")
        self.ai_output.config(state="disabled")
        self.update_idletasks()
        try:
            reply = self.dimmi.run(prompt)
        except Exception as e:
            reply = f"Error: {e}"
        self.ai_output.config(state="normal")
        self.ai_output.delete("1.0", tk.END)
        self.ai_output.insert(tk.END, reply)
        self.ai_output.config(state="disabled")

    def choose_home_dir(self):
        path = filedialog.askdirectory(initialdir=str(self.home_dir))
        if path:
            self.home_dir = Path(path)
            self.current_dir = self.home_dir
            self.refresh_graph()

    def go_home(self):
        self.current_dir = self.home_dir
        self.refresh_file_list()

    def go_up(self):
        new_dir = self.current_dir.parent
        self.current_dir = new_dir
        self.refresh_file_list()

    def refresh_file_list(self):
        self.path_label.config(text=str(self.current_dir))
        self.files_list.delete(0, tk.END)
        self.items = []
        for child in sorted(self.current_dir.iterdir(), key=lambda p: (p.is_file(), p.name.lower())):
            if child.name in IGNORE_NAMES or child.name.startswith('.'):
                continue
            if self.filter_var.get().lower() not in child.name.lower():
                continue
            display = child.name + ("/" if child.is_dir() else "")
            self.files_list.insert(tk.END, display)
            self.items.append(child)

    def save_file(self):
        if not self.current_file or not self.current_file.is_file():
            return
        text = self.preview.get("1.0", tk.END)
        try:
            self.current_file.write_text(text, encoding="utf-8")
        except Exception as e:
            messagebox.showerror("Save Error", str(e))
        self.refresh_graph()

    def on_closing(self):
        self.save_settings()
        self.destroy()

def main():
    app = DoorApp()
    app.protocol("WM_DELETE_WINDOW", app.on_closing)
    app.mainloop()
if __name__ == "__main__":
    main()
