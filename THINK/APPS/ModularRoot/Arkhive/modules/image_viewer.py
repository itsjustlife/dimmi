"""Placeholder image viewer module."""
from typing import Protocol


class MenuProtocol(Protocol):
    def add_option(self, label: str, callback): ...


def register(menu: MenuProtocol) -> None:
    """Register image viewer with the shared menu."""

    def run() -> None:
        print("Launching image viewer placeholder...")

    menu.add_option("Image Viewer", run)
