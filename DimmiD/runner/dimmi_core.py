from pathlib import Path
from datetime import datetime
import json
import os
import sys
try:  # gpt4all is optional for reading prompts
    from gpt4all import GPT4All  # type: ignore
except Exception:  # pragma: no cover - handled at runtime
    GPT4All = None
try:  # optional HTTP client for detecting models from a local API server
    import requests  # type: ignore
except Exception:  # pragma: no cover - requests is optional
    requests = None

"""Core runner logic for the DimmiD offline assistant.

This module is responsible for composing the system prompt from the
various text files in the project tree, executing ad-hoc turns, queueing
tasks, and processing queued tasks.  It is intentionally dependency light
so it can be reused by both the CLI and the tkinter GUI.
"""

DIMMID_ROOT = Path(__file__).resolve().parent.parent
MEM = DIMMID_ROOT / "Memory"
OUT = DIMMID_ROOT / "Outputs"

CORE_FILES = [
    "Start.txt",
    "Dimmi-Core.txt",
    "Mind.txt",
    "Commands.txt",
    "Personality.txt",
]

def _read(p: Path) -> str:
    return p.read_text(encoding="utf-8")

def compose_system_prompt() -> str:
    parts = []
    # Core
    for name in CORE_FILES:
        p = DIMMID_ROOT / name
        if p.exists():
            parts.append(f"\n/// {name}\n{_read(p)}")
    # Abilities
    abl = DIMMID_ROOT / "Abilities"
    if abl.exists():
        for ap in sorted(abl.glob("*.txt")):
            parts.append(f"\n/// Abilities/{ap.name}\n{_read(ap)}")
    # PREPROMPTS (optional)
    for pre in [
        DIMMID_ROOT / "Templates" / "PREPROMPTS" / "Layer-1.txt",
        DIMMID_ROOT / "Templates" / "PREPROMPTS" / "Layer-2.txt",
    ]:
        if pre.exists():
            rel = pre.relative_to(DIMMID_ROOT)
            parts.append(f"\n/// {rel}\n{_read(pre)}")
    return "<<DIMMID_SYSTEM_START>>\n" + "\n".join(parts) + "\n<<DIMMID_SYSTEM_END>>"

def queue_task(task_text: str) -> None:
    """Append a task to ``Memory/requests.log`` (module-level helper)."""
    MEM.mkdir(parents=True, exist_ok=True)
    (MEM / "requests.log").open("a", encoding="utf-8").write(task_text.strip() + "\n")

def _load_config():
    cfg = DIMMID_ROOT / "config.json"
    if cfg.exists():
        return json.loads(cfg.read_text(encoding="utf-8"))
    return {"model_path": "", "max_tokens": 1200, "temp": 0.2, "top_p": 0.9}


def _candidate_model_dirs():
    dirs: list[Path] = []
    home = Path.home()
    dirs.append(home / ".cache" / "gpt4all")
    if sys.platform == "win32":
        local = Path(os.environ.get("LOCALAPPDATA", ""))
        dirs.append(local / "nomic.ai" / "GPT4All")
    elif sys.platform == "darwin":
        dirs.append(home / "Library" / "Application Support" / "nomic.ai" / "GPT4All")
    else:
        dirs.append(home / ".local" / "share" / "nomic.ai" / "GPT4All")
    return [d for d in dirs if d and d.exists()]


def _model_name_from_server() -> str | None:
    if not requests:
        return None
    try:
        r = requests.get("http://localhost:4891/v1/models", timeout=1)
        r.raise_for_status()
        data = r.json().get("data", [])
        if data:
            return data[0].get("id")
    except Exception:
        return None
    return None


def resolve_model_path(preferred: str | None = None) -> str:
    """Determine a usable GPT4All model path.

    Order of preference:
    1. Explicit ``preferred`` path if it exists.
    2. Model name reported by a local GPT4All server on port 4891.
    3. First ``*.gguf`` file found in known GPT4All directories.
    """

    if preferred:
        p = Path(preferred).expanduser()
        if p.exists():
            return str(p)

    name = _model_name_from_server()
    dirs = _candidate_model_dirs()
    if name:
        for d in dirs:
            p = d / name
            if p.exists():
                return str(p)

    for d in dirs:
        gguf = next(d.glob("*.gguf"), None)
        if gguf:
            return str(gguf)

    raise FileNotFoundError(
        "No local GPT4All model file found. Update config.json or run GPT4All server."
    )

