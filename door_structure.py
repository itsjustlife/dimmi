"""Structure editing pane for DOOR.

This module provides a :class:`StructurePane` widget which mimics a very small
subset of the functionality of the Arkhivist project.  The widget exposes a
simple tree editor capable of loading/saving OPML structures.  The real
Arkhivist project exposes ``Node`` objects and a set of OPML helpers.  We try to
import these first and fall back to small, self contained stand‑ins so the code
remains dependency free.

The implementation here only aims to provide enough features for the demo
application; most operations are simplified and many are stubs that simply
manipulate the in‑memory ``Node`` tree and rebuild the ``Treeview``.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Callable, List, Optional

try:  # pragma: no cover - optional dependency
    # The real project exposes a richer API; we only need a couple helpers.
    from arkhiver import Node, load_opml, save_opml, loads_opml, dumps_opml
except Exception:  # pragma: no cover - fallback implementation
    import xml.etree.ElementTree as ET

    @dataclass
    class Node:
        """Minimal tree node used for OPML import/export."""

        text: str = ""
        content: str = ""
        children: List["Node"] = field(default_factory=list)
        parent: Optional["Node"] = field(default=None, repr=False)

        def add_child(self, node: "Node", index: Optional[int] = None) -> None:
            node.parent = self
            if index is None:
                self.children.append(node)
            else:
                self.children.insert(index, node)

        def remove(self) -> None:
            if self.parent is not None:
                self.parent.children.remove(self)
                self.parent = None

    def _node_from_elem(elem: ET.Element) -> Node:
        node = Node(text=elem.attrib.get("text", ""), content=elem.attrib.get("content", ""))
        for child in elem.findall("outline"):
            node.add_child(_node_from_elem(child))
        return node

    def load_opml(path: str) -> Node:
        tree = ET.parse(path)
        body = tree.getroot().find("body")
        root = Node("root")
        if body is not None:
            for elem in body.findall("outline"):
                root.add_child(_node_from_elem(elem))
        return root

    def loads_opml(data: str) -> Node:
        root_elem = ET.fromstring(data)
        body = root_elem.find("body")
        root = Node("root")
        if body is not None:
            for elem in body.findall("outline"):
                root.add_child(_node_from_elem(elem))
        return root

    def _elem_from_node(node: Node) -> ET.Element:
        elem = ET.Element("outline", text=node.text)
        if node.content:
            elem.set("content", node.content)
        for child in node.children:
            elem.append(_elem_from_node(child))
        return elem

    def save_opml(node: Node, path: str) -> None:
        opml = ET.Element("opml", version="2.0")
        head = ET.SubElement(opml, "head")  # noqa: F841 - placeholder
        body = ET.SubElement(opml, "body")
        for child in node.children:
            body.append(_elem_from_node(child))
        ET.ElementTree(opml).write(path, encoding="utf-8", xml_declaration=True)

    def dumps_opml(node: Node) -> str:
        opml = ET.Element("opml", version="2.0")
        ET.SubElement(opml, "head")
        body = ET.SubElement(opml, "body")
        for child in node.children:
            body.append(_elem_from_node(child))
        return ET.tostring(opml, encoding="utf-8", xml_declaration=True).decode("utf-8")


import tkinter as tk
from tkinter import ttk, simpledialog, messagebox


class StructurePane(ttk.Frame):
    """Simple tree editor widget."""

    def __init__(self, master, *, on_select: Optional[Callable[[Node], None]] = None):
        super().__init__(master)
        self.on_select = on_select
        self._clipboard: Optional[Node] = None
        self.root_node = Node("root")

        self._build_widgets()
        self.refresh()

    # ------------------------------------------------------------------ UI
    def _build_widgets(self) -> None:
        style = ttk.Style()
        style.configure("Header.TButton", padding=2)

        toolbar = ttk.Frame(self)
        toolbar.pack(fill=tk.X)

        btn = ttk.Button
        self.add_child_btn = btn(toolbar, text="Add Child", command=self.add_child, style="Header.TButton")
        self.add_child_btn.pack(side=tk.LEFT)
        self.add_sibling_btn = btn(toolbar, text="Add Sibling", command=self.add_sibling, style="Header.TButton")
        self.add_sibling_btn.pack(side=tk.LEFT)
        self.up_btn = btn(toolbar, text="Up", command=lambda: self.move(-1), style="Header.TButton")
        self.up_btn.pack(side=tk.LEFT)
        self.down_btn = btn(toolbar, text="Down", command=lambda: self.move(1), style="Header.TButton")
        self.down_btn.pack(side=tk.LEFT)
        self.rename_btn = btn(toolbar, text="Rename", command=self.rename_node, style="Header.TButton")
        self.rename_btn.pack(side=tk.LEFT)
        self.delete_btn = btn(toolbar, text="Delete", command=self.delete_node, style="Header.TButton")
        self.delete_btn.pack(side=tk.LEFT)
        self.copy_btn = btn(toolbar, text="Copy", command=self.copy_node, style="Header.TButton")
        self.copy_btn.pack(side=tk.LEFT)
        self.cut_btn = btn(toolbar, text="Cut", command=self.cut_node, style="Header.TButton")
        self.cut_btn.pack(side=tk.LEFT)
        self.paste_btn = btn(toolbar, text="Paste", command=self.paste_node, style="Header.TButton")
        self.paste_btn.pack(side=tk.LEFT)
        ttk.Separator(toolbar, orient=tk.VERTICAL).pack(side=tk.LEFT, fill=tk.Y, padx=4)
        self.image_btn = btn(toolbar, text="Attach Image", command=self.attach_image, style="Header.TButton")
        self.image_btn.pack(side=tk.LEFT)
        self.url_btn = btn(toolbar, text="Open URL", command=self.open_url, style="Header.TButton")
        self.url_btn.pack(side=tk.LEFT)

        self.tree = ttk.Treeview(self, show="tree")
        self.tree.pack(fill=tk.BOTH, expand=True)
        self.tree.bind("<<TreeviewSelect>>", self._on_select)
        self.tree.bind("<Double-1>", lambda e: self.rename_node())
        self.tree.bind("<F2>", lambda e: self.rename_node())
        self.tree.bind("<Control-Return>", lambda e: self.add_child())
        self.tree.bind("<Control-Shift-Return>", lambda e: self.add_sibling())
        self.tree.bind("<Delete>", lambda e: self.delete_node())

        # Context menu
        self.menu = tk.Menu(self, tearoff=False)
        self.menu.add_command(label="Add Child", command=self.add_child)
        self.menu.add_command(label="Add Sibling", command=self.add_sibling)
        self.menu.add_separator()
        self.menu.add_command(label="Rename", command=self.rename_node)
        self.menu.add_command(label="Delete", command=self.delete_node)
        self.menu.add_separator()
        self.menu.add_command(label="Copy", command=self.copy_node)
        self.menu.add_command(label="Cut", command=self.cut_node)
        self.menu.add_command(label="Paste", command=self.paste_node)
        self.tree.bind("<Button-3>", self._popup_menu)

    # ----------------------------------------------------------------- helpers
    def _popup_menu(self, event) -> None:  # pragma: no cover - UI helper
        try:
            self.tree.selection_set(self.tree.identify_row(event.y))
        finally:
            self.menu.tk_popup(event.x_root, event.y_root)

    def _on_select(self, _event) -> None:
        if self.on_select:
            node = self.get_selected_node()
            if node:
                self.on_select(node)

    # ---------------------------------------------------------- tree rebuild
    def refresh(self) -> None:
        """Rebuild the ``Treeview`` from ``root_node``."""
        self.tree.delete(*self.tree.get_children(""))

        def add_items(parent_id: str, node: Node) -> None:
            for child in node.children:
                iid = self.tree.insert(parent_id, "end", text=child.text, values=(id(child),))
                self.tree.set(iid, column="#0")  # ensure text column exists
                self.tree.item(iid, tags=(str(id(child)),))
                add_items(iid, child)

        add_items("", self.root_node)
        self.tree.expand_all = getattr(self.tree, "expand_all", lambda: None)  # compatibility

    # -------------------------------------------------------------- selections
    def get_selected_node(self) -> Optional[Node]:
        sel = self.tree.selection()
        if not sel:
            return None
        iid = sel[0]
        node_id = int(self.tree.item(iid, "tags")[0])
        return self._find_node(self.root_node, node_id)

    def _find_node(self, node: Node, ident: int) -> Optional[Node]:
        if id(node) == ident:
            return node
        for child in node.children:
            found = self._find_node(child, ident)
            if found:
                return found
        return None

    # --------------------------------------------------------------- mutations
    def add_child(self) -> None:
        parent = self.get_selected_node() or self.root_node
        parent.add_child(Node("New"))
        self.refresh()

    def add_sibling(self) -> None:
        node = self.get_selected_node()
        if not node or not node.parent:
            return
        idx = node.parent.children.index(node) + 1
        node.parent.add_child(Node("New"), index=idx)
        self.refresh()

    def move(self, offset: int) -> None:
        node = self.get_selected_node()
        if not node or not node.parent:
            return
        sibs = node.parent.children
        idx = sibs.index(node)
        new_idx = max(0, min(len(sibs) - 1, idx + offset))
        if new_idx == idx:
            return
        sibs.insert(new_idx, sibs.pop(idx))
        self.refresh()

    def rename_node(self) -> None:  # pragma: no cover - requires UI
        node = self.get_selected_node()
        if not node:
            return
        new_name = simpledialog.askstring("Rename", "New name:", initialvalue=node.text)
        if new_name:
            node.text = new_name
            self.refresh()

    def delete_node(self) -> None:
        node = self.get_selected_node()
        if node and node.parent:
            node.remove()
            self.refresh()

    def copy_node(self) -> None:
        node = self.get_selected_node()
        if node:
            import copy

            self._clipboard = copy.deepcopy(node)

    def cut_node(self) -> None:
        node = self.get_selected_node()
        if node and node.parent:
            self.copy_node()
            node.remove()
            self.refresh()

    def paste_node(self) -> None:
        parent = self.get_selected_node() or self.root_node
        if self._clipboard:
            import copy

            parent.add_child(copy.deepcopy(self._clipboard))
            self.refresh()

    # --------------------------------------------------------- file operations
    def load_opml_file(self, path: str) -> None:
        self.root_node = load_opml(path)
        self.refresh()

    def save_opml_file(self, path: str) -> None:
        save_opml(self.root_node, path)

    def load_from_string(self, data: str) -> None:
        self.root_node = loads_opml(data)
        self.refresh()

    def export_to_string(self) -> str:
        return dumps_opml(self.root_node)

    # ----------------------------------------------------------- placeholders
    def attach_image(self) -> None:  # pragma: no cover - UI stub
        messagebox.showinfo("Attach Image", "Not implemented")

    def open_url(self) -> None:  # pragma: no cover - UI stub
        messagebox.showinfo("Open URL", "Not implemented")


__all__ = ["StructurePane", "Node", "load_opml", "save_opml", "loads_opml", "dumps_opml"]

