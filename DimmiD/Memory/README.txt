Append-only logs used by DimmiD:
- requests.log: pending tasks (one per line)
- requests.resolved.log: timestamped completed tasks
- facts.log: lightweight audit (e.g., where outputs were written)
These files may be empty. The runner creates them if missing.
