#!/bin/bash

# Simple launcher for var_send Debug Viewer
echo "🎯 Starting var_send Debug Viewer"
echo "================================"

# Check if virtual environment exists
if [ ! -d "venv" ]; then
    echo "❌ Virtual environment not found. Please run: ./setup_viewer.sh"
    exit 1
fi

# Activate virtual environment
source venv/bin/activate

# Parse command line arguments
HOST="127.0.0.1"
PORT="9001"

while [[ $# -gt 0 ]]; do
    case $1 in
        --host)
            HOST="$2"
            shift 2
            ;;
        --port)
            PORT="$2"
            shift 2
            ;;
        -h|--help)
            echo "Usage: $0 [--host HOST] [--port PORT]"
            echo ""
            echo "Options:"
            echo "  --host HOST    Host to bind to (default: 127.0.0.1)"
            echo "  --port PORT    Port to listen on (default: 9001)"
            echo "  -h, --help     Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                                    # Listen on 127.0.0.1:9001"
            echo "  $0 --host 0.0.0.0                   # Listen on all interfaces"
            echo "  $0 --host 0.0.0.0 --port 9002      # Custom host and port"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

echo "🌐 Starting debug viewer on $HOST:$PORT"
echo "🔧 Configure your PHP to use: ini_set('var_send.server_host', '$HOST'); ini_set('var_send.server_port', '$PORT');"
echo ""
echo "📝 Navigation: q=Quit | c=Clear | f=Filter | s=Save | ↑↓=Navigate | Tab=Switch | Esc=Exit Filter"
echo ""

# Start the debug viewer
python debug_viewer.py --host "$HOST" --port "$PORT"
