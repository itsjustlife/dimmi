import tkinter as tk
from tkinter import ttk, messagebox, scrolledtext
import requests, time

API_BASE = "http://127.0.0.1:4891/v1"  # force IPv4 + HTTP
API_MODELS = f"{API_BASE}/models"
API_CHAT   = f"{API_BASE}/chat/completions"
HEADERS    = {"Content-Type": "application/json", "Connection": "close"}

def fetch_models(timeout=10):
    try:
        r = requests.get(API_MODELS, headers=HEADERS, timeout=timeout)
        r.raise_for_status()
        data = r.json()
        # Expect {"data":[{"id":"..."}], "object":"list"}
        return [m.get("id") for m in data.get("data", []) if m.get("id")]
    except Exception as e:
        return []

def send_chat(model_id, prompt, max_tokens=256, temperature=0.2, retries=2):
    payload = {
        "model": model_id,
        "messages": [
            {"role": "system", "content": "Be concise. If you used LocalDocs, include citations."},
            {"role": "user", "content": prompt}
        ],
        "max_tokens": max_tokens,
        "temperature": temperature
    }
    last_err = None
    for attempt in range(retries + 1):
        try:
            r = requests.post(API_CHAT, json=payload, headers=HEADERS, timeout=90)
            r.raise_for_status()
            j = r.json()
            choice0 = (j.get("choices") or [{}])[0]
            content = (choice0.get("message") or {}).get("content", "").strip()
            refs = choice0.get("references") or []
            return content or "[No content returned.]", refs, None
        except (requests.exceptions.ConnectionError, requests.exceptions.ReadTimeout) as e:
            last_err = e
            if attempt < retries:
                time.sleep(1.5 * (attempt + 1))
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

def do_health():
    models = fetch_models()
    if models:
        messagebox.showinfo("Server OK", f"API is up. {len(models)} model(s) available.\n\nFirst few:\n- " + "\n- ".join(models[:5]))
        model_combo["values"] = models
        if not model_var.get():
            model_var.set(models[0])
    else:
        messagebox.showerror("Server Unreachable", f"Could not list models at:\n{API_MODELS}\n\nCheck GPT4All > Settings > Enable Local API Server.")

def on_send():
    model_id = model_var.get().strip()
    prompt = prompt_editor.get("1.0", tk.END).strip()
    if not model_id or not prompt:
        messagebox.showwarning("Missing Info", "Select a model and enter a prompt.")
        return

    chat_log.config(state="normal")
    chat_log.insert(tk.END, f"\nYou:\n{prompt}\n", "user")
    chat_log.config(state="disabled")
    root.update()

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
                page  = ref.get("page")
                pg    = f" — page {page}" if page is not None else ""
                text  = (ref.get("text") or "").replace("\n", " ")
                text  = (text[:200] + "...") if len(text) > 200 else text
                chat_log.insert(tk.END, f"[{i}] {title}{pg} — {text}\n", "refs")
        else:
            chat_log.insert(tk.END, "(No citations returned. If LocalDocs is enabled for the API server chat, try a doc-specific question.)\n", "refs_note")
    chat_log.config(state="disabled")
    chat_log.see(tk.END)
    prompt_editor.delete("1.0", tk.END)

def on_close():
    if messagebox.askokcancel("Quit", "Close the chat?"):
        root.destroy()

# ---------- UI ----------
root = tk.Tk()
root.title("GPT4All Local API Chat (Model Dropdown + LocalDocs citations)")
root.protocol("WM_DELETE_WINDOW", on_close)

top = tk.Frame(root)
top.pack(fill="x", padx=10, pady=8)

tk.Label(top, text="Model:").pack(side="left")
model_var = tk.StringVar()
model_combo = ttk.Combobox(top, textvariable=model_var, width=40, state="readonly")
model_combo.pack(side="left", padx=6)

ttk.Button(top, text="Health Check", command=do_health).pack(side="left", padx=6)

chat_log = scrolledtext.ScrolledText(root, height=20, width=100, state="disabled", font=("Consolas", 11))
chat_log.tag_config("user", foreground="#004")
chat_log.tag_config("ai_ok", foreground="#040")
chat_log.tag_config("ai_err", foreground="#800")
chat_log.tag_config("refs_hdr", font=("Consolas", 11, "bold"))
chat_log.tag_config("refs", foreground="#333")
chat_log.tag_config("refs_note", foreground="#666")
chat_log.pack(padx=10, pady=8)

prompt_editor = tk.Text(root, height=6, width=100, font=("Consolas", 11))
prompt_editor.pack(padx=10, pady=(0,8))

send_btn = ttk.Button(root, text="Send", command=on_send)
send_btn.pack(pady=6)

# Try to populate models on startup
models = fetch_models()
if models:
    model_combo["values"] = models
    model_var.set(models[0])

root.mainloop()
