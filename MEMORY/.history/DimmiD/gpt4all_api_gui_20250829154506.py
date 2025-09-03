import tkinter as tk
from tkinter import ttk, messagebox, scrolledtext
import requests, time

COMMON_PORTS = [4891, 4892, 8080]  # expand if you changed it
HEADERS = {"Content-Type": "application/json", "Connection": "close"}

def base_url():
    return f"http://127.0.0.1:{int(port_var.get())}/v1"

def fetch_models(timeout=8):
    try:
        r = requests.get(f"{base_url()}/models", headers=HEADERS, timeout=timeout)
        r.raise_for_status()
        data = r.json()
        return [m.get("id") for m in data.get("data", []) if m.get("id")]
    except Exception:
        return []

def find_server():
    for p in COMMON_PORTS:
        try:
            r = requests.get(f"http://127.0.0.1:{p}/v1/models", headers=HEADERS, timeout=3)
            if r.ok and isinstance(r.json().get("data", []), list):
                port_var.set(str(p))
                models = [m.get("id") for m in r.json().get("data", []) if m.get("id")]
                model_combo["values"] = models
                if models and not model_var.get():
                    model_var.set(models[0])
                messagebox.showinfo("Found", f"API server detected on port {p}.")
                return
        except Exception:
            pass
    messagebox.showerror("Not found", "No GPT4All API server detected on common ports.\nMake sure it’s enabled in GPT4All settings.")

def do_health():
    models = fetch_models()
    if models:
        messagebox.showinfo("Server OK", f"API is up on port {port_var.get()}.\n{len(models)} model(s) available.\n\n- " + "\n- ".join(models[:5]))
        model_combo["values"] = models
        if not model_var.get():
            model_var.set(models[0])
    else:
        messagebox.showerror("Server Unreachable", f"Could not list models at:\nhttp://127.0.0.1:{port_var.get()}/v1/models\nCheck server ON + port + firewall.")

def send_chat(model_id, prompt, max_tokens=256, temperature=0.2, retries=2):
    payload = {
        "model": model_id,
        "messages": [{"role":"user","content":prompt}],
        "max_tokens": max_tokens, "temperature": temperature
    }
    last_err = None
    for attempt in range(retries+1):
        try:
            r = requests.post(f"{base_url()}/chat/completions",
                              json=payload, headers=HEADERS, timeout=90)
            r.raise_for_status()
            j = r.json()
            ch0 = (j.get("choices") or [{}])[0]
            content = (ch0.get("message") or {}).get("content","").strip()
            refs = ch0.get("references") or []
            return content or "[No content returned.]", refs, None
        except (requests.exceptions.ConnectionError, requests.exceptions.Timeout) as e:
            last_err = e
            if attempt < retries:
                time.sleep(1.5 * (attempt+1))
                continue
            return None, None, f"Connection/Timeout: {e}"
        except requests.exceptions.HTTPError as e:
            body = ""
            try: body = r.text
            except: pass
            return None, None, f"HTTP {r.status_code}: {e}\n{body}"
        except Exception as e:
            return None, None, f"Error: {e}"
    return None, None, f"Unknown error: {last_err}"

def on_send():
    model_id = model_var.get().strip()
    prompt = prompt_editor.get("1.0", tk.END).strip()
    if not model_id or not prompt:
        messagebox.showwarning("Missing Info", "Select a model, a port, and enter a prompt.")
        return
    chat_log.config(state="normal")
    chat_log.insert(tk.END, f"\nYou:\n{prompt}\n", "user"); chat_log.config(state="disabled"); root.update()
    content, refs, err = send_chat(model_id, prompt)
    chat_log.config(state="normal")
    if err:
        chat_log.insert(tk.END, f"\nAI (error):\n{err}\n", "ai_err")
    else:
        chat_log.insert(tk.END, f"\nAI:\n{content}\n", "ai_ok")
        if refs:
            chat_log.insert(tk.END, "\nSources:\n", "refs_hdr")
            for i, ref in enumerate(refs, 1):
                title = ref.get("title") or "Untitled"
                page = ref.get("page")
                pg = f" — page {page}" if page is not None else ""
                text = (ref.get("text") or "").replace("\n"," ")
                text = (text[:200] + "...") if len(text) > 200 else text
                chat_log.insert(tk.END, f"[{i}] {title}{pg} — {text}\n", "refs")
        else:
            chat_log.insert(tk.END, "(No citations. If LocalDocs is enabled for the API server chat, ask a doc-specific question.)\n", "refs_note")
    chat_log.config(state="disabled"); chat_log.see(tk.END); prompt_editor.delete("1.0", tk.END)

def on_close():
    if messagebox.askokcancel("Quit", "Close the chat?"): root.destroy()

# UI
root = tk.Tk(); root.title("GPT4All Local API Chat (Port + Model Dropdown)"); root.protocol("WM_DELETE_WINDOW", on_close)

row = tk.Frame(root); row.pack(fill="x", padx=10, pady=8)
tk.Label(row, text="Port:").pack(side="left")
port_var = tk.StringVar(value="4891")
port_entry = ttk.Entry(row, textvariable=port_var, width=8); port_entry.pack(side="left", padx=6)
ttk.Button(row, text="Find Server", command=find_server).pack(side="left", padx=6)
ttk.Button(row, text="Health Check", command=do_health).pack(side="left", padx=6)

row2 = tk.Frame(root); row2.pack(fill="x", padx=10, pady=(0,8))
tk.Label(row2, text="Model:").pack(side="left")
model_var = tk.StringVar()
model_combo = ttk.Combobox(row2, textvariable=model_var, width=42, state="readonly"); model_combo.pack(side="left", padx=6)

chat_log = scrolledtext.ScrolledText(root, height=20, width=100, state="disabled", font=("Consolas", 11))
chat_log.tag_config("user", foreground="#004"); chat_log.tag_config("ai_ok", foreground="#040"); chat_log.tag_config("ai_err", foreground="#800")
chat_log.tag_config("refs_hdr", font=("Consolas", 11, "bold")); chat_log.tag_config("refs", foreground="#333"); chat_log.tag_config("refs_note", foreground="#666")
chat_log.pack(padx=10, pady=8)

prompt_editor = tk.Text(root, height=6, width=100, font=("Consolas", 11)); prompt_editor.pack(padx=10, pady=(0,8))
ttk.Button(root, text="Send", command=on_send).pack(pady=6)

# Optional: initial auto-find
find_server()
root.mainloop()
