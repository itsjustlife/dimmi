import os, sys

# Base = this "dimmi" folder
BASE = os.path.dirname(__file__)

# Add WebEditor code to path
sys.path.insert(0, os.path.join(BASE, "THINK", "APPS", "WebEditor"))

# Import the Flask app
from server import app as application
