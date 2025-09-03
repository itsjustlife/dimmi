from pathlib import Path
from gpt4all import GPT4All

CORE_FILES = [
    "Start.txt",
    "Dimmi-Core.txt",
    "Commands.txt",
    "Personality.txt",
]

class DimmiRunner:
    def __init__(self, model_path):
        self.model = GPT4All(model_path, allow_download=False)
        self.prompt = self.load_prompt()

    def load_prompt(self):
        base = Path(__file__).parent
        parts = []
        for name in CORE_FILES:
            file_path = base / name
            if not file_path.exists():
                continue
            parts.append(file_path.read_text(encoding="utf-8"))
        return "\n\n".join(parts)

    def run(self, user_input, max_tokens=256):
        full_prompt = f"{self.prompt}\n\nUser: {user_input}\nAI:"
        return self.model.generate(full_prompt, max_tokens=max_tokens)