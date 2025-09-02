"""Shared menu system used by modular apps."""
from typing import Callable, List, Tuple


class Menu:
    """Simple text-based menu."""

    def __init__(self) -> None:
        self.options: List[Tuple[str, Callable[[], None]]] = []

    def add_option(self, label: str, callback: Callable[[], None]) -> None:
        """Register a new option with the menu."""
        self.options.append((label, callback))

    def show(self) -> None:
        """Display the menu and execute the chosen option."""
        print("Dimmi Modular Menu")
        for index, (label, _) in enumerate(self.options, 1):
            print(f"{index}. {label}")
        choice = input("Select an option: ")
        try:
            _, callback = self.options[int(choice) - 1]
            callback()
        except (ValueError, IndexError):
            print("Invalid selection.")
