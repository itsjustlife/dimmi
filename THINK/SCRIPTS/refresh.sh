#!/bin/bash
python3 scripts/txt_to_md.py && python3 scripts/linker.py && python3 scripts/link_check.py
