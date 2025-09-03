"""Placeholder text editor module."""
from typing import Protocol


class MenuProtocol(Protocol):
    def add_option(self, label: str, callback): ...


def register(menu: MenuProtocol) -> None:
    """Register text editor with the shared menu."""

    def run() -> None:
        print("Launching text editor placeholder...")

    menu.add_option("Text Editor", run)
