# DimmiD Offline Core

This folder contains a reduced, text-only reconstruction of **Dimmi** for offline use with GPT4All or similar models.
The goal is to capture essential behavior, personality, and memory handling without relying on network calls or external creative engines.
THIS VERSION OF DIMMI IS SPECIFICALLY CREATED TO WORK VIA AN OFFLINE LLM MODEL AND WHEN IT"S CONTENTS ARE UPDATED THE MODEL WILL BE RETRAINED ON IT. 
## Structure
- `Start.txt` – entrypoint instructions for every chat session.
- `Dimmi-Core.txt` – architecture overview and routing rules.
- `Mind.txt` – lightweight orchestrator for abilities and safety.
- `Commands.txt` – compact command set understood by the offline assistant.
- `Personality.txt` – tone and style guidelines (“dimmi-code”).
- `dimmi-talk.txt` – companion language guide for casual voice.
- `Abilities/` – textual descriptions of capabilities; extendable.
- `ProPrompts/` – spec and snippets for reusable task blocks.
- `Templates/` – preprompt templates for bootstrapping sessions.
- `Memory/` – instructions and log template for recording user requests and missing abilities.
- `dimmi.py` – minimal Python runner that loads these files with a GPT4All model.

Each file uses plain language so a local model can read, analyze, and act without network calls.
Whenever the model lacks an ability, it should describe what is needed and append the request to `Memory/requests.log`.

Key abilities now include **DIMMI-TALK** for voice, **CHOOSE-PATH** for numbered options, **CLARIFY**, **REFLECT**, **DIMMI-CODE**, **PROTOPROMPT**, and **PREPROMPT**.

The boot path: `Start.txt` loads dimmi-code and dimmi-talk, reads memory, branches through commands, proprompts, templates, or `Dimmi-Core.txt`, then closes with a footer pro-prompt inviting the next step.

Templates such as `Templates/PrePrompt-L1-Bootstrap.txt` and `Templates/ChoosePath-Template.txt` demonstrate preprompting and choose-your-own-adventure flows.

To retrain GPT4All, feed the contents of `DimmiD/` in order: start, mind, abilities, templates, proprompts, and memory examples. The package is self-contained and mirrors the root project’s Start sequence in simplified offline form.
