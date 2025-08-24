# Arkhivist - Full Refined and Debugged Version

import tkinter as tk
from tkinter import ttk, filedialog, messagebox
import os
import json
import xml.etree.ElementTree as ET

class ArkhivistApp:
    def __init__(self, root):
        self.root = root
        self.root.title("Arkhivist")
        self.root.geometry("1000x800")

        self.folder_structure = {}
        self.include_notes = tk.BooleanVar(value=True)
        self.output_format = tk.StringVar(value='txt')

        self.create_widgets()

    def create_widgets(self):
        top_frame = ttk.Frame(self.root)
        top_frame.pack(fill=tk.X)

        ttk.Button(top_frame, text="Load JSON", command=self.load_structure_file).pack(side=tk.LEFT, padx=5)
        ttk.Button(top_frame, text="Load OPML", command=self.load_structure_opml).pack(side=tk.LEFT, padx=5)
        ttk.Button(top_frame, text="Open Folder Structure", command=self.open_folder_structure).pack(side=tk.LEFT, padx=5)
        ttk.Button(top_frame, text="New Folder Structure", command=self.new_folder_structure).pack(side=tk.LEFT, padx=5)

        columns = ('Note', 'Note File Name')
        self.tree = ttk.Treeview(self.root, columns=columns, show='tree headings')
        self.tree.heading('#0', text='Folder Structure Viewer')
        self.tree.heading('Note', text='Note')
        self.tree.heading('Note File Name', text='Note File Name')
        self.tree.pack(fill=tk.BOTH, expand=True)

        export_frame = ttk.LabelFrame(self.root, text="EXPORT")
        export_frame.pack(fill=tk.X, pady=10)
        ttk.Button(export_frame, text="Create Folder Structure", command=self.start_create_folder_structure).pack(side=tk.LEFT, padx=5)
        ttk.Button(export_frame, text="Export to JSON", command=lambda: self.export_code('json')).pack(side=tk.LEFT, padx=5)
        ttk.Button(export_frame, text="Export to OPML", command=lambda: self.export_code('opml')).pack(side=tk.LEFT, padx=5)

        self.status_var = tk.StringVar()
        self.status_bar = ttk.Label(self.root, textvariable=self.status_var, relief=tk.SUNKEN, anchor='w')
        self.status_bar.pack(fill=tk.X, side=tk.BOTTOM)

    def refresh_treeview(self):
        self.tree.delete(*self.tree.get_children())

        def insert_items(parent, structure):
            for name, content in structure.items():
                note = content.get('_note', '')
                filename = content.get('_note_filename', '')
                node_id = self.tree.insert(parent, 'end', text=name, values=(note, filename), open=True)
                insert_items(node_id, content.get('subfolders', {}))

        insert_items('', self.folder_structure)

    def load_structure_file(self):
        file_path = filedialog.askopenfilename(filetypes=[("JSON files", "*.json")])
        if file_path:
            with open(file_path, 'r', encoding='utf-8') as f:
                self.folder_structure = json.load(f)
                self.refresh_treeview()
                self.status_var.set(f"Loaded structure from {file_path}")

    def load_structure_opml(self):
        file_path = filedialog.askopenfilename(filetypes=[("OPML files", "*.opml")])
        if file_path:
            self.load_opml_file(file_path)

    def load_opml_file(self, file_path):
        tree = ET.parse(file_path)
        root = tree.getroot()
        body = root.find('body')
        self.folder_structure = self.parse_opml_outline(body)
        self.refresh_treeview()
        self.status_var.set(f"Loaded OPML from {file_path}")

    def parse_opml_outline(self, element):
        structure = {}
        for outline in element.findall('outline'):
            name = outline.attrib.get('text')
            note = outline.attrib.get('_note', '')
            filename = outline.attrib.get('_note_filename', '')
            structure[name] = {
                '_note': note,
                '_note_filename': filename,
                'subfolders': self.parse_opml_outline(outline)
            }
        return structure

    def new_folder_structure(self):
        self.folder_structure = {}
        self.refresh_treeview()
        self.status_var.set("Started new folder structure")

    def open_folder_structure(self):
        folder_path = filedialog.askdirectory()
        if folder_path:
            self.folder_structure = self.scan_directory(folder_path)
            self.refresh_treeview()
            self.status_var.set(f"Loaded folder structure from {folder_path}")

    def scan_directory(self, path):
        structure = {}
        for entry in os.scandir(path):
            if entry.is_dir():
                subfolders = self.scan_directory(entry.path)
                note, filename = '', ''
                for file in os.listdir(entry.path):
                    if file.endswith('.txt') or file.endswith('.html'):
                        filepath = os.path.join(entry.path, file)
                        with open(filepath, 'r', encoding='utf-8') as f:
                            note = f.read()
                        filename = file
                        break
                structure[entry.name] = {
                    '_note': note,
                    '_note_filename': filename,
                    'subfolders': subfolders
                }
        return structure

    def start_create_folder_structure(self):
        path = filedialog.askdirectory()
        if path:
            self.create_folders(path, self.folder_structure)
            self.status_var.set(f"Folder structure created at {path}")

    def create_folders(self, base, structure):
        for name, content in structure.items():
            folder = os.path.join(base, name)
            os.makedirs(folder, exist_ok=True)
            note = content.get('_note', '')
            filename = content.get('_note_filename', '')
            if note and filename:
                with open(os.path.join(folder, filename), 'w', encoding='utf-8') as f:
                    f.write(note)
            self.create_folders(folder, content.get('subfolders', {}))

    def export_code(self, format_type):
        file_path = filedialog.asksaveasfilename(defaultextension=f".{format_type}")
        if not file_path:
            return

        if format_type == 'json':
            with open(file_path, 'w', encoding='utf-8') as f:
                json.dump(self.folder_structure, f, indent=4, ensure_ascii=False)
            self.status_var.set(f"Exported to {file_path}")
        elif format_type == 'opml':
            opml = ET.Element('opml', version='2.0')
            head = ET.SubElement(opml, 'head')
            ET.SubElement(head, 'title').text = 'Arkhivist Structure'
            body = ET.SubElement(opml, 'body')
            self.build_opml_outline(body, self.folder_structure)
            tree = ET.ElementTree(opml)
            tree.write(file_path, encoding='utf-8', xml_declaration=True)
            self.status_var.set(f"Exported to {file_path}")

    def build_opml_outline(self, parent, structure):
        for name, content in structure.items():
            outline = ET.SubElement(parent, 'outline', text=name)
            if content.get('_note'):
                outline.attrib['_note'] = content['_note']
            if content.get('_note_filename'):
                outline.attrib['_note_filename'] = content['_note_filename']
            self.build_opml_outline(outline, content.get('subfolders', {}))

    def global_shortcuts(self, event):
        if event.keysym == 'n' and event.state & 0x4:  # Ctrl+N
            self.new_folder_structure()


def main():
    root = tk.Tk()
    app = ArkhivistApp(root)
    root.mainloop()


if __name__ == "__main__":
    main()
