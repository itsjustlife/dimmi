import json
import os
import secrets
from functools import wraps
from flask import Blueprint, request, jsonify
from werkzeug.security import check_password_hash

# Blueprint keeps authentication endpoints separate so apps can stay modular
auth_bp = Blueprint('auth', __name__)

# Load user credentials from a JSON file with hashed passwords
USERS_FILE = os.path.join(os.path.dirname(__file__), 'users.json')
if os.path.exists(USERS_FILE):
    with open(USERS_FILE, 'r', encoding='utf-8') as f:
        USERS = json.load(f)
else:
    USERS = {}

# In-memory token store.  In a bigger project this could be a database or redis.
TOKENS = {}

def token_required(func):
    """Decorator to require a valid auth token for write operations."""
    @wraps(func)
    def wrapper(*args, **kwargs):
        token = request.headers.get('Authorization')
        if not token or token not in TOKENS:
            return jsonify({'error': 'unauthorized'}), 401
        return func(*args, **kwargs)
    return wrapper

@auth_bp.post('/api/login')
def login():
    """Authenticate user and return a session token."""
    data = request.get_json() or {}
    username = data.get('username')
    password = data.get('password')
    if not username or not password:
        return jsonify({'error': 'username and password required'}), 400
    stored_hash = USERS.get(username)
    if not stored_hash or not check_password_hash(stored_hash, password):
        return jsonify({'error': 'invalid credentials'}), 401
    token = secrets.token_hex(16)
    TOKENS[token] = username
    return jsonify({'token': token})
