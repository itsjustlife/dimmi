import argparse
import sys
from pathlib import Path

# Allow running as a script
sys.path.append(str(Path(__file__).resolve().parent))
from dimmi_core import DimmiRunner, compose_system_prompt, queue_task

def main():
    p = argparse.ArgumentParser(description="DimmiD CLI (offline, GPT4All)")
    p.add_argument("--model", help="Path to .gguf model (overrides config.json)")
    p.add_argument("--show-prompt", action="store_true", help="Print composed system prompt")
    p.add_argument("--turn", help="Ad-hoc user turn text")
    p.add_argument("--task", help="Run a one-off TASK (optionally a ProPrompt name)")
    p.add_argument("--proprompt", help="Optional ProPrompt file name (without .txt)")
    p.add_argument("--queue", help="Append TASK text to Memory/requests.log and exit")
    p.add_argument("--batch", type=int, default=0, help="Process N queued tasks from Memory/requests.log")
    args = p.parse_args()

    if args.show_prompt:
        print(compose_system_prompt())
        return

    if args.queue:
        queue_task(args.queue)
        return

    runner = DimmiRunner(model_path=args.model)  # may be None -> uses config.json

    if args.batch and args.batch > 0:
        results = runner.process_queue(max_items=args.batch)
        for r in results:
            print(r["ts"], r["task"])
        return

    if args.task:
        print(runner.run_task(args.task, args.proprompt))
        return

    if args.turn:
        print(runner.generate(args.turn))
        return

    p.print_help()

if __name__ == "__main__":
    main()
