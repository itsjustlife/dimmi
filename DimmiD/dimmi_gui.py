import tkinter as tk
from tkinter import filedialog, scrolledtext, messagebox
from gpt4all import GPT4All
from pathlib import Path

MODEL_PATH = r"C:\Users\itsju\AppData\Local\nomic.ai\GPT4All\mistral-7b-instruct-v0.1.Q4_0.gguf"

class DimmiGUI:
    def __init__(self, root):
        self.root = root
        self.root.title("DimmiD - GPT4All Assistant")

        # Conversation history
        self.chat_log = scrolledtext.ScrolledText(root, height=20, width=80, state='disabled', font=("Consolas", 11))
        self.chat_log.pack(padx=10, pady=10)

        # Prompt editor
        self.prompt_editor = tk.Text(root, height=6, width=80, font=("Consolas", 11))
        self.prompt_editor.pack(padx=10, pady=(0,10))

        # Buttons
        button_frame = tk.Frame(root)
        button_frame.pack(pady=5)

        self.send_btn = tk.Button(button_frame, text="Send Prompt", command=self.send_prompt)
        self.send_btn.pack(side=tk.LEFT, padx=5)

        self.clear_btn = tk.Button(button_frame, text="Clear Conversation", command=self.clear_chat)
        self.clear_btn.pack(side=tk.LEFT, padx=5)

        self.load_btn = tk.Button(button_frame, text="Load Prompt File", command=self.load_prompt_file)
        self.load_btn.pack(side=tk.LEFT, padx=5)

        # Load model
        self.model = GPT4All(MODEL_PATH, allow_download=False)

    def send_prompt(self):
        prompt = self.prompt_editor.get("1.0", tk.END).strip()
        if not prompt:
            messagebox.showwarning("Empty Prompt", "Please enter a prompt.")
            return
        self.append_chat("Prompt", prompt)
        self.prompt_editor.delete("1.0", tk.END)
        self.root.update()
        try:
            reply = self.model.generate(prompt, max_tokens=256)
        except Exception as e:
            reply = f"Error: {e}"
        self.append_chat("Reply", reply)

    def append_chat(self, role, text):
        self.chat_log.config(state='normal')
        if role == "Prompt":
            self.chat_log.insert(tk.END, f"\nYou:\n{text}\n", "user")
        else:
            self.chat_log.insert(tk.END, f"\nAI:\n{text}\n", "ai")
        self.chat_log.config(state='disabled')
        self.chat_log.see(tk.END)

    def clear_chat(self):
        self.chat_log.config(state='normal')
        self.chat_log.delete("1.0", tk.END)
        self.chat_log.config(state='disabled')

    def load_prompt_file(self):
        file_path = filedialog.askopenfilename(title="Select Prompt File", filetypes=[("Text Files", "*.txt")])
        if file_path:
            try:
                content = Path(file_path).read_text(encoding="utf-8")
                self.prompt_editor.delete("1.0", tk.END)
                self.prompt_editor.insert(tk.END, content)
            except Exception as e:
                messagebox.showerror("Error", f"Could not load file:\n{e}")

if __name__ == "__main__":
    root = tk.Tk()
    app = DimmiGUI(root)
    root.mainloop()