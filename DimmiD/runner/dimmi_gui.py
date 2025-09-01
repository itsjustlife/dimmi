import tkinter as tk
from tkinter import filedialog, scrolledtext, messagebox
from pathlib import Path
import json
import sys

# Allow running as a script without installation
sys.path.append(str(Path(__file__).resolve().parent))
from dimmi_core import DimmiRunner

CFG = Path(__file__).resolve().parent.parent / "config.json"

def load_cfg():
    if CFG.exists():
        return json.loads(CFG.read_text(encoding="utf-8"))
    return {"model_path": ""}

def save_cfg(data):
    CFG.write_text(json.dumps(data, indent=2), encoding="utf-8")

class DimmiGUI:
    def __init__(self, root):
        self.root = root
        self.root.title("DimmiD (offline)")
        self.cfg = load_cfg()
        try:
            self.runner = DimmiRunner(self.cfg.get("model_path"))
        except FileNotFoundError:
            messagebox.showerror(
                "Model error", "No GPT4All model found. Please choose a .gguf file."
            )
            path = filedialog.askopenfilename(
                title="Select GPT4All model", filetypes=[("GPT4All model", "*.gguf")]
            )
            if not path:
                raise
            self.runner = DimmiRunner(path)
            self.cfg["model_path"] = path
            save_cfg(self.cfg)
        except Exception as e:
            messagebox.showerror("Model error", str(e)); raise

        self.chat_log = scrolledtext.ScrolledText(root, height=22, width=90, state='disabled', font=("Consolas", 11))
        self.chat_log.pack(padx=10, pady=10)

        self.prompt_editor = tk.Text(root, height=6, width=90, font=("Consolas", 11))
        self.prompt_editor.pack(padx=10, pady=(0,10))

        btns = tk.Frame(root); btns.pack(pady=5)
        tk.Button(btns, text="Run", command=self.run_prompt).pack(side=tk.LEFT, padx=5)
        tk.Button(btns, text="Queue Task", command=self.queue_task).pack(side=tk.LEFT, padx=5)
        tk.Button(btns, text="Load Prompt File", command=self.load_prompt_file).pack(side=tk.LEFT, padx=5)
        tk.Button(btns, text="Clear", command=self.clear_chat).pack(side=tk.LEFT, padx=5)

    def append_chat(self, role, text):
        self.chat_log.config(state='normal')
        self.chat_log.insert(tk.END, f"\n{role}:\n{text}\n")
        self.chat_log.config(state='disabled'); self.chat_log.see(tk.END)

    def run_prompt(self):
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
        self.runner.queue(task)
        self.append_chat("Queued", task)
        self.prompt_editor.delete("1.0", tk.END)

    def load_prompt_file(self):
        base = Path(__file__).resolve().parent.parent
        path = filedialog.askopenfilename(initialdir=base, title="Select Prompt File", filetypes=[("Text", "*.txt")])
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
