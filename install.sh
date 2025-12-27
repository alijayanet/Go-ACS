#!/bin/bash

# ACS Full Installation Script (Database + Service)

# ========================================
# CONFIGURATION - Customize Here
# ========================================
DB_NAME="acs"
DB_USER="root"
DB_PASS="secret123"
INSTALL_DIR="/opt/acs"
SERVICE_NAME="acslite"
DB_DSN="$DB_USER:$DB_PASS@tcp(127.0.0.1:3306)/$DB_NAME?parseTime=true"

# Admin Login Credentials (stored in web/data/admin.json)
ADMIN_USER="admin"
ADMIN_PASS="admin123"

# Note: Telegram notifications are configured in web/api/admin_api.php

# ========================================
# Send Telegram Notification Function
# Calls PHP API which has token stored securely
# ========================================
send_telegram_via_php() {
    local message="$1"
    local php_api="http://localhost:8888/api/notify.php"
    
    # Try to send via PHP API (if PHP is running)
    curl -s -X POST "$php_api" \
        -H "Content-Type: application/json" \
        -d "{\"message\": \"$message\"}" > /dev/null 2>&1 || true
}

# ========================================
# Check for Root Privileges
# ========================================
if [ "$EUID" -ne 0 ]; then
  echo "Please run as root (sudo ./install.sh)"
  exit 1
fi

echo "=========================================="
echo "ACS Full Installer"
echo "=========================================="

# ---------------------------------------------------------
# PART 1: DATABASE SETUP
# ---------------------------------------------------------
echo ""
echo ">>> STEP 1: Setting up Database..."

# 1. Install MariaDB Server
if ! command -v mysql &> /dev/null; then
    echo "[INFO] MariaDB not found. Installing..."
    
    if command -v apt-get &> /dev/null; then
        apt-get update
        apt-get install -y mariadb-server
    elif command -v yum &> /dev/null; then
        yum install -y mariadb-server
    else
        echo "[ERROR] Unsupported package manager. Please install MariaDB manually."
        exit 1
    fi
else
    echo "[INFO] MariaDB is already installed."
fi

# 2. Start and Enable Service
echo "[INFO] Starting MariaDB Service..."
systemctl start mariadb
systemctl enable mariadb

# 3. Secure Installation & Set Root Password
echo "[INFO] Configuring Database..."

# Check if we can login without password (fresh install)
if mysql -u root -e "status" &>/dev/null; then
    echo "[INFO] Setting root password to '$DB_PASS'..."
    mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$DB_PASS'; FLUSH PRIVILEGES;"
else
    echo "[INFO] Root password already set or requires authentication."
    echo "[INFO] Attempting to connect with password..."
fi

# 4. Create Database
echo "[INFO] Creating database '$DB_NAME'..."
mysql -u $DB_USER -p$DB_PASS -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;"

if [ $? -ne 0 ]; then
    echo "[ERROR] Failed to create database. Please check your password."
    exit 1
fi
echo "[SUCCESS] Database ready."

# 5. Create Tables
echo "[INFO] Creating database tables..."

mysql -u $DB_USER -p$DB_PASS $DB_NAME <<EOF
-- Create onu_locations table (with customer login support)
CREATE TABLE IF NOT EXISTS onu_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    serial_number VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) DEFAULT NULL,
    username VARCHAR(50) DEFAULT NULL COMMENT 'Customer login username',
    password VARCHAR(255) DEFAULT NULL COMMENT 'Customer login password (hashed)',
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_serial (serial_number),
    INDEX idx_coords (latitude, longitude),
    UNIQUE INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOF

if [ $? -eq 0 ]; then
    echo "[SUCCESS] Database tables created."
else
    echo "[WARNING] Failed to create tables. You may need to run migration manually."
fi


# ---------------------------------------------------------
# PART 2: SERVICE SETUP
# ---------------------------------------------------------
echo ""
echo ">>> STEP 2: Installing Application Service..."

# 1. Detect Architecture
ARCH=$(uname -m)
echo "[INFO] Detected Architecture: $ARCH"

if [ "$ARCH" = "x86_64" ]; then
    BINARY_SOURCE="build/acs-linux-amd64"
elif [ "$ARCH" = "aarch64" ]; then
    BINARY_SOURCE="build/acs-linux-arm64"
