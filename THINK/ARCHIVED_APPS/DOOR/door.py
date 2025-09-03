"""Entry point for the simplified DOOR application.

The application demonstrates a three column layout (Files → Structure →
Content) with a minimal structure editor capable of importing and exporting
OPML.  Many features are placeholders intended to mirror the workflow of the
Arkhivist tool.
"""

from __future__ import annotations

import json
from pathlib import Path
import tkinter as tk
from tkinter import ttk, filedialog, messagebox

from door_structure import Node, StructurePane

SETTINGS = Path(__file__).with_name("door_settings.json")


class DoorApp(tk.Tk):
    def __init__(self) -> None:
        super().__init__()
        self.title("DOOR")
        self.current_node: Node | None = None

        self._build_style()
        self._build_menu()
        self._build_widgets()
        self._bind_shortcuts()
        self.load_settings()

    # --------------------------------------------------------------- builders
    def _build_style(self) -> None:
        style = ttk.Style()
        style.configure("Header.TLabel", font=("TkDefaultFont", 10, "bold"))
        style.configure("Toolbar.TButton", padding=2)

    def _build_menu(self) -> None:
        menubar = tk.Menu(self)
        file_menu = tk.Menu(menubar, tearoff=False)
        file_menu.add_command(label="New Structure", command=self.new_structure)
        file_menu.add_command(label="Open OPML…", command=self.open_opml)
        file_menu.add_command(label="Save OPML…", command=self.save_opml)
        file_menu.add_command(label="Copy OPML", command=self.copy_opml)
        file_menu.add_separator()
        file_menu.add_command(label="Exit", command=self.destroy)
        menubar.add_cascade(label="File", menu=file_menu)
        self.config(menu=menubar)

    def _build_widgets(self) -> None:
        toolbar = ttk.Frame(self)
        toolbar.pack(side=tk.TOP, fill=tk.X)

        create_btn = tk.Menubutton(toolbar, text="Create")
        create_menu = tk.Menu(create_btn, tearoff=False)
        create_menu.add_command(label="Folders…", command=lambda: messagebox.showinfo("Create", "Folders…"))
        create_menu.add_command(label="Site…", command=lambda: messagebox.showinfo("Create", "Site…"))
        create_menu.add_command(label="Image…", command=lambda: messagebox.showinfo("Create", "Image…"))
        create_menu.add_command(label="Link…", command=lambda: messagebox.showinfo("Create", "Link…"))
        create_btn.config(menu=create_menu)
        create_btn.pack(side=tk.LEFT)

        ttk.Button(toolbar, text="New Structure", command=self.new_structure, style="Toolbar.TButton").pack(side=tk.LEFT)
        ttk.Button(toolbar, text="Open OPML…", command=self.open_opml, style="Toolbar.TButton").pack(side=tk.LEFT)
        ttk.Button(toolbar, text="Save OPML…", command=self.save_opml, style="Toolbar.TButton").pack(side=tk.LEFT)
        ttk.Button(toolbar, text="Copy OPML", command=self.copy_opml, style="Toolbar.TButton").pack(side=tk.LEFT)

        self.main = tk.PanedWindow(self, orient=tk.HORIZONTAL, sashrelief=tk.RAISED, sashwidth=5)
        self.main.pack(fill=tk.BOTH, expand=True)

        # ------------------------------------------------------- files column
        files_frame = ttk.Frame(self.main)
        ttk.Label(files_frame, text="FILES", style="Header.TLabel").pack(anchor="w")

        buttons = ttk.Frame(files_frame)
        buttons.pack(fill=tk.X)
        ttk.Button(buttons, text="Analyze Folders", command=lambda: messagebox.showinfo("Analyze", "Not implemented")).pack(side=tk.LEFT)
        ttk.Button(buttons, text="Export Folders", command=lambda: messagebox.showinfo("Export", "Not implemented")).pack(side=tk.LEFT)

        self.files_list = tk.Listbox(files_frame)
        self.files_list.pack(fill=tk.BOTH, expand=True)
        self.file_menu = tk.Menu(self, tearoff=False)
        self.file_menu.add_command(label="Analyze Folders", command=lambda: messagebox.showinfo("Analyze", "Not implemented"))
        self.file_menu.add_command(label="Export Folders", command=lambda: messagebox.showinfo("Export", "Not implemented"))
        self.files_list.bind("<Button-3>", self._popup_file_menu)
        self.main.add(files_frame, width=200)

        # ---------------------------------------------------- structure column
        structure_frame = ttk.Frame(self.main)
        ttk.Label(structure_frame, text="STRUCTURE", style="Header.TLabel").pack(anchor="w")
        self.structure = StructurePane(structure_frame, on_select=self.on_node_select)
        self.structure.pack(fill=tk.BOTH, expand=True)
        self.main.add(structure_frame, width=300)

        # ------------------------------------------------------- content column
        content_frame = ttk.Frame(self.main)
        header = ttk.Frame(content_frame)
        header.pack(fill=tk.X)
        ttk.Label(header, text="CONTENT", style="Header.TLabel").pack(side=tk.LEFT)
        ttk.Button(header, text="Save", command=self.save_content, style="Toolbar.TButton").pack(side=tk.RIGHT)
        self.editor = tk.Text(content_frame)
        self.editor.pack(fill=tk.BOTH, expand=True)
        self.main.add(content_frame, width=400)

    def _bind_shortcuts(self) -> None:
        self.bind("<Control-s>", lambda e: self.save_content())

    # --------------------------------------------------------- menu commands
    def new_structure(self) -> None:
        self.structure.root_node = Node("root")
        self.structure.refresh()
        self.current_node = None
        self.editor.delete("1.0", tk.END)

    def open_opml(self) -> None:
        path = filedialog.askopenfilename(filetypes=[("OPML files", "*.opml"), ("All files", "*")])
        if path:
            self.structure.load_opml_file(path)

    def save_opml(self) -> None:
        path = filedialog.asksaveasfilename(defaultextension=".opml", filetypes=[("OPML files", "*.opml")])
        if path:
            self.structure.save_opml_file(path)

    def copy_opml(self) -> None:
        data = self.structure.export_to_string()
        self.clipboard_clear()
        self.clipboard_append(data)
        messagebox.showinfo("Copy OPML", "Structure copied to clipboard")

    # ------------------------------------------------------- structure events
    def on_node_select(self, node: Node) -> None:
        self.current_node = node
        self.editor.delete("1.0", tk.END)
        self.editor.insert("1.0", node.content)

    def save_content(self) -> None:
        if self.current_node is not None:
            self.current_node.content = self.editor.get("1.0", tk.END).rstrip()

    # -------------------------------------------------------- context menus
    def _popup_file_menu(self, event) -> None:  # pragma: no cover - UI helper
        self.file_menu.tk_popup(event.x_root, event.y_root)

    # ------------------------------------------------------------- settings
    def load_settings(self) -> None:
        if SETTINGS.exists():
            try:
                data = json.loads(SETTINGS.read_text())
                geom = data.get("geometry")
                if geom:
                    self.geometry(geom)
                sashes = data.get("sashes", [])

                def set_sashes() -> None:
                    for i, pos in enumerate(sashes):
                        try:
                            self.main.sashpos(i, pos)
                        except Exception:
                            pass

                self.after(100, set_sashes)
            except Exception:
                pass
        self.protocol("WM_DELETE_WINDOW", self.on_close)

    def save_settings(self) -> None:
        data = {
            "geometry": self.geometry(),
            "sashes": [self.main.sashpos(i) for i in range(len(self.main.panes()) - 1)],
        }
        SETTINGS.write_text(json.dumps(data))

    def on_close(self) -> None:
        self.save_settings()
        self.destroy()


def main() -> None:  # pragma: no cover - convenience
    app = DoorApp()
    app.mainloop()


if __name__ == "__main__":  # pragma: no cover
    main()

