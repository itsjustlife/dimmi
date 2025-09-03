#!/usr/bin/env bash
# Launch the WebEditor Flask app using gunicorn.
# Binds to all interfaces on port 8000 so it can be accessed remotely.
set -e
cd "$(dirname "$0")"
exec gunicorn --bind 0.0.0.0:8000 server:app
