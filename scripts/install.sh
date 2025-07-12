#!/bin/bash

# var_send PHP Extension Installation Script
# This script automates the installation process for the var_send PHP extension

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if script is run as root for make install
check_sudo() {
    if [[ $EUID -eq 0 ]]; then
        SUDO_CMD=""
    else
        SUDO_CMD="sudo"
        print_warning "Some operations require sudo privileges"
    fi
}

# Check prerequisites
check_prerequisites() {
    print_status "Checking prerequisites..."
    
    # Check for PHP
    if ! command -v php &> /dev/null; then
        print_error "PHP is not installed. Please install PHP first."
        exit 1
    fi
    
    # Check for phpize
    if ! command -v phpize &> /dev/null; then
        print_error "phpize is not found. Please install php-dev package."
        print_error "Ubuntu/Debian: sudo apt-get install php-dev"
        print_error "CentOS/RHEL: sudo yum install php-devel"
        print_error "macOS: Install Xcode command line tools or use Homebrew"
        exit 1
    fi
    
    # Check for required build tools
    for tool in autoconf make gcc; do
        if ! command -v $tool &> /dev/null; then
            print_error "$tool is not installed. Please install build-essential or development tools."
            exit 1
        fi
    done
    
    print_status "All prerequisites satisfied"
}

# Build and install PHP extension
install_php_extension() {
    print_status "Building var_send PHP extension..."
    
    # Clean previous builds if they exist
    if [ -f "Makefile" ]; then
        make clean 2>/dev/null || true
    fi
    
    # Prepare build environment
    print_status "Running phpize..."
    phpize
    
    # Configure
    print_status "Configuring build..."
    ./configure --enable-var-send
    
    # Build
    print_status "Compiling extension..."
    make
    
    # Install
    print_status "Installing extension..."
    $SUDO_CMD make install
    
    print_status "PHP extension compiled and installed successfully"
}



# Configure PHP extension
configure_extension() {
    print_status "Configuring PHP extension..."
    
    # Check if extension is already enabled
    if php -m | grep -q "var_send"; then
        print_status "var_send extension is already enabled"
        return
    fi
    
    # Find PHP ini file or conf.d directory
    PHP_INI=$(php --ini | grep "Loaded Configuration File" | cut -d: -f2 | xargs)
    CONF_D_DIR=$(php --ini | grep "Scan for additional .ini files" | cut -d: -f2 | xargs)
    
    # Determine where to add the extension
    if [ -n "$PHP_INI" ] && [ "$PHP_INI" != "(none)" ] && [ -f "$PHP_INI" ]; then
        # Use main php.ini file
        print_status "Adding extension to php.ini: $PHP_INI"
        
        # Create backup
        $SUDO_CMD cp "$PHP_INI" "$PHP_INI.backup.$(date +%Y%m%d_%H%M%S)"
        
        # Add extension line
        echo "extension=var_send.so" | $SUDO_CMD tee -a "$PHP_INI"
        
        print_status "Extension added to php.ini"
        
    elif [ -n "$CONF_D_DIR" ] && [ -d "$CONF_D_DIR" ]; then
        # Use conf.d directory
        EXT_INI_FILE="$CONF_D_DIR/var_send.ini"
        print_status "Adding extension to conf.d directory: $EXT_INI_FILE"
        
        # Create extension ini file
        echo "extension=var_send.so" | $SUDO_CMD tee "$EXT_INI_FILE"
        
        print_status "Extension configuration created: $EXT_INI_FILE"
        
    else
        print_warning "Could not determine PHP configuration location."
        print_warning "Please manually add 'extension=var_send.so' to your PHP configuration."
        print_warning "You can create a file like: /usr/local/etc/php/conf.d/var_send.ini"
        print_warning "Or add it to your main php.ini file."
        return
    fi
}

# Verify installation
verify_installation() {
    print_status "Verifying installation..."
    
    if php -m | grep -q "var_send"; then
        print_status "✓ var_send extension is loaded successfully"
        
        # Show extension info
        php -r "
        if (function_exists('var_send')) {
            echo 'var_send function is available\n';
            echo 'Default configuration:\n';
            echo '  var_send.server_host: ' . ini_get('var_send.server_host') . '\n';
            echo '  var_send.server_port: ' . ini_get('var_send.server_port') . '\n';
            echo '  var_send.enabled: ' . (ini_get('var_send.enabled') ? 'Yes' : 'No') . '\n';
        } else {
            echo 'var_send function not found\n';
            exit(1);
        }
        "
    else
        print_error "✗ var_send extension is not loaded"
        print_error "Please check your PHP configuration and restart your web server"
        exit 1
    fi
}

# Main installation function
main() {
    echo "var_send PHP Extension Installer"
    echo "================================"
    echo
    
    # Get the directory where this script is located
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
    
    # Change to project root directory
    cd "$PROJECT_ROOT"
    
    check_sudo
    check_prerequisites
    install_php_extension
    configure_extension
    verify_installation
    
    echo
    print_status "Installation completed successfully!"
    echo
    echo "Next steps:"
    echo "1. Restart your web server (Apache/Nginx/PHP-FPM)"
    echo "2. Start the debug server: php src/debug-server/php/debug_server.php"
    echo "3. Test the extension with: php examples/example.php"
    echo
    echo "For more information, see README.MD"
}

# Run main function
main "$@"
