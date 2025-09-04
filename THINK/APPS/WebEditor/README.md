# WebEditor

A simple Flask-based file browser and editor. Authentication is handled via a small token system in `auth.py`.

## Quick start

1. Install the Python packages:
   ```bash
   pip install flask gunicorn
   ```
2. Run the server:
   ```bash
   ./run_server.sh
   ```
   This uses `gunicorn` to serve `server:app` on port 8000.

3. Log in with the default credentials:
   - **Username:** `Arkhivist`
   - **Password:** `Arkhiver`
   You can change these by editing `users.json` with hashed passwords.

## Running on itsjustlife.cloud

To run this app on the cloud server:

1. SSH into the machine:
   ```bash
   ssh <your-user>@itsjustlife.cloud
   ```
2. Navigate to the project folder and start the server:
   ```bash
   cd /path/to/dimmi/THINK/APPS/WebEditor
   ./run_server.sh
   ```
   The app will listen on `http://localhost:8000`. Use a reverse proxy like Nginx if you need a public URL.
