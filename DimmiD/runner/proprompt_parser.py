"""Parser for standalone ProPrompt cards using Dimmi-Code."""

from dataclasses import dataclass, field
from typing import Dict

@dataclass
class ProPrompt:
    """In-memory representation of a ProPrompt card."""
    id: str
    title: str
    role: str
    status: str
    body: str
    extras: Dict[str, str] = field(default_factory=dict)

def parse_card(text: str) -> ProPrompt:
    """Parse a card string into a ProPrompt object.

    This stub uses a naive header parser and keeps unknown keys in `extras`.
    """
    header, _, body = text.partition('---')
    fields: Dict[str, str] = {}
    for line in header.strip().splitlines():
        if ':' in line:
            key, value = line.split(':', 1)
            fields[key.strip()] = value.strip()
    return ProPrompt(
        id=fields.get('id', ''),
        title=fields.get('title', ''),
        role=fields.get('role', ''),
        status=fields.get('status', ''),
        body=body.strip(),
        extras={k: v for k, v in fields.items() if k not in {'id', 'title', 'role', 'status'}},
    )