else
    echo "[ERROR] Unsupported architecture: $ARCH"
    exit 1
fi

# 2. Verify Binary Exists
if [ ! -f "$BINARY_SOURCE" ]; then
    echo "[ERROR] Binary not found at: $BINARY_SOURCE"
    echo "Please ensure you have uploaded the 'build' folder."
    exit 1
fi

# 3. Create Installation Directory
echo "[INFO] Creating installation directory at $INSTALL_DIR..."
mkdir -p "$INSTALL_DIR/web/templates"
mkdir -p "$INSTALL_DIR/web/api"
mkdir -p "$INSTALL_DIR/web/data"

# 4. Copy Files
echo "[INFO] Copying application files..."
cp "$BINARY_SOURCE" "$INSTALL_DIR/acs"
chmod +x "$INSTALL_DIR/acs"

# Copy web/templates
if [ -d "web/templates" ]; then
    cp -r web/templates/* "$INSTALL_DIR/web/templates/"
    echo "[INFO] Copied web/templates/"
else
    echo "[WARNING] web/templates directory not found! UI might not work."
fi

# Copy web/api (PHP API files)
if [ -d "web/api" ]; then
    cp -r web/api/* "$INSTALL_DIR/web/api/"
    echo "[INFO] Copied web/api/"
fi

# Copy web/data if exists
if [ -d "web/data" ]; then
    cp -r web/data/* "$INSTALL_DIR/web/data/" 2>/dev/null || true
    echo "[INFO] Copied web/data/"
fi

# Copy .htaccess if exists
if [ -f "web/.htaccess" ]; then
    cp "web/.htaccess" "$INSTALL_DIR/web/.htaccess"
    echo "[INFO] Copied web/.htaccess"
fi

# 5. Create .env File
echo "[INFO] Creating .env configuration file..."
cat <<EOF > "$INSTALL_DIR/.env"
ACS_PORT=7547
DB_DSN=$DB_DSN
API_KEY=secret
EOF
chmod 600 "$INSTALL_DIR/.env"

# 6. Create Systemd Service File
echo "[INFO] Creating systemd service file..."
cat <<EOF > /etc/systemd/system/$SERVICE_NAME.service
[Unit]
Description=GoACS TR-069 Auto Configuration Server
After=network.target mariadb.service

[Service]
Type=simple
User=root
WorkingDirectory=$INSTALL_DIR
ExecStart=$INSTALL_DIR/acs
Restart=always
RestartSec=5

# Load Environment Variables from .env
EnvironmentFile=$INSTALL_DIR/.env

# Logging
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=$SERVICE_NAME

[Install]
WantedBy=multi-user.target
EOF

# 7. Enable and Start Service
echo "[INFO] Reloading systemd daemon..."
systemctl daemon-reload
systemctl enable $SERVICE_NAME
systemctl restart $SERVICE_NAME

# ---------------------------------------------------------
# PART 3: PHP API SERVER (Customer Portal)
# ---------------------------------------------------------
echo ""
echo ">>> STEP 3: Installing PHP API Server..."

# 1. Fix potential repository issues
echo "[INFO] Fixing repository configuration..."
sed -i '/backports/d' /etc/apt/sources.list 2>/dev/null || true

# 2. Install PHP
echo "[INFO] Installing PHP..."
if ! command -v php &> /dev/null; then
    if command -v apt-get &> /dev/null; then
        apt-get update
        apt-get install -y php-cli php-mysql php-json 2>/dev/null || apt-get install -y php php-mysql 2>/dev/null || echo "[WARNING] PHP installation failed. Customer API may not work."
    elif command -v yum &> /dev/null; then
        yum install -y php php-mysql php-json 2>/dev/null || echo "[WARNING] PHP installation failed."
    fi
else
    echo "[INFO] PHP is already installed."
fi

# 3. Ensure customers.json exists
echo "[INFO] Checking customers.json..."
if [ ! -f "$INSTALL_DIR/web/data/customers.json" ]; then
    echo '{"customers":{}}' > "$INSTALL_DIR/web/data/customers.json"
    chmod 666 "$INSTALL_DIR/web/data/customers.json"
    echo "[INFO] Created customers.json"
fi

# 4. Ensure admin.json exists with default credentials
echo "[INFO] Checking admin.json..."
if [ ! -f "$INSTALL_DIR/web/data/admin.json" ]; then
    cat <<ADMINJSON > "$INSTALL_DIR/web/data/admin.json"
{
    "admin": {
        "username": "$ADMIN_USER",
        "password": "$ADMIN_PASS"
    }
}
ADMINJSON
    chmod 600 "$INSTALL_DIR/web/data/admin.json"
    echo "[INFO] Created admin.json with default credentials"
else
    echo "[INFO] admin.json already exists, keeping current credentials"
fi

# 5. Create PHP API systemd service
echo "[INFO] Creating PHP API service..."
cat <<EOF > /etc/systemd/system/acs-php-api.service
[Unit]
Description=ACS PHP Customer API Server
After=network.target mariadb.service $SERVICE_NAME.service
Wants=mariadb.service

[Service]
Type=simple
User=root
WorkingDirectory=$INSTALL_DIR/web
ExecStart=/usr/bin/php -S 0.0.0.0:8888
Restart=always
RestartSec=5

# Logging
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=acs-php-api

[Install]
WantedBy=multi-user.target
EOF

# 5. Enable and Start PHP API Service
if command -v php &> /dev/null; then
    echo "[INFO] Starting PHP API service..."
    systemctl daemon-reload
    systemctl enable acs-php-api
    systemctl restart acs-php-api
    PHP_STATUS="Running"
else
    echo "[WARNING] PHP not installed. Customer API will not be available."
    PHP_STATUS="Not Available (PHP not installed)"
fi

# ---------------------------------------------------------
# FINAL STATUS
# ---------------------------------------------------------
echo ""
echo "=========================================="
if systemctl is-active --quiet $SERVICE_NAME; then
    SERVER_IP=$(hostname -I | awk '{print $1}')
    echo "[SUCCESS] INSTALLATION COMPLETE!"
    echo "------------------------------------------"
    echo ""
    echo "📍 Main Application (Go Server - Port 7547):"
    echo "   Admin Panel: http://$SERVER_IP:7547/web/templates/index.html"
    echo "   Admin Login: http://$SERVER_IP:7547/web/templates/login.html"
    echo "   Map View:    http://$SERVER_IP:7547/web/templates/map.html"
    echo ""
    echo "📍 Customer Portal (PHP API - Port 8888):"
    echo "   Customer Login: http://$SERVER_IP:7547/web/templates/customer_login.html"
    echo "   API Status: $PHP_STATUS"
    echo ""
    echo "📍 Admin Credentials:"
    echo "   Username: $ADMIN_USER"
    echo "   Password: $ADMIN_PASS"
    echo ""
    echo "📍 Configuration:"
    echo "   Config File: $INSTALL_DIR/.env"
    echo "   Database: $DB_NAME (user: $DB_USER)"
    echo ""
    echo "📍 Service Commands:"
    echo "   ACS Status:     systemctl status $SERVICE_NAME"
    echo "   PHP API Status: systemctl status acs-php-api"
    echo "------------------------------------------"
    
    # Send success notification to Telegram via PHP API
    send_telegram_via_php "✅ <b>Go-ACS Installation Complete!</b>

📍 Server: ${SERVER_IP}
🕐 Time: $(date '+%Y-%m-%d %H:%M:%S')
💻 Hostname: $(hostname)

🌐 <b>Access URLs:</b>
• Admin Panel: http://${SERVER_IP}:7547/web/templates/index.html
• Admin Login: http://${SERVER_IP}:7547/web/templates/login.html
• Customer Portal: http://${SERVER_IP}:7547/web/templates/customer_login.html

🔐 <b>Admin Credentials:</b>
• Username: ${ADMIN_USER}
• Password: ${ADMIN_PASS}

📊 PHP API: ${PHP_STATUS}

📞 Support: wa.me/6281947215703"

else
    echo "[ERROR] Service failed to start."
    echo "Check logs: journalctl -u $SERVICE_NAME -e"
    
    # Send failure notification to Telegram via PHP API
    send_telegram_via_php "❌ <b>Go-ACS Installation Failed!</b>

📍 Server: ${SERVER_IP}
🕐 Time: $(date '+%Y-%m-%d %H:%M:%S')
💻 Hostname: $(hostname)

⚠️ Service failed to start.
Please check logs: journalctl -u $SERVICE_NAME -e

📞 Support: wa.me/6281947215703"
fi
echo "=========================================="
