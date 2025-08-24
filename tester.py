"""
PSSST — Grid OS (single file)
- Square-grid menu that only shows existing items (auto-tiles to fit)
- Inline editor pane (Title / Type / Text) with autosave
- Long-press/right-click opens in-app Action Sheet (no pop-up windows)
- Drag a tile onto another to nest; drop on top/bottom third to order siblings
- Double-click a tile to enter its children (breadcrumb path at top)
- Copy/Cut/Paste (deep copy) across the tree
- Persistent state in ~/.pssst_grid_os.json
- Toolbars include placeholders for future features (Search, Links, Multi-Select, Import/Export, Help)

Run:
    python psssst_grid_os_single.py
"""

import json, time, uuid, math
from pathlib import Path
import tkinter as tk
from tkinter import ttk

# ---------- Config ----------
STATE_PATH = Path.home() / ".pssst_grid_os.json"
LONG_PRESS_MS = 600
AUTOSAVE_MS = 800

TYPES = ["SOURCE","STRUCTURE","VIEW","CONTENT","CHANGE","CONTROLS","SHARING"]

PALETTE_DARK = {
    "bg":"#0e1520","panel":"#0b131e","ink":"#e6edf3","muted":"#94a3b8","border":"#1e293b",
    "tile":"#0b1523","sel":"#67e8f9",
    "SOURCE":"#a78bfa","STRUCTURE":"#67e8f9","VIEW":"#60a5fa","CONTENT":"#fbbf24","CHANGE":"#fb923c","CONTROLS":"#34d399","SHARING":"#f472b6",
}
PALETTE_LIGHT = {
    "bg":"#f4f7fb","panel":"#ffffff","ink":"#0b1118","muted":"#4b5a6b","border":"#d6e0ea",
    "tile":"#edf2f7","sel":"#0ea5e9",
    "SOURCE":"#6d28d9","STRUCTURE":"#0ea5e9","VIEW":"#2563eb","CONTENT":"#b45309","CHANGE":"#c2410c","CONTROLS":"#059669","SHARING":"#be185d",
}

# ---------- Utilities ----------
def new_id() -> str:
    return uuid.uuid4().hex[:12]

def default_state():
    root = new_id()
    # seed with a couple of items so you can feel the flow immediately
    a = new_id(); b = new_id()
    return {
        "theme": "dark",
        "root": root,
        "current": root,
        "clipboard": None,  # {"mode":"copy"|"cut","tree":{...}}
        "nodes": {
            root: {"id":root,"title":"Start","type":"STRUCTURE","text":"Welcome to PSSST Grid.\nDouble-click tiles to enter; long-press for actions.","parent":None,"children":[a,b]},
            a: {"id":a,"title":"Notes","type":"CONTENT","text":"This is a note.\nTry drag me onto another tile.","parent":root,"children":[]},
            b: {"id":b,"title":"Projects","type":"STRUCTURE","text":"Put children inside me.","parent":root,"children":[]},
        },
    }

def load_state():
    if STATE_PATH.exists():
        try:
            return json.loads(STATE_PATH.read_text(encoding="utf-8"))
        except Exception:
            pass
    return default_state()

def save_state(state):
    try:
        STATE_PATH.write_text(json.dumps(state, indent=2), encoding="utf-8")
    except Exception:
        pass

