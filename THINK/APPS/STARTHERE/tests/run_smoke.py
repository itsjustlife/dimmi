import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

subprocess.check_call([sys.executable, str(ROOT / 'refresh.py')])

out = ROOT / 'out'
assert (out / 'structure.opml').stat().st_size > 0
assert (out / 'graph.json').stat().st_size > 0
print('smoke test passed')
