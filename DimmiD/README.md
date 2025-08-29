# DimmiD Offline Core

This folder contains a reduced, text-only reconstruction of **Dimmi** for offline use with GPT4All or similar models.  
The goal is to capture essential behavior, personality, and memory handling without relying on network calls or external creative engines.  

## Structure
- `Start.txt` – entrypoint instructions for every chat session.
- `Dimmi-Core.txt` – architecture overview and routing rules.
- `Commands.txt` – compact command set understood by the offline assistant.
- `Personality.txt` – tone and style guidelines (“dimmi-code”).
- `Abilities/` – textual descriptions of capabilities; extendable.
- `Memory/` – instructions and log template for recording user requests and missing abilities.
- `dimmi.py` – minimal Python runner that loads these files with a GPT4All model.

Each file uses plain language so a local model can read, reason about, and follow the steps.
Whenever the model lacks an ability, it should describe what is needed and append the request to `Memory/requests.log`.

The `REFLECT` ability lets DimmiD explain its own reasoning and suggest which files might need updates when limitations appear. The `CLARIFY` ability prompts follow-up questions whenever user input lacks detail, helping the offline model stay on track.
