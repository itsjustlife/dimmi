import json
import os
import sys
import tkinter as tk
from tkinter import simpledialog, messagebox, filedialog
from pathlib import Path
from importlib import util
import subprocess

ROOT = Path(__file__).resolve().parent
DATA_DIR = ROOT / "data"
STRUCT_DIR = ROOT / "structures"
CONTENT_DIR = ROOT / "content"
TEMPLATE_DIR = ROOT / "templates"
OUTBOX = ROOT / "agents" / "codex_outbox"

# @@todo: split logic into modules when project grows


def load_json(path, default):
    try:
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception:
        return default


def save_json(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2, ensure_ascii=False)


def load_state():
    return load_json(ROOT / 'door_data.json',
                     {"window": {"width": 800, "height": 600}, "view": "list"})


def save_state():
    door_state['window'] = {'width': root.winfo_width(), 'height': root.winfo_height()}
    door_state['view'] = current_view
    save_json(ROOT / 'door_data.json', door_state)


def load_items():
    items = {}
    try:
        with open(DATA_DIR / 'items.jsonl', 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line:
                    it = json.loads(line)
                    items[it['id']] = it
    except Exception:
        seed = {"id": "seed", "title": "Seed", "type": "container", "tags": ["root"],
                "content_path": "content/seed.md", "children": [], "relations": []}
        items['seed'] = seed
        save_items(items)
    return items


def save_items(items):
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    with open(DATA_DIR / 'items.jsonl', 'w', encoding='utf-8') as f:
        for it in items.values():
            f.write(json.dumps(it, ensure_ascii=False) + '\n')


def load_structure():
    return load_json(STRUCT_DIR / 'current.structure.json',
                     {"root": "seed", "items": ["seed"]})


def save_structure(struct):
    save_json(STRUCT_DIR / 'current.structure.json', struct)


def update_ui():
    item = items[current_id]
    title_var.set(item.get('title', ''))
    tags_var.set(' '.join(item.get('tags', [])))
    children_list.delete(0, tk.END)
    for cid in item.get('children', []):
        child = items.get(cid, {'title': cid, 'type': 'item'})
        children_list.insert(tk.END, f"{child['title']} ({child['type']})")
    run_button.config(state=('normal' if item.get('type') == 'action' else 'disabled'))
    narrator_msg(f"Viewing: {item.get('title')}")


def on_child_select(event):
    sel = children_list.curselection()
    if sel:
        idx = sel[0]
        cid = items[current_id].get('children', [])[idx]
        set_current(cid)


def set_current(cid):
    global current_id
    current_id = cid
    update_ui()


def narrator_msg(msg):
    narrator.config(state='normal')
    narrator.insert(tk.END, msg + '\n')
    narrator.see(tk.END)
    narrator.config(state='disabled')


def on_door_click(event):
    narrator_msg("This is an Item. It has a title and a type.")


def on_next():
    narrator_msg("Next... (more levels to come)")


def on_save():
    item = items[current_id]
    item['title'] = title_var.get().strip() or item['title']
    item['tags'] = [t for t in tags_var.get().split() if t]
    save_items(items)
    save_structure(structure)
    save_state()
    narrator_msg("Saved.")


def on_load():
    global items, structure
    items = load_items()
    structure = load_structure()
    set_current(structure.get('root', 'seed'))
    narrator_msg("Reloaded from disk.")


def create_item(prefill_type=None):
    dlg = tk.Toplevel(root)
    dlg.title("Create Item")
    tk.Label(dlg, text="Title").pack()
    title_e = tk.Entry(dlg)
    title_e.pack()
    tk.Label(dlg, text="Type").pack()
    type_v = tk.StringVar(value=prefill_type or 'item')
    tk.OptionMenu(dlg, type_v, 'item', 'link', 'content', 'action', 'container', 'view').pack()
    tk.Label(dlg, text="Tags (space)").pack()
    tags_e = tk.Entry(dlg)
    tags_e.pack()
    action_kind = tk.StringVar(value='open_folder')
    action_menu = None
    def on_type_change(*_):
        nonlocal action_menu
        if type_v.get() == 'action':
            if not action_menu:
                tk.Label(dlg, text="Action").pack()
                action_menu = tk.OptionMenu(dlg, action_kind, 'open_folder', 'run_template')
                action_menu.pack()
        elif action_menu:
            action_menu.destroy()
            action_menu = None
    type_v.trace_add('write', on_type_change)

    def ok():
        title = title_e.get().strip() or 'Item'
        new_id = f"item_{len(items)+1}"
        item = {
            'id': new_id,
            'title': title,
            'type': type_v.get(),
            'tags': [t for t in tags_e.get().split() if t],
            'content_path': '',
            'children': [],
            'relations': []
        }
        if item['type'] == 'content':
            path = CONTENT_DIR / f"{new_id}.md"
            with open(path, 'w', encoding='utf-8') as f:
                f.write(f"# {title}\n")
            item['content_path'] = f"content/{new_id}.md"
            edit_content(path)
        if item['type'] == 'action':
            if action_kind.get() == 'run_template':
                item['content_path'] = str(TEMPLATE_DIR / 'module_template.py')
            else:
                item['content_path'] = 'open_folder'
        items[new_id] = item
        items[current_id].setdefault('children', []).append(new_id)
        structure.setdefault('items', []).append(new_id)
        save_items(items)
        save_structure(structure)
        update_ui()
        dlg.destroy()
    tk.Button(dlg, text="OK", command=ok).pack()


def edit_content(path):
    dlg = tk.Toplevel(root)
    dlg.title("Edit Content")
    txt = tk.Text(dlg)
    txt.pack(fill='both', expand=True)
    if path.exists():
        with open(path, 'r', encoding='utf-8') as f:
            txt.insert('1.0', f.read())
    def save_and_close():
        with open(path, 'w', encoding='utf-8') as f:
            f.write(txt.get('1.0', tk.END))
        dlg.destroy()
    tk.Button(dlg, text="Save", command=save_and_close).pack()


def link_items():
    dlg = tk.Toplevel(root)
    dlg.title("Create Link")
    tk.Label(dlg, text="Target ID").pack()
    target_v = tk.StringVar(value='')
    options = [i for i in items.keys() if i != current_id]
    if not options:
        tk.Label(dlg, text="No items to link to").pack()
    else:
        tk.OptionMenu(dlg, target_v, *options).pack()
        tk.Label(dlg, text="Kind").pack()
        kind_v = tk.StringVar(value='related')
        tk.OptionMenu(dlg, kind_v, 'related', 'contains', 'uses').pack()
        def ok():
            t = target_v.get()
            k = kind_v.get()
            if not t:
                return
            link = {'from': current_id, 'to': t, 'kind': k}
            with open(DATA_DIR / 'links.jsonl', 'a', encoding='utf-8') as f:
                f.write(json.dumps(link) + '\n')
            items[current_id].setdefault('relations', []).append({'to': t, 'kind': k})
            items[t].setdefault('relations', []).append({'to': current_id, 'kind': k})
            save_items(items)
            dlg.destroy()
        tk.Button(dlg, text="OK", command=ok).pack()


def toggle_view():
    global current_view
    views = ['list', 'grid', '3x3']
    idx = views.index(current_view)
    current_view = views[(idx + 1) % len(views)]
    narrator_msg(f"View: {current_view}")


def export_html():
    def render(item_id, indent=0):
        item = items[item_id]
        line = '  ' * indent + f"<li><a href='{item.get('content_path','')}'>{item['title']}</a>"
        children = item.get('children', [])
        if children:
            line += "<ul>\n" + '\n'.join(render(c, indent + 1) for c in children) + "\n" + '  ' * indent + "</ul>"
        line += "</li>"
        return line
    html = "<html><body><ul>" + render(structure['root']) + "</ul></body></html>"
    path = STRUCT_DIR / 'export.html'
    with open(path, 'w', encoding='utf-8') as f:
        f.write(html)
    narrator_msg(f"Exported HTML to {path}")


def export_opml():
    def render(item_id, indent=0):
        item = items[item_id]
        line = '  ' * indent + f"<outline text='{item['title']}'>\n"
        for c in item.get('children', []):
            line += render(c, indent + 1)
        line += '  ' * indent + "</outline>\n"
        return line
    opml = "<?xml version='1.0' encoding='UTF-8'?><opml version='2.0'><body>\n" + render(structure['root']) + "</body></opml>"
    path = STRUCT_DIR / 'export.opml'
    with open(path, 'w', encoding='utf-8') as f:
        f.write(opml)
    narrator_msg(f"Exported OPML to {path}")


def on_export():
    export_html()
    export_opml()


def run_selected_action():
    item = items[current_id]
    if item.get('type') != 'action':
        return
    log_path = OUTBOX / 'last_action.log'
    OUTBOX.mkdir(parents=True, exist_ok=True)
    def log(msg):
        with open(log_path, 'a', encoding='utf-8') as f:
            f.write(msg + '\n')
    if item.get('content_path') == 'open_folder':
        path = str(ROOT)
        try:
            if sys.platform.startswith('win'):
                os.startfile(path)
            elif sys.platform == 'darwin':
                subprocess.Popen(['open', path])
            else:
                subprocess.Popen(['xdg-open', path])
            log('Opened folder')
        except Exception as e:
            log(f'Failed to open folder: {e}')
    else:
        module_path = item.get('content_path')
        try:
            spec = util.spec_from_file_location('action_module', module_path)
            mod = util.module_from_spec(spec)
            spec.loader.exec_module(mod)
            mod.run({'repo_root': str(ROOT), 'current_item': item, 'log': log})
            log('Module run')
        except Exception as e:
            log(f'Action error: {e}')


def on_close():
    save_state()
    root.destroy()


door_state = load_state()
items = load_items()
structure = load_structure()
current_id = structure.get('root', 'seed')
current_view = door_state.get('view', 'list')

root = tk.Tk()
root.title('DOOR')
root.geometry(f"{door_state['window']['width']}x{door_state['window']['height']}")

root.grid_rowconfigure(0, weight=1)
root.grid_columnconfigure(1, weight=1)

inventory_frame = tk.Frame(root)
inventory_frame.grid(row=0, column=0, sticky='ns')

center_frame = tk.Frame(root)
center_frame.grid(row=0, column=1, sticky='nsew')

right_frame = tk.Frame(root)
right_frame.grid(row=0, column=2, sticky='ns')

bottom_frame = tk.Frame(root)
bottom_frame.grid(row=1, column=0, columnspan=3, sticky='ew')

# Inventory palette
for t in ['item', 'container', 'content', 'link', 'action', 'view']:
    tk.Button(inventory_frame, text=t.title(), command=lambda tt=t: create_item(tt)).pack(fill='x')

# Door canvas
canvas = tk.Canvas(center_frame, width=100, height=100, bg='black')
canvas.pack(expand=True)
canvas.bind('<Button-1>', on_door_click)

# Narrator and item fields
narrator = tk.Text(right_frame, width=40, height=10, state='disabled')
narrator.pack(fill='both', expand=True)

tk.Label(right_frame, text='Title').pack()

title_var = tk.StringVar()

title_entry = tk.Entry(right_frame, textvariable=title_var)
title_entry.pack(fill='x')
tk.Label(right_frame, text='Tags').pack()

tags_var = tk.StringVar()

tags_entry = tk.Entry(right_frame, textvariable=tags_var)
tags_entry.pack(fill='x')

children_list = tk.Listbox(right_frame)
children_list.pack(fill='both', expand=True)
children_list.bind('<<ListboxSelect>>', on_child_select)

# Bottom bar
run_button = tk.Button(bottom_frame, text='Run', command=run_selected_action)
run_button.pack(side='right')

for text, cmd in [
    ('Next', on_next),
    ('Save', on_save),
    ('Load', on_load),
    ('Export', on_export),
    ('+', create_item),
    ('Link', link_items),
    ('View', toggle_view),
]:
    tk.Button(bottom_frame, text=text, command=cmd).pack(side='left')

# Menu
menubar = tk.Menu(root)
debug = tk.Menu(menubar, tearoff=0)
debug.add_command(label='Reload', command=on_load)
menubar.add_cascade(label='Debug', menu=debug)
root.config(menu=menubar)

def on_key(event):
    if current_view == '3x3' and event.keysym.isdigit():
        idx = int(event.keysym) - 1
        children = items[current_id].get('children', [])
        if 0 <= idx < len(children):
            set_current(children[idx])
    else:
        narrator_msg(f"Key: {event.keysym}")

root.bind('<Key>', on_key)
root.protocol('WM_DELETE_WINDOW', on_close)

update_ui()

if __name__ == '__main__':
    root.mainloop()
