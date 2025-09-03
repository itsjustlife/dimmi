# ProPrompt CLI (spec)

A simple command interface for standalone ProPrompt cards.

## Planned Commands
- `proprompt show <card>` – display parsed fields.
- `proprompt queue <card>` – move card into `queue/`.
- `proprompt done <id>` – mark card done and move to `done/`.

The CLI will plug into `dimmi_cli.py` so tools share the same menu layout.
