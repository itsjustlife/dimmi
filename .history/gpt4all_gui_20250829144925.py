import tkinter as tk
from gpt4all import GPT4All

model_path = r"C:\Users\itsju\AppData\Local\nomic.ai\GPT4All\mistral-7b-instruct-v0.1.Q4_0.gguf"
model = GPT4All(model_path, allow_download=False)

def send():
    user_input = entry.get()
    chat_log.insert(tk.END, f"You: {user_input}\n")
    entry.delete(0, tk.END)
    response = model.generate(user_input, max_tokens=100)
    chat_log.insert(tk.END, f"AI: {response}\n")

root = tk.Tk()
root.title("GPT4All Chat")

chat_log = tk.Text(root, height=20, width=60)
chat_log.pack()

entry = tk.Entry(root, width=50)
entry.pack(side=tk.LEFT, padx=5, pady=5)

send_button = tk.Button(root, text="Send", command=send)
send_button.pack(side=tk.LEFT, padx=5, pady=5)

root.mainloop()