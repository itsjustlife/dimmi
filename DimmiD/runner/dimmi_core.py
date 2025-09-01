from pathlib import Path
from datetime import datetime
import json
from gpt4all import GPT4All

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

def _load_config():
    cfg = DIMMID_ROOT / "config.json"
    if cfg.exists():
        return json.loads(cfg.read_text(encoding="utf-8"))
    return {"model_path": "", "max_tokens": 1200, "temp": 0.2, "top_p": 0.9}

class DimmiRunner:
    def __init__(self, model_path: str | None = None):
        cfg = _load_config()
        self.model_path = model_path or cfg.get("model_path", "")
        if not self.model_path:
            raise FileNotFoundError("Model path is not set. Update /DimmiD/config.json.")
        self.max_tokens = int(cfg.get("max_tokens", 1200))
        self.temp = float(cfg.get("temp", 0.2))
        self.top_p = float(cfg.get("top_p", 0.9))
        self.model = GPT4All(self.model_path, allow_download=False)
        self.system_prompt = compose_system_prompt()

    def generate(self, user_text: str) -> str:
        prompt = f"{self.system_prompt}\n\nUser: {user_text}\nAI:"
        return self.model.generate(
            prompt,
            max_tokens=self.max_tokens,
            temp=self.temp,
            top_p=self.top_p
        )

    def run_task(self, task_text: str, proprompt_name: str | None = None) -> str:
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
            top_p=self.top_p
        )

    def process_queue(self, max_items: int = 1):
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
        day = datetime.utcnow().strftime("%Y-%m-%d")
        daydir = OUT / day
        daydir.mkdir(exist_ok=True)

        results = []
        for task in to_run:
            out = self.run_task(task)
            ts = datetime.utcnow().strftime("%Y-%m-%dT%H-%M-%SZ")
            obj = {"ts": ts, "task": task, "output": out}
            of = daydir / f"{ts}.json"
            of.write_text(json.dumps(obj, ensure_ascii=False, indent=2), encoding="utf-8")
            (MEM / "requests.resolved.log").open("a", encoding="utf-8").write(f"{ts}\t{task}\t{of.name}\n")
            (MEM / "facts.log").open("a", encoding="utf-8").write(f"{ts}\tOUTPUT\t{of}\n")
            results.append(obj)

        reqf.write_text(("\n".join(remaining) + "\n") if remaining else "", encoding="utf-8")
        return results
