"""Minimal offline Dimmi runner using GPT4All.

Loads core instruction files from this directory and feeds them to a
GPT4All model. Intended as a starting point for a desktop or Android
assistant.
"""

from pathlib import Path
import argparse

try:
    from gpt4all import GPT4All
except ImportError as exc:  # pragma: no cover
    GPT4All = None
    IMPORT_ERROR = exc
else:
    IMPORT_ERROR = None

CORE_FILES = [
    "Start.txt",
    "Dimmi-Core.txt",
    "Commands.txt",
    "Personality.txt",
]


def load_prompt() -> str:
    """Combine core files into a single prompt string."""
    base = Path(__file__).parent
    parts = []
    for name in CORE_FILES:
        parts.append((base / name).read_text(encoding="utf-8"))
    return "\n\n".join(parts)


def chat(model_path: str) -> None:
    """Stream tokens from the model for the initial prompt."""
    if GPT4All is None:
        raise IMPORT_ERROR
    model = GPT4All(model_path, allow_download=False)
    prompt = load_prompt()
    for token in model.generate(prompt, streaming=True):
        print(token, end="")
    print()


def main() -> None:
    parser = argparse.ArgumentParser(description="Run DimmiD with a GPT4All model")
    parser.add_argument("--model", help="Path to GPT4All model file")
    parser.add_argument("--show-prompt", action="store_true", help="Print combined prompt and exit")
    args = parser.parse_args()

    if args.show_prompt:
        print(load_prompt())
        return

    if not args.model:
        parser.error("--model is required unless --show-prompt is used")

    chat(args.model)


if __name__ == "__main__":  # pragma: no cover
    main()

python dimmi.py --model path\to\model.bin
