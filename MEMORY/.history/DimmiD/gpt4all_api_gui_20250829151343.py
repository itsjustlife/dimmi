import tkinter as tk
from tkinter import messagebox, scrolledtext
import requests

API_URL = "http://localhost:4891/v1/chat/completions"

def chat_with_localdocs(model_name, collection, prompt):
    payload = {
        "model": model_name,
        "messages": [
            {"role": "user", "content": prompt}
        ],
        "local_docs": {
            "collections": [collection]
        }
    }
    try:
        response = requests.post(API_URL, json=payload)
        response.raise_for_status()
        data = response.json()
        return data["choices"][0]["message"]["content"]
    except requests.exceptions.HTTPError as e:
        return f"HTTP Error: {e}\n{response.text}"
    except Exception as e:
        return f"Error: {e}"

def send_prompt():
    model_name = model_entry.get().strip()
    collection = collection_entry.get().strip()
    prompt = prompt_editor.get("1.0", tk.END).strip()
    if not model_name or not collection or not prompt:
        messagebox.showwarning("Missing Info", "Please fill in all fields.")
        return
    chat_log.config(state='normal')
    chat_log.insert(tk.END, f"\nYou:\n{prompt}\n", "user")
    chat_log.config(state='disabled')
    root.update()
    reply = chat_with_localdocs(model_name, collection, prompt)
    chat_log.config(state='normal')
    chat_log.insert(tk.END, f"\nAI:\n{reply}\n", "ai")
    chat_log.config(state='disabled')
    chat_log.see(tk.END)
    prompt_editor.delete("1.0", tk.END)

root = tk.Tk()
root.title("GPT4All API Chat with LocalDocs")

tk.Label(root, text="Model Name:").pack()
model_entry = tk.Entry(root, width=60)
model_entry.pack()
model_entry.insert(0, "mistral-7b-instruct-v0.1.Q4_0.gguf")  # Default

tk.Label(root, text="Collection Name:").pack()
collection_entry = tk.Entry(root, width=60)
collection_entry.pack()
collection_entry.insert(0, "DimmiD")  # Default

chat_log = scrolledtext.ScrolledText(root, height=20, width=80, state='disabled', font=("Consolas", 11))
chat_log.pack(padx=10, pady=10)

prompt_editor = tk.Text(root, height=6, width=80, font=("Consolas", 11))
prompt_editor.pack(padx=10, pady=(0,10))

send_btn = tk.Button(root, text="Send Prompt", command=send_prompt)
send_btn.pack(pady=5)

root.mainloop()