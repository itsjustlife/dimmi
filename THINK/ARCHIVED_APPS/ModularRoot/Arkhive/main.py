"""Prototype entry point combining modular apps with a shared menu."""
from shared.menu import Menu
from modules import text_editor, image_viewer

MODULES = [text_editor, image_viewer]


def build_menu() -> Menu:
    menu = Menu()
    for module in MODULES:
        module.register(menu)
    return menu


def main() -> None:
    menu = build_menu()
    menu.show()


if __name__ == "__main__":
    main()