class DimmiRunner:
    """Wrapper around the GPT4All model used by the project."""

    def __init__(self, model_path: str | None = None):
        cfg = _load_config()
        if GPT4All is None:
            raise ImportError("The 'gpt4all' package is required to run DimmiD.")
        self.model_path = resolve_model_path(model_path or cfg.get("model_path"))
        self.max_tokens = int(cfg.get("max_tokens", 1200))
        self.temp = float(cfg.get("temp", 0.2))
        self.top_p = float(cfg.get("top_p", 0.9))
        self.model = GPT4All(self.model_path, allow_download=False)
        self.system_prompt = compose_system_prompt()

    # ------------------------------------------------------------------
    # Basic interaction helpers
    # ------------------------------------------------------------------
    def generate(self, user_text: str) -> str:
        """Run a single-turn conversation with the current system prompt."""
        prompt = f"{self.system_prompt}\n\nUser: {user_text}\nAI:"
        return self.model.generate(
            prompt,
            max_tokens=self.max_tokens,
            temp=self.temp,
            top_p=self.top_p,
        )

    def run_task(self, task_text: str, proprompt_name: str | None = None) -> str:
        """Execute a TASK optionally seeded with a ProPrompt template."""
        proprompt = ""
        if proprompt_name:
            ppf = DIMMID_ROOT / "ProPrompts" / f"{proprompt_name}.txt"
            if ppf.exists():
                proprompt = ppf.read_text(encoding="utf-8")
        task_prompt = f"<<TASK>> {task_text}\n{proprompt}\nAI:"
        prompt = f"{self.system_prompt}\n\n{task_prompt}"
        return self.model.generate(
            prompt,
            max_tokens=self.max_tokens,
            temp=self.temp,
            top_p=self.top_p,
        )

    # ------------------------------------------------------------------
    # Queue management
    # ------------------------------------------------------------------
    def queue(self, task_text: str) -> None:
        """Append a task to ``Memory/requests.log`` for deferred execution."""
        queue_task(task_text)

    def process_queue(self, max_items: int = 1):
        """Process up to ``max_items`` queued tasks.

        Each completed task is written to ``Outputs/<DATE>/<TIMESTAMP>.json``
        and logged to both ``requests.resolved.log`` and ``facts.log``.
        Returns a list of result objects.
        """

        reqf = MEM / "requests.log"
        reqf.parent.mkdir(parents=True, exist_ok=True)
        if not reqf.exists():
            reqf.write_text("", encoding="utf-8")
            return []
        lines = [ln for ln in reqf.read_text(encoding="utf-8").splitlines() if ln.strip()]
        if not lines:
            return []
        to_run = lines[:max_items]
        remaining = lines[max_items:]

        OUT.mkdir(parents=True, exist_ok=True)
        daydir = OUT / datetime.utcnow().strftime("%Y-%m-%d")
        daydir.mkdir(exist_ok=True)

        results = []
        for task in to_run:
            out = self.run_task(task)
            ts = datetime.utcnow().strftime("%Y-%m-%dT%H-%M-%SZ")
            obj = {"ts": ts, "task": task, "output": out}
            of = daydir / f"{ts}.json"
            of.write_text(
                json.dumps(obj, ensure_ascii=False, indent=2), encoding="utf-8"
            )
            (MEM / "requests.resolved.log").open("a", encoding="utf-8").write(
                f"{ts}\t{task}\t{of.name}\n"
            )
            (MEM / "facts.log").open("a", encoding="utf-8").write(
                f"{ts}\tOUTPUT\t{of}\n"
            )
            results.append(obj)

        reqf.write_text(("\n".join(remaining) + "\n") if remaining else "", encoding="utf-8")
        return results
