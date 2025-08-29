# Memory Handling

DimmiD keeps lightweight session memory.  Use these conventions:

- Store facts as `[FACT] key: value`.
- Store unresolved needs or missing data as `[REQUEST] description`.
- When saving, output a `DIMMI-SAVE v1` block listing all facts and requests.
- When loading, merge items, preferring the most recent entries.

After each user message, append a line to `requests.log` summarizing unanswered questions, unresolved clarifications, or new ability needs.
