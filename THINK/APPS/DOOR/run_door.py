import tkinter as tk
from STARTHERE.door_app import main

class YourAppName(tk.Tk):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.title("Your App Title")
        self.geometry("800x600")
        self.create_widgets()

    def create_widgets(self):
        # ...existing code...

        # Add Ask AI field directly in the main window
        ai_frame = tk.Frame(self)
        ai_frame.pack(side=tk.TOP, fill=tk.X, padx=8, pady=4)
        tk.Label(ai_frame, text="Ask AI:").pack(side=tk.LEFT)
        self.ai_entry = tk.Entry(ai_frame, width=60)
        self.ai_entry.pack(side=tk.LEFT, padx=4)
        self.ai_btn = tk.Button(ai_frame, text="Ask", command=self.ask_ai)
        self.ai_btn.pack(side=tk.LEFT, padx=4)
        tk.Label(ai_frame, text="AI Reply:").pack(side=tk.LEFT, padx=8)
        # Scrollable AI Reply field
        ai_output_frame = tk.Frame(ai_frame)
        ai_output_frame.pack(side=tk.LEFT, padx=4)
        self.ai_output = tk.Text(ai_output_frame, height=4, width=60, state="disabled", wrap="word")
        self.ai_output.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        ai_scroll = tk.Scrollbar(ai_output_frame, command=self.ai_output.yview)
        ai_scroll.pack(side=tk.RIGHT, fill=tk.Y)
        self.ai_output.config(yscrollcommand=ai_scroll.set)
        # Copy button
        self.copy_btn = tk.Button(ai_frame, text="Copy", command=self.copy_ai_reply)
        self.copy_btn.pack(side=tk.LEFT, padx=4)

    def ask_ai(self):
        # Your existing ask_ai code
        pass

    def copy_ai_reply(self):
        # Your existing copy_ai_reply code
        pass

if __name__ == "__main__":
    app = YourAppName()
    app.mainloop()
