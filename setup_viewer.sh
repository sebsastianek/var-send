#!/bin/bash

# Setup script for var_send Debug Viewer
echo "🚀 Setting up var_send Debug Viewer"
echo "==================================="

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
echo "✅ Setup complete!"
echo ""
echo "🎯 Usage:"
echo "  1. Activate the environment:  source venv/bin/activate"
echo "  2. Start the debug viewer:    python debug_viewer.py"
echo "  3. Optional parameters:"
echo "     --host 0.0.0.0            (bind to all interfaces)"
echo "     --port 9001               (custom port)"
echo ""
echo "📝 Example:"
echo "  python debug_viewer.py --host 0.0.0.0 --port 9001"
echo ""
echo "🔄 To test with your PHP extension:"
echo "  php -d extension=./modules/var_send.so -r \"var_send('Hello World!');\""