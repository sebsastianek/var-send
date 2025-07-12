#!/bin/bash

# Install script for var_send Debug Viewer
# This script installs Python dependencies for the debug viewer

set -e  # Exit immediately on any error

echo "🚀 Installing var_send Debug Viewer"
echo "==================================="

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Check if Python is available
if ! command -v python3 &> /dev/null; then
    echo "❌ Error: Python 3 is not installed. Please install Python 3 first."
    exit 1
fi

# Navigate to Python debug server directory
cd "$PROJECT_ROOT/src/debug-server/python/"

# Create virtual environment if it doesn't exist
if [ ! -d "venv" ]; then
    echo "📦 Creating Python virtual environment..."
    python3 -m venv venv
fi

# Activate virtual environment
echo "🔧 Activating virtual environment..."
source venv/bin/activate

# Install dependencies
echo "📥 Installing dependencies..."
pip install -r requirements.txt

echo ""
echo "✅ Installation complete!"
echo ""
echo "🎯 Usage:"
echo "  1. Navigate to debug viewer:  cd src/debug-server/python/"
echo "  2. Activate the environment:  source venv/bin/activate"
echo "  3. Start the debug viewer:    python debug_viewer.py"
echo "  4. Optional parameters:"
echo "     --host 0.0.0.0            (bind to all interfaces)"
echo "     --port 9001               (custom port)"
echo ""
echo "📝 Example:"
echo "  python debug_viewer.py --host 0.0.0.0 --port 9001"
echo ""
echo "🔄 To test with your PHP extension:"
echo "  php -d extension=./modules/var_send.so -r \"var_send('Hello World!');\""