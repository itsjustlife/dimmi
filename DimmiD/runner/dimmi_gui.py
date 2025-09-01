import tkinter as tk
from tkinter import filedialog, scrolledtext, messagebox
from pathlib import Path
import json
from .dimmi_core import DimmiRunner

CFG = Path(__file__).resolve().parent.parent / "config.json"
MEM = Path(__file__).resolve().parent.parent / "Memory"

def load_cfg():
    if CFG.exists():
        return json.loads(CFG.read_text(encoding="utf-8"))
    return {"model_path": ""}

class DimmiGUI:
    def __init__(self, root):
        self.root = root
        self.root.title("DimmiD (offline)")
        self.cfg = load_cfg()
        try:
            self.runner = DimmiRunner(self.cfg.get("model_path"))
        except Exception as e:
            messagebox.showerror("Model error", str(e)); raise

        self.chat_log = scrolledtext.ScrolledText(root, height=22, width=90, state='disabled', font=("Consolas", 11))
        self.chat_log.pack(padx=10, pady=10)

        self.prompt_editor = tk.Text(root, height=6, width=90, font=("Consolas", 11))
        self.prompt_editor.pack(padx=10, pady=(0,10))

        btns = tk.Frame(root); btns.pack(pady=5)
        tk.Button(btns, text="Send Turn", command=self.send_turn).pack(side=tk.LEFT, padx=5)
        tk.Button(btns, text="Queue Task", command=self.queue_task).pack(side=tk.LEFT, padx=5)
        tk.Button(btns, text="Load PREPROMPT", command=self.load_preprompt).pack(side=tk.LEFT, padx=5)
        tk.Button(btns, text="Clear", command=self.clear_chat).pack(side=tk.LEFT, padx=5)

    def append_chat(self, role, text):
        self.chat_log.config(state='normal')
        self.chat_log.insert(tk.END, f"\n{role}:\n{text}\n")
        self.chat_log.config(state='disabled'); self.chat_log.see(tk.END)

    def send_turn(self):
        prompt = self.prompt_editor.get("1.0", tk.END).strip()
        if not prompt: return
        self.append_chat("You", prompt)
        self.prompt_editor.delete("1.0", tk.END)
        try:
            reply = self.runner.generate(prompt)
        except Exception as e:
            reply = f"Error: {e}"
        self.append_chat("AI", reply)

    def queue_task(self):
        task = self.prompt_editor.get("1.0", tk.END).strip()
        if not task: return
        MEM.mkdir(parents=True, exist_ok=True)
        (MEM/"requests.log").open("a", encoding="utf-8").write(task + "\n")
        self.append_chat("Queued", task)
        self.prompt_editor.delete("1.0", tk.END)

    def load_preprompt(self):
        base = Path(__file__).resolve().parent.parent / "Templates" / "PREPROMPTS"
        path = filedialog.askopenfilename(initialdir=base, title="Select PREPROMPT", filetypes=[("Text", "*.txt")])
        if path:
            try:
                text = Path(path).read_text(encoding="utf-8")
                self.prompt_editor.delete("1.0", tk.END)
                self.prompt_editor.insert(tk.END, text)
            except Exception as e:
                messagebox.showerror("Error", f"Could not load file:\n{e}")

    def clear_chat(self):
        self.chat_log.config(state='normal'); self.chat_log.delete("1.0", tk.END); self.chat_log.config(state='disabled')

if __name__ == "__main__":
    root = tk.Tk(); app = DimmiGUI(root); root.mainloop()