# ---------- App ----------
class PSSST(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("PSSST — Grid OS")
        self.geometry("1200x760")
        self.minsize(960, 620)

        # state
        self.state = load_state()
        self.selected = None
        self.long_job = None
        self.press_xy = None
        self.pressed_tile = None
        self.dragging_id = None
        self.drag_start = None
        self.drag_ghost = None
        self.pending_delete = {"id": None, "t": 0}

        # colors
        self.palette = PALETTE_DARK if self.state.get("theme","dark")=="dark" else PALETTE_LIGHT
        self.configure(bg=self.palette["bg"])

        # build UI
        self._build_toolbar()
        self._build_breadcrumb()
        self._build_split()
        self._build_actionsheet()
        self._build_statusbar()

        # initial draw
        self.refresh_all()

    # ---------- Theme ----------
    def toggle_theme(self):
        self.state["theme"] = "light" if self.state.get("theme","dark")=="dark" else "dark"
        self.palette = PALETTE_LIGHT if self.state["theme"]=="light" else PALETTE_DARK
        save_state(self.state)
        self.configure(bg=self.palette["bg"])
        self._style_toolbar()
        self._style_breadcrumb()
        self._style_split()
        self._style_actionsheet()
        self._style_statusbar()
        self.draw_grid()
        self.toast(f"Theme: {self.state['theme']}")

    # ---------- Top toolbar ----------
    def _build_toolbar(self):
        self.toolbar = tk.Frame(self, bg=self.palette["panel"], highlightthickness=1, highlightbackground=self.palette["border"])
        self.toolbar.pack(fill="x", padx=10, pady=10)

        def btn(txt, cmd, w=12):
            b = tk.Button(self.toolbar, text=txt, command=cmd, bg=self.palette["panel"], fg=self.palette["ink"], width=w, relief="ridge", bd=1)
            b.pack(side="left", padx=4, pady=6)
            return b

        self.btn_home = btn("Home", lambda: self.set_current(self.state["root"]))
        self.btn_up   = btn("Up", self.nav_up)
        self.sep1 = tk.Label(self.toolbar, text="|", bg=self.palette["panel"], fg=self.palette["muted"]); self.sep1.pack(side="left", padx=6)

        self.btn_search = btn("Search (soon)", lambda: self.toast("Search palette coming soon"))
        self.btn_links  = btn("Links (soon)",  lambda: self.toast("Link overlays coming soon"))
        self.btn_multi  = btn("Multi‑Select (soon)", lambda: self.toast("Multi-select coming soon"))
        self.sep2 = tk.Label(self.toolbar, text="|", bg=self.palette["panel"], fg=self.palette["muted"]); self.sep2.pack(side="left", padx=6)

        self.btn_import = btn("Import (soon)", lambda: self.toast("Importer coming soon"))
        self.btn_export = btn("Export (soon)", lambda: self.toast("Exporter coming soon"))
        self.sep3 = tk.Label(self.toolbar, text="|", bg=self.palette["panel"], fg=self.palette["muted"]); self.sep3.pack(side="left", padx=6)

        self.btn_theme  = btn("Toggle Theme", self.toggle_theme)
        self.btn_help   = btn("Help (soon)", lambda: self.toast("Help overlay coming soon"))
        self._style_toolbar()

    def _style_toolbar(self):
        self.toolbar.configure(bg=self.palette["panel"], highlightbackground=self.palette["border"])
        for w in self.toolbar.winfo_children():
            if isinstance(w, tk.Button):
                w.configure(bg=self.palette["panel"], fg=self.palette["ink"], activebackground=self.palette["tile"], activeforeground=self.palette["ink"])
            elif isinstance(w, tk.Label):
                w.configure(bg=self.palette["panel"], fg=self.palette["muted"])

    # ---------- Breadcrumb ----------
    def _build_breadcrumb(self):
        self.bread = tk.Frame(self, bg=self.palette["bg"])
        self.bread.pack(fill="x", padx=10, pady=(0,8))
        self._style_breadcrumb()

    def _style_breadcrumb(self):
        self.bread.configure(bg=self.palette["bg"])

    def build_breadcrumb(self):
        for w in self.bread.winfo_children():
            w.destroy()

        # path root->current
        path_ids = []
        x = self.state["current"]
        while x is not None:
            path_ids.append(x)
            x = self.node(path_ids[-1])["parent"]
        path_ids.reverse()

        for i, nid in enumerate(path_ids):
            title = self.node(nid)["title"] or "Untitled"
            btn = tk.Button(self.bread, text=title, command=lambda nid=nid: self.set_current(nid),
                            bg=self.palette["panel"], fg=self.palette["ink"], relief="ridge", bd=1)
            btn.pack(side="left", padx=(0,6), pady=2)
            if i < len(path_ids)-1:
                tk.Label(self.bread, text="›", bg=self.palette["bg"], fg=self.palette["muted"]).pack(side="left", padx=(0,6))
        # quick add at level
        tk.Button(self.bread, text="+", command=lambda: self.add_and_focus(self.state["current"]),
                  bg=self.palette["panel"], fg=self.palette["ink"], relief="ridge", bd=1, width=3).pack(side="right")

    # ---------- Main split ----------
    def _build_split(self):
        self.split = tk.PanedWindow(self, orient="horizontal", sashwidth=6, bg=self.palette["bg"])
        self.split.pack(fill="both", expand=True, padx=10, pady=(0,10))

        # Left: grid canvas
        left = tk.Frame(self.split, bg=self.palette["bg"])
        self.canvas = tk.Canvas(left, bg=self.palette["tile"], highlightthickness=1, highlightbackground=self.palette["border"])
        self.canvas.pack(fill="both", expand=True)
        self.split.add(left, stretch="always")

        # Canvas events
        self.canvas.bind("<Configure>", lambda e: self.draw_grid())
        self.canvas.bind("<ButtonPress-1>", self.on_press)
        self.canvas.bind("<ButtonRelease-1>", self.on_release)
        self.canvas.bind("<B1-Motion>", self.on_motion)
        self.canvas.bind("<Double-Button-1>", self.on_double)
        self.canvas.bind("<Button-3>", self.on_right_click)  # alt trigger for action sheet
        self.canvas.bind("<Button-2>", self.on_right_click)

        # Right: inline editor
        right = tk.Frame(self.split, bg=self.palette["panel"], highlightthickness=1, highlightbackground=self.palette["border"])
        # Title
        tk.Label(right, text="Title", bg=self.palette["panel"], fg=self.palette["ink"]).pack(anchor="w", padx=10, pady=(10,2))
        self.title_var = tk.StringVar()
        self.title_entry = tk.Entry(right, textvariable=self.title_var, bg="#0a1320", fg=self.palette["ink"], insertbackground=self.palette["ink"])
        self.title_entry.pack(fill="x", padx=10)
        self.title_entry.bind("<KeyRelease>", lambda e: self.schedule_autosave())

        # Type
        tk.Label(right, text="Type", bg=self.palette["panel"], fg=self.palette["ink"]).pack(anchor="w", padx=10, pady=(10,2))
        self.type_var = tk.StringVar(value="CONTENT")
        tk.OptionMenu(right, self.type_var, *TYPES, command=lambda _=None: self.save_selected()).pack(fill="x", padx=10)

        # Text
        tk.Label(right, text="Text", bg=self.palette["panel"], fg=self.palette["ink"]).pack(anchor="w", padx=10, pady=(10,2))
        self.text = tk.Text(right, wrap="word", bg="#0b1523", fg=self.palette["ink"], insertbackground=self.palette["ink"])
        self.text.pack(fill="both", expand=True, padx=10, pady=(0,10))
        self.text.bind("<KeyRelease>", lambda e: self.schedule_autosave())

        # Quick buttons (duplicates of action sheet core verbs)
        qb = tk.Frame(right, bg=self.palette["panel"])
        qb.pack(fill="x", padx=10, pady=(0,10))
        def qbtn(t, cmd): tk.Button(qb, text=t, command=cmd, bg=self.palette["panel"], fg=self.palette["ink"], relief="ridge", bd=1).pack(side="left", padx=4)
        qbtn("Add Sub‑Item", lambda: self.quick_add(kind="sub"))
        qbtn("Add Sibling", lambda: self.quick_add(kind="sib"))
        qbtn("Delete", self.quick_delete)
        qbtn("Copy", lambda: self.copy_or_cut("copy"))
        qbtn("Cut", lambda: self.copy_or_cut("cut"))
        qbtn("Paste Into", lambda: self.paste(into=True))
        qbtn("Paste After", lambda: self.paste(into=False))

        self.split.add(right, minsize=360)
        self._style_split()

    def _style_split(self):
        self.split.configure(bg=self.palette["bg"])
        # children styled already on build

    # ---------- Action Sheet (in-app, not a pop-up) ----------
    def _build_actionsheet(self):
        self.sheet = tk.Frame(self, bg=self.palette["panel"], highlightthickness=1, highlightbackground=self.palette["border"])
        # initially hidden; pack at bottom when shown
        row = tk.Frame(self.sheet, bg=self.palette["panel"]); row.pack(fill="x", padx=10, pady=6)

        def sbtn(label, cmd):
            b = tk.Button(row, text=label, command=cmd, bg=self.palette["panel"], fg=self.palette["ink"], relief="ridge", bd=1)
            b.pack(side="left", padx=4, pady=4)
            return b

        self.sheet_label = tk.Label(self.sheet, text="Actions", bg=self.palette["panel"], fg=self.palette["muted"])
        self.sheet_label.pack(anchor="w", padx=12)

        self.btn_sheet_addsub = sbtn("Add Sub‑Item", lambda: self.sheet_act("sub"))
        self.btn_sheet_addsib = sbtn("Add Sibling",  lambda: self.sheet_act("sib"))
        self.btn_sheet_rename = sbtn("Rename (focus title)", lambda: self.title_entry.focus_set())
        self.btn_sheet_delete = sbtn("Delete", lambda: self.sheet_act("del"))
        self.btn_sheet_copy   = sbtn("Copy", lambda: self.sheet_clip("copy"))
        self.btn_sheet_cut    = sbtn("Cut",  lambda: self.sheet_clip("cut"))
        self.btn_sheet_pastei = sbtn("Paste Into",  lambda: self.sheet_act("paste_into"))
        self.btn_sheet_pastea = sbtn("Paste After", lambda: self.sheet_act("paste_after"))
        self.btn_sheet_enter  = sbtn("Enter", lambda: self.sheet_act("enter"))

        # type switcher inside sheet
        self.sheet_type_var = tk.StringVar(value="CONTENT")
        tk.Label(self.sheet, text="Type:", bg=self.palette["panel"], fg=self.palette["ink"]).pack(side="left", padx=(12,4))
        tk.OptionMenu(self.sheet, self.sheet_type_var, *TYPES, command=lambda _=None: self.set_type(self.sheet_type_var.get())).pack(side="left")
        tk.Button(self.sheet, text="Close", command=self.hide_sheet, bg=self.palette["panel"], fg=self.palette["ink"], relief="ridge", bd=1)\
            .pack(side="right", padx=10, pady=6)

        self._style_actionsheet()

    def _style_actionsheet(self):
        self.sheet.configure(bg=self.palette["panel"], highlightbackground=self.palette["border"])
        for w in self.sheet.winfo_children():
            if isinstance(w, tk.Button):
                w.configure(bg=self.palette["panel"], fg=self.palette["ink"], activebackground=self.palette["tile"], activeforeground=self.palette["ink"])
            elif isinstance(w, tk.Label):
                w.configure(bg=self.palette["panel"], fg=self.palette["muted"])

    def show_sheet_for(self, nid=None, bg=False):
        # Configure label to reflect context
        if bg:
            self.sheet_label.configure(text="Actions — here")
        else:
            title = self.node(nid)["title"]
            self.sheet_label.configure(text=f"Actions — {title}")
        # sync type dropdown
        if self.selected and self.selected in self.state["nodes"]:
            self.sheet_type_var.set(self.node(self.selected)["type"])
        # show
        if not self.sheet.winfo_ismapped():
            self.sheet.pack(fill="x", padx=10, pady=(0,10))
        # ensure focus remains reasonable
        self.title_entry.focus_set()

    def hide_sheet(self):
        if self.sheet.winfo_ismapped():
            self.sheet.pack_forget()

    def sheet_act(self, which):
        if which == "sub":
            self.quick_add(kind="sub")
        elif which == "sib":
            self.quick_add(kind="sib")
        elif which == "del":
            self.quick_delete()
        elif which == "paste_into":
            self.paste(into=True)
        elif which == "paste_after":
            self.paste(into=False)
        elif which == "enter" and self.selected:
            self.set_current(self.selected)

    def sheet_clip(self, mode):
        self.copy_or_cut(mode)

    # ---------- Status bar / toast ----------
    def _build_statusbar(self):
        self.status = tk.Label(self, text="ready", bg=self.palette["bg"], fg=self.palette["muted"], anchor="w")
        self.status.pack(fill="x", padx=10, pady=(0,10))
        self._style_statusbar()

    def _style_statusbar(self):
        self.status.configure(bg=self.palette["bg"], fg=self.palette["muted"])

    def toast(self, msg):
        self.status.configure(text=msg)
        # optional fade timeout
        if hasattr(self, "_toast_job") and self._toast_job:
            try: self.after_cancel(self._toast_job)
            except Exception: pass
        self._toast_job = self.after(2000, lambda: self.status.configure(text="ready"))

    # ---------- Data helpers ----------
    def node(self, nid): return self.state["nodes"][nid]
    def children_of(self, nid): return [self.node(cid) for cid in self.node(nid)["children"]]

    def add_child(self, parent_id, title="Untitled", typ="CONTENT"):
        nid = new_id()
        self.state["nodes"][nid] = {"id":nid,"title":title,"type":typ,"text":"","parent":parent_id,"children":[]}
        self.node(parent_id)["children"].append(nid)
        return nid

    def insert_sibling(self, target_id, title="Untitled", after=True, typ="CONTENT"):
        parent = self.node(target_id)["parent"]
        if parent is None: return None
        kids = self.node(parent)["children"]
        idx = kids.index(target_id) + (1 if after else 0)
        nid = new_id()
        self.state["nodes"][nid] = {"id":nid,"title":title,"type":typ,"text":"","parent":parent,"children":[]}
        kids.insert(idx, nid)
        return nid

    def delete_node(self, nid):
        if nid == self.state["root"]:  # protect root
            return
        # detach
        parent = self.node(nid)["parent"]
        if parent is not None and nid in self.node(parent)["children"]:
            self.node(parent)["children"].remove(nid)
        # recursive drop
        def drop(x):
            for c in list(self.node(x)["children"]):
                drop(c)
            del self.state["nodes"][x]
        drop(nid)

    def deep_copy_tree(self, nid):
        def clone(x):
            src = self.node(x)
            new = {"id": new_id(), "title": src["title"], "type": src["type"], "text": src["text"], "children":[]}
            new["children"] = [clone(c) for c in src["children"]]
            return new
        return clone(nid)

    def materialize_tree(self, tree_struct, parent_id, at_index=None):
        """Install a deep tree struct under parent; returns new root id."""
        def install(struct, parent, index=None):
            nid = struct["id"]
            self.state["nodes"][nid] = {"id":nid,"title":struct["title"],"type":struct["type"],"text":struct["text"],
                                        "parent":parent,"children":[]}
            arr = self.node(parent)["children"]
            if index is None or index > len(arr): arr.append(nid)
            else: arr.insert(index, nid)
            for ch in struct["children"]:
                install(ch, nid, None)
            return nid
        return install(tree_struct, parent_id, at_index)

    # ---------- Navigation ----------
    def set_current(self, nid):
        self.state["current"] = nid
        save_state(self.state)
        self.refresh_all()
        self.toast(f"Entered: {self.node(nid)['title']}")

    def nav_up(self):
        cur = self.state["current"]
        parent = self.node(cur)["parent"]
        if parent is not None:
            self.set_current(parent)
        else:
            self.toast("Already at top level")

    def refresh_all(self):
        self.build_breadcrumb()
        self.draw_grid()
        self.select_first_if_none()

    # ---------- Grid drawing ----------
    def visible_nodes(self):
        return self.children_of(self.state["current"])

    def draw_grid(self):
        self.canvas.delete("all")
        items = self.visible_nodes()
        n = len(items)
        W = self.canvas.winfo_width() or 800
        H = self.canvas.winfo_height() or 520
        pad = 12
        min_w, min_h = 124, 96

        if n == 0:
            self.canvas.create_text(W//2, H//2, text="(long‑press background to add)", fill=self.palette["muted"], font=("Helvetica", 12))
            self.tile_rects = {}
            return

        cols = max(1, int(round(math.sqrt(n))))
        rows = math.ceil(n / cols)
        tw = max(min_w, int((W - pad*(cols+1)) / cols))
        th = max(min_h, int((H - pad*(rows+1)) / rows))

        self.tile_rects = {}  # nid -> (x1,y1,x2,y2)
        for i, node in enumerate(items):
            r = i // cols; c = i % cols
            x1 = pad + c*(tw+pad); y1 = pad + r*(th+pad)
            x2 = x1 + tw; y2 = y1 + th
            nid = node["id"]
            self.tile_rects[nid] = (x1,y1,x2,y2)

            # card
            self.canvas.create_rectangle(x1,y1,x2,y2, fill=self.palette["tile"], outline=self.palette["border"])
            # border by type
            tcol = self.palette.get(node["type"], self.palette["CONTENT"])
            self.canvas.create_rectangle(x1+2,y1+2,x2-2,y2-2, outline=tcol, width=2)
            # title
            self.canvas.create_text(x1+10, y1+10, anchor="nw", text=node["title"] or "Untitled",
                                    fill=self.palette["ink"], font=("Helvetica", 10, "bold"))
            # type tag
            self.canvas.create_text(x2-8, y2-10, anchor="se", text=node["type"], fill=self.palette["muted"], font=("Helvetica", 9))
            # selection ring
            if self.selected == nid:
                self.canvas.create_rectangle(x1+5,y1+5,x2-5,y2-5, outline=self.palette["sel"], width=2, dash=(3,3))

        # drag ghost is drawn during motion

    def select_first_if_none(self):
        if self.selected and self.selected in self.state["nodes"]:
            self.load_into_editor(self.selected); return
        kids = self.node(self.state["current"])["children"]
        if kids:
            self.selected = kids[0]
            self.load_into_editor(self.selected)
        else:
            self.selected = None
            self.title_var.set(""); self.type_var.set("CONTENT"); self.text.delete("1.0","end")

    # ---------- Editor ----------
    def load_into_editor(self, nid):
        node = self.node(nid)
        self.title_var.set(node["title"])
        self.type_var.set(node["type"])
        self.text.delete("1.0","end")
        self.text.insert("1.0", node["text"])

    def save_selected(self):
        if not self.selected: return
        node = self.node(self.selected)
        node["title"] = self.title_var.get().strip() or "Untitled"
        node["type"]  = self.type_var.get()
        node["text"]  = self.text.get("1.0","end").rstrip()
        save_state(self.state)
        self.build_breadcrumb()
        self.draw_grid()

    def schedule_autosave(self):
        if hasattr(self, "_autosave_job") and self._autosave_job:
            try: self.after_cancel(self._autosave_job)
            except Exception: pass
        self._autosave_job = self.after(AUTOSAVE_MS, self.save_selected)

    # ---------- Hit testing ----------
    def tile_at(self, x, y):
        for nid, (x1,y1,x2,y2) in self.tile_rects.items():
            if x1<=x<=x2 and y1<=y<=y2:
                return nid, (x1,y1,x2,y2)
        return None, None

    # ---------- Events ----------
    def on_press(self, e):
        self.press_xy = (e.x, e.y)
        nid, _ = self.tile_at(e.x, e.y)
        self.pressed_tile = nid

        # schedule long-press actionsheet (tile or background)
        if self.long_job:
            try: self.after_cancel(self.long_job)
            except Exception: pass
        self.long_job = self.after(LONG_PRESS_MS, lambda: self.on_long_press(e.x_root, e.y_root, nid))

    def on_release(self, e):
        # cancel long-press if it didn't fire
        if self.long_job:
            try: self.after_cancel(self.long_job)
            except Exception: pass
            self.long_job = None

        # if dragging, finalize
        if self.dragging_id:
            self.finish_drag(e.x, e.y)
            return

        # single click select
        nid, _ = self.tile_at(e.x, e.y)
        if nid:
            self.selected = nid
            self.load_into_editor(nid)
            self.draw_grid()
        else:
            # deselect nothing; keep current selection
            pass

    def on_motion(self, e):
        if not self.pressed_tile: return
        px, py = self.press_xy
        # movement cancels long-press
        if abs(e.x - px) + abs(e.y - py) > 8 and self.long_job:
            try: self.after_cancel(self.long_job)
            except Exception: pass
            self.long_job = None

        # start dragging
        if abs(e.x - px) + abs(e.y - py) > 10 and not self.dragging_id:
            self.dragging_id = self.pressed_tile
            self.drag_start = (px, py)
            x1,y1,x2,y2 = self.tile_rects.get(self.dragging_id, (px-60,py-40,px+60,py+40))
            self.drag_ghost = self.canvas.create_rectangle(x1,y1,x2,y2, outline=self.palette["sel"], dash=(4,2), width=2)

        # update ghost
        if self.dragging_id and self.drag_ghost:
            dx, dy = e.x - self.drag_start[0], e.y - self.drag_start[1]
            x1,y1,x2,y2 = self.tile_rects.get(self.dragging_id, (0,0,0,0))
            self.canvas.coords(self.drag_ghost, x1+dx, y1+dy, x2+dx, y2+dy)

    def finish_drag(self, x, y):
        if self.drag_ghost:
            self.canvas.delete(self.drag_ghost)
            self.drag_ghost = None
        source = self.dragging_id
        self.dragging_id = None

        target, rect = self.tile_at(x, y)
        if not target or target == source:
            self.draw_grid(); return

        x1,y1,x2,y2 = rect
        rel = (y - y1) / max(1, (y2 - y1))
        if rel < 0.33:
            # insert before target
            parent = self.node(target)["parent"]
            if parent is not None and source != parent:
                # detach
                oldp = self.node(source)["parent"]
                if oldp is not None and source in self.node(oldp)["children"]:
                    self.node(oldp)["children"].remove(source)
                # attach
                arr = self.node(parent)["children"]
                idx = arr.index(target)
                self.node(source)["parent"] = parent
                arr.insert(idx, source)
                save_state(self.state)
        elif rel > 0.67:
            # insert after target
            parent = self.node(target)["parent"]
            if parent is not None and source != parent:
                oldp = self.node(source)["parent"]
                if oldp is not None and source in self.node(oldp)["children"]:
                    self.node(oldp)["children"].remove(source)
                arr = self.node(parent)["children"]
                self.node(source)["parent"] = parent
                arr.insert(arr.index(target)+1, source)
                save_state(self.state)
        else:
            # make child of target, avoid cycles
            p = target; ok = True
            while p is not None:
                if p == source: ok = False; break
                p = self.node(p)["parent"]
            if ok:
                oldp = self.node(source)["parent"]
                if oldp is not None and source in self.node(oldp)["children"]:
                    self.node(oldp)["children"].remove(source)
                self.node(source)["parent"] = target
                self.node(target)["children"].append(source)
                save_state(self.state)

        self.draw_grid()

    def on_double(self, e):
        nid, _ = self.tile_at(e.x, e.y)
        if nid:
            self.set_current(nid)

    def on_right_click(self, e):
        nid, _ = self.tile_at(e.x, e.y)
        if nid:
            self.selected = nid
            self.load_into_editor(nid)
            self.show_sheet_for(nid, bg=False)
        else:
            self.show_sheet_for(bg=True)

    def on_long_press(self, x_root, y_root, nid):
        # simply show the sheet in place (no pop-ups)
        if nid:
            self.selected = nid
            self.load_into_editor(nid)
            self.show_sheet_for(nid, bg=False)
        else:
            self.show_sheet_for(bg=True)

    # ---------- Actions (wired to both quick buttons & sheet) ----------
    def add_and_focus(self, parent):
        nid = self.add_child(parent, title="Untitled")
        save_state(self.state); self.draw_grid()
        self.selected = nid; self.load_into_editor(nid); self.title_entry.focus_set()
        self.toast("Added sub‑item")

    def add_sibling_and_focus(self, nid):
        new_id_ = self.insert_sibling(nid, title="Untitled", after=True)
        if new_id_:
            save_state(self.state); self.draw_grid()
            self.selected = new_id_; self.load_into_editor(new_id_); self.title_entry.focus_set()
            self.toast("Added sibling")

    def quick_add(self, kind="sub"):
        if not self.selected:
            # add at current level
            self.add_and_focus(self.state["current"]); return
        if kind == "sub":
            self.add_and_focus(self.selected)
        else:
            self.add_sibling_and_focus(self.selected)

    def quick_delete(self):
        if not self.selected: return
        now = time.time()
        # double-tap delete to confirm within 2 sec
        if self.pending_delete["id"] == self.selected and (now - self.pending_delete["t"]) < 2.0:
            nid = self.selected
            parent = self.node(nid)["parent"]
            sibs = list(self.node(parent)["children"]) if parent else []
            idx = sibs.index(nid) if nid in sibs else -1
            self.delete_node(nid)
            save_state(self.state)
            # choose new selection
            if parent and self.node(parent)["children"]:
                kids = self.node(parent)["children"]
                self.selected = kids[min(idx, len(kids)-1)]
            else:
                self.selected = None
            self.refresh_all()
            self.toast("Deleted")
            self.pending_delete = {"id": None, "t": 0}
        else:
            self.pending_delete = {"id": self.selected, "t": now}
            self.toast("Tap Delete again to confirm")

    def set_type(self, t):
        if not self.selected: return
        self.node(self.selected)["type"] = t
        save_state(self.state); self.draw_grid(); self.load_into_editor(self.selected)
        self.toast(f"Type → {t}")

    def copy_or_cut(self, mode):
        if not self.selected: return
        self.state["clipboard"] = {"mode": mode, "tree": self.deep_copy_tree(self.selected), "src": self.selected}
        save_state(self.state)
        self.toast("Copied" if mode=="copy" else "Cut (paste to move)")

    def paste(self, into=True):
        clip = self.state.get("clipboard")
        if not clip:
            self.toast("Clipboard empty"); return
        # where to paste?
        if into:
            parent = self.selected if self.selected else self.state["current"]
            at_index = None
        else:
            if not self.selected:
                self.toast("Select a tile to paste after"); return
            parent = self.node(self.selected)["parent"]
            if parent is None:
                self.toast("Cannot paste after root"); return
            arr = self.node(parent)["children"]
            at_index = arr.index(self.selected) + 1

        # install deep copy
        self.materialize_tree(clip["tree"], parent, at_index)
        if clip["mode"] == "cut":
            # remove original source (simple move semantics)
            src = clip.get("src")
            if src and src in self.state["nodes"]:
                self.delete_node(src)
            self.state["clipboard"] = None
        save_state(self.state)
        self.draw_grid()
        self.toast("Pasted")

    # ---------- Helpers ----------
    def add_sibling_here(self):
        if self.selected:
            self.add_sibling_and_focus(self.selected)
        else:
            self.add_and_focus(self.state["current"])

# ---------- Launch ----------
if __name__ == "__main__":
    app = PSSST()
    app.mainloop()
