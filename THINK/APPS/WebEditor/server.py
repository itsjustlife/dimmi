import os
import logging
from flask import Flask, request, jsonify

# Import authentication blueprint and helper
from auth import auth_bp, token_required

app = Flask(__name__)
# Register authentication routes so login is available
app.register_blueprint(auth_bp)

# Set up basic logging to keep track of actions
logging.basicConfig(level=logging.INFO,
                    format='%(asctime)s %(levelname)s: %(message)s')

def build_tree(path):
    """Return a dictionary representing the directory tree."""
    name = os.path.basename(path) or path
    if os.path.isdir(path):
        tree = {"name": name, "path": path, "type": "directory", "children": []}
        try:
            for entry in os.listdir(path):
                child_path = os.path.join(path, entry)
                tree["children"].append(build_tree(child_path))
        except PermissionError:
            logging.warning("Permission denied when listing %s", path)
            tree["error"] = "permission denied"
        return tree
    if os.path.exists(path):
        return {"name": name, "path": path, "type": "file"}
    raise FileNotFoundError(path)

@app.get('/api/list')
def list_directory():
    """Return the directory tree for the given path."""
    path = request.args.get('path', '.')
    logging.info("Listing directory: %s", path)
    try:
        if not os.path.exists(path):
            raise FileNotFoundError(path)
        return jsonify(build_tree(path))
    except FileNotFoundError:
        logging.error("Directory not found: %s", path)
        return jsonify({"error": "not found"}), 404
    except PermissionError:
        logging.error("Permission denied: %s", path)
        return jsonify({"error": "permission denied"}), 403

@app.get('/api/file')
def get_file():
    """Read a text file and return its contents."""
    path = request.args.get('path')
    if not path:
        return jsonify({"error": "path required"}), 400
    logging.info("Reading file: %s", path)
    try:
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()
        return jsonify({"path": path, "content": content})
    except FileNotFoundError:
        logging.error("File not found: %s", path)
        return jsonify({"error": "not found"}), 404
    except PermissionError:
        logging.error("Permission denied: %s", path)
        return jsonify({"error": "permission denied"}), 403

@app.post('/api/file')
@token_required
def save_file():
    """Save text to a file. Requires a valid auth token."""
    data = request.get_json() or {}
    path = data.get('path')
    content = data.get('content')
    if not path or content is None:
        return jsonify({"error": "path and content required"}), 400
    logging.info("Writing file: %s", path)
    try:
        with open(path, 'w', encoding='utf-8') as f:
            f.write(content)
        return jsonify({"status": "ok"})
    except FileNotFoundError:
        logging.error("File not found: %s", path)
        return jsonify({"error": "not found"}), 404
    except PermissionError:
        logging.error("Permission denied: %s", path)
        return jsonify({"error": "permission denied"}), 403

if __name__ == '__main__':
    app.run(debug=True)
