import tkinter as tk
from tkinter import messagebox, scrolledtext
import requests

BASE = "http://127.0.0.1:4891/v1"  # force IPv4 + HTTP
API_CHAT = f"{BASE}/chat/completions"
API_MODELS = f"{BASE}/models"

COMMON_HEADERS = {
    "Content-Type": "application/json",
    "Connection": "close"  # avoid keep-alive on flaky local servers
}

DEFAULT_PAYLOAD = {
    "messages": [],
    "max_tokens": 256,
    "temperature": 0.2
}

def list_models():
    try:
        r = requests.get(API_MODELS, headers=COMMON_HEADERS, timeout=15)
        r.raise_for_status()
        data = r.json()
        # GPT4All returns {"data": [{"id": "model-name", ...}, ...]}
        return [m.get("id") for m in data.get("data", []) if m.get("id")]
    except Exception as e:
        return []

def chat_with_localdocs(model_name, prompt):
    payload = dict(DEFAULT_PAYLOAD)
    payload["model"] = model_name
    payload["messages"] = [
        {"role": "system", "content": "Answer concisely. If you use LocalDocs, include citations."},
        {"role": "user", "content": prompt}
    ]
    try:
        resp = requests.post(API_CHAT, json=payload, headers=COMMON_HEADERS, timeout=90)
        resp.raise_for_status()
        data = resp.json()

        # Defensive parsing
        choice0 = (data.get("choices") or [{}])[0]
        msg = (choice0.get("message") or {}).get("content", "").strip()
        refs = choice0.get("references") or []

        if not msg:
            msg = "[No content returned. Check server console/logs.]"
        return msg, refs

    # Show HTTP body for easier debugging
    except requests.exceptions.HTTPError as e:
        try:
            body = resp.text
        except Exception:
            body = ""
        return f"HTTP Error {resp.status_code}: {e}\n{body}", []
    except requests.exceptions.ConnectionError as e:
        return ("Connection Error: The local API server closed the connection or is unreachable.\n"
                "Try: open GPT4All → Settings → Enable Local API Server; verify /v1/models in your browser."), []
    except requests.exceptions.Timeout:
        return "Timeout: Try smaller max_tokens or simpler prompt; confirm model finished loading.", []
    except Exception as e:
        return f"Error: {e}", []

def send_prompt():
    model_name = model_entry.get().strip()
    prompt = prompt_editor.get("1.0", tk.END).strip()
    if not model_name or not prompt:
        messagebox.showwarning("Missing Info", "Please fill in all fields.")
        return

    chat_log.config(state='normal')
    chat_log.insert(tk.END, f"\nYou:\n{prompt}\n", "user")
    chat_log.config(state='disabled')
    root.update()

    reply, refs = chat_with_localdocs(model_name, prompt)

    chat_log.config(state='normal')
    chat_log.insert(tk.END, f"\nAI:\n{reply}\n", "ai")

    if refs:
        chat_log.insert(tk.END, "\nSources:\n", "refs")
        for i, ref in enumerate(refs, 1):
            title = ref.get("title") or "Untitled"
            page = ref.get("page")
            snippet = (ref.get("text") or "")[:200].replace("\n", " ")
            pg = f" — page {page}" if page is not None else ""
            chat_log.insert(tk.END, f"[{i}] {title}{pg} — {snippet}...\n", "refs")

    chat_log.config(state='disabled')
    chat_log.see(tk.END)
    prompt_editor.delete("1.0", tk.END)

def probe_server():
    # quick health: GET /v1/models and report status
    try:
        r = requests.get(API_MODELS, headers=COMMON_HEADERS, timeout=10)
        r.raise_for_status()
        models = [m.get("id") for m in r.json().get("data", []) if m.get("id")]
        if models:
            messagebox.showinfo("Server OK", f"API is up. {len(models)} model(s) available.\n\nFirst few:\n- " + "\n- ".join(models[:5]))
            # populate dropdown if empty
            if not model_entry.get().strip():
                model_entry.insert(0, models[0])
        else:
            messagebox.showwarning("Server OK", "API is up but returned no models. Load a model in GPT4All.")
    except Exception as e:
        messagebox.showerror("Server Unreachable", f"Could not reach {API_MODELS}\n\n{e}")

def on_closing():
    if messagebox.askokcancel("Quit", "Do you want to quit?"):
        root.destroy()

# ----- UI -----
root = tk.Tk()
root.title("GPT4All Chat (LocalDocs-ready)")

tk.Label(root, text="Model Name (exact id from /v1/models):").pack()
model_entry = tk.Entry(root, width=60)
model_entry.pack()

# Try to auto-fill a model id
models = list_models()
if models:
    model_entry.insert(0, models[0])

btn_row = tk.Frame(root)
btn_row.pack(pady=4)
tk.Button(btn_row, text="Health Check", command=probe_server).pack(side=tk.LEFT, padx=5)

chat_log = scrolledtext.ScrolledText(root, height=20, width=90, state='disabled', font=("Consolas", 11))
chat_log.pack(padx=10, pady=10)

prompt_editor = tk.Text(root, height=6, width=90, font=("Consolas", 11))
prompt_editor.pack(padx=10, pady=(0,10))

send_btn = tk.Button(root, text="Send Prompt", command=send_prompt)
send_btn.pack(pady=5)

root.protocol("WM_DELETE_WINDOW", on_closing)
root.mainloop()
