#!/bin/bash

echo "Starting enhanced setup script..."

# Create main directory and subdirectories
mkdir -p /root/schemas/temp

# Set proper permissions
chmod +x /root/schemas/deploy.php
chmod 755 /root/schemas
chmod 755 /root/schemas/temp

# Create empty log file if it doesn't exist
touch /root/schemas/schema_deploy.log
chmod 644 /root/schemas/schema_deploy.log

# Add cron job to run every minute if not already there
if ! crontab -l | grep -q "/root/schemas/deploy.php"; then
    (crontab -l 2>/dev/null; echo "* * * * * /usr/bin/php /root/schemas/deploy.php >> /root/schemas/cron.log 2>&1") | crontab -
    echo "Added cron job to run deploy.php every minute"
else
    echo "Cron job already exists"
fi

# Function to find and update API configuration
setup_api_ips() {
    local api_ip1="$1"
    local api_ip2="$2"

    # Possible API config locations
    API_LOCATIONS=(
        "/usr/local/hestia/conf/api.conf"
        "/usr/local/hestia/data/api/api.conf"
        "/etc/hestia/api.conf"
        "/home/admin/conf/api.conf"
    )

    API_CONF=""

    # Find existing API config file
    for location in "${API_LOCATIONS[@]}"; do
        if [ -f "$location" ]; then
            API_CONF="$location"
            echo "Found API config at: $API_CONF"
            break
        fi
    done

    # If no config found, create default one
    if [ -z "$API_CONF" ]; then
        API_CONF="/usr/local/hestia/conf/api.conf"
        echo "Creating new API config at: $API_CONF"
        mkdir -p "$(dirname "$API_CONF")"
        touch "$API_CONF"
        chmod 640 "$API_CONF"
        chown root:hestia "$API_CONF" 2>/dev/null || chown root:root "$API_CONF"
    fi

    # Function to add IP to config
    add_ip_to_config() {
        local ip="$1"
        local config_file="$2"

        if [ -n "$ip" ] && [ "$ip" != "YOUR_IP" ]; then
            if ! grep -q "$ip" "$config_file" 2>/dev/null; then
                # If ALLOW_IP already exists, append to it
                if grep -q "^ALLOW_IP=" "$config_file"; then
                    sed -i "s/^ALLOW_IP='\(.*\)'/ALLOW_IP='\1,$ip'/" "$config_file"
                else
                    echo "ALLOW_IP='$ip'" >> "$config_file"
                fi
                echo "Added $ip to API access list"
            else
                echo "$ip already in API access list"
            fi
        fi
    }

    # Add IPs to config
    add_ip_to_config "$api_ip1" "$API_CONF"
    add_ip_to_config "$api_ip2" "$API_CONF"
}

# Setup Hestia v-install-wordpress command
echo "Setting up Hestia v-install-wordpress command..."

# Copy the v-install-wordpress script to Hestia bin directory
if [ -f "./v-install-wordpress" ]; then
    # Always copy and update the script (even if it exists)
    cp ./v-install-wordpress /usr/local/hestia/bin/v-install-wordpress
    chmod +x /usr/local/hestia/bin/v-install-wordpress

    # Check if script was updated or newly added
    if [ -f "/usr/local/hestia/bin/v-install-wordpress" ]; then
        echo "Updated v-install-wordpress command in Hestia"
    else
        echo "Added v-install-wordpress command to Hestia"
    fi
else
    echo "Warning: v-install-wordpress script not found in current directory"
fi

# Setup WP-CLI
echo "Checking WP-CLI installation..."

if ! command -v wp &> /dev/null; then
    echo "WP-CLI not found, installing..."

    # Download WP-CLI
    curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

    # Check if download was successful
    if [ -f "wp-cli.phar" ]; then
        # Make it executable
        chmod +x wp-cli.phar

        # Move to system path
        mv wp-cli.phar /usr/local/bin/wp

        # Verify installation
        if wp --info &> /dev/null; then
            echo "WP-CLI installed successfully"
        else
            echo "Error: WP-CLI installation failed"
        fi
    else
        echo "Error: Failed to download WP-CLI"
    fi
else
    echo "WP-CLI already installed"
    wp --version
fi

# Setup Nginx templates
echo "Setting up Nginx templates..."

# Copy tc-nginx-only.stpl template
if [ -f "./tc-nginx-only.stpl" ]; then
    TARGET_STPL="/usr/local/hestia/data/templates/web/nginx/tc-nginx-only.stpl"
    cp ./tc-nginx-only.stpl "$TARGET_STPL"
    chmod 644 "$TARGET_STPL"
    echo "Copied tc-nginx-only.stpl template"
else
    echo "Warning: tc-nginx-only.stpl template not found in current directory"
fi

# Copy tc-nginx-only.tpl template
if [ -f "./tc-nginx-only.tpl" ]; then
    TARGET_TPL="/usr/local/hestia/data/templates/web/nginx/tc-nginx-only.tpl"
    cp ./tc-nginx-only.tpl "$TARGET_TPL"
    chmod 644 "$TARGET_TPL"
    echo "Copied tc-nginx-only.tpl template"
else
    echo "Warning: tc-nginx-only.tpl template not found in current directory"
fi

# Setup Hestia API
echo "Setting up Hestia API..."

# Enable API in Hestia configuration
if [ -f "/usr/local/hestia/conf/hestia.conf" ]; then
    # Check if API is already enabled
    if ! grep -q "API='yes'" /usr/local/hestia/conf/hestia.conf; then
        # Enable API
        sed -i "s/API='.*'/API='yes'/" /usr/local/hestia/conf/hestia.conf
        # If no API line exists, add it
        if ! grep -q "^API=" /usr/local/hestia/conf/hestia.conf; then
            echo "API='yes'" >> /usr/local/hestia/conf/hestia.conf
        fi
        echo "API enabled in hestia.conf"
    else
        echo "API already enabled in hestia.conf"
    fi
fi

# Add IP addresses to API configuration (replace with your actual IPs)
API_IP1="104.248.205.174"  # Replace with your first IP
API_IP2="185.38.219.89"    # Replace with your second IP

setup_api_ips "$API_IP1" "$API_IP2"

# Rebuild web domains configuration if templates were updated
if [ -f "/usr/local/hestia/data/templates/web/nginx/tc-nginx-only.stpl" ] || [ -f "/usr/local/hestia/data/templates/web/nginx/tc-nginx-only.tpl" ]; then
    echo "Rebuilding web domain configurations..."

    # Get list of all users
    USERS=$(ls /usr/local/hestia/data/users/ 2>/dev/null || echo "")

    if [ -n "$USERS" ]; then
        for user in $USERS; do
            if [ -f "/usr/local/hestia/data/users/$user/web.conf" ]; then
                echo "Rebuilding web config for user: $user"

                # Read domains for this user
                while IFS= read -r line; do
                    if [[ $line =~ ^DOMAIN=\'([^\']+)\' ]]; then
                        domain="${BASH_REMATCH[1]}"

                        # Rebuild this domain's nginx config
                        if command -v v-rebuild-web-domain &> /dev/null; then
                            v-rebuild-web-domain "$user" "$domain" >/dev/null 2>&1
                        fi
                    fi
                done < "/usr/local/hestia/data/users/$user/web.conf"
            fi
        done
        echo "Web configurations rebuilt"
    else
        echo "No users found, skipping domain rebuild"
    fi

    # Test nginx configuration
    echo "Testing nginx configuration..."
    if nginx -t >/dev/null 2>&1; then
        echo "Nginx configuration is valid, reloading..."
        systemctl reload nginx
        if [ $? -eq 0 ]; then
            echo "Nginx reloaded successfully"
        else
            echo "Warning: Nginx reload failed"
        fi
    else
        echo "Error: Nginx configuration test failed, not reloading"
        echo "Please check nginx configuration manually:"
        echo "nginx -t"
    fi
else
    echo "No template changes detected, skipping domain rebuild"
fi

echo ""
echo "=== Setup completed successfully ==="
echo "- Directory: /root/schemas/"
echo "- PHP script: /root/schemas/deploy.php"
echo "- Temp directory: /root/schemas/temp/"
echo "- Log file: /root/schemas/schema_deploy.log"
echo "- Cron log: /root/schemas/cron.log"
echo "- Cron job: runs every minute"
echo "- Hestia command: v-install-wordpress updated"
echo "- WP-CLI: $(wp --version 2>/dev/null || echo 'installation checked')"
echo "- Nginx templates: tc-nginx-only.stpl and tc-nginx-only.tpl"
echo "- Hestia API: enabled with IP restrictions"
echo "- Domain configs: rebuilt if templates were updated"
echo ""

# Show API config location for reference
API_LOCATIONS=(
    "/usr/local/hestia/conf/api.conf"
    "/usr/local/hestia/data/api/api.conf"
    "/etc/hestia/api.conf"
    "/home/admin/conf/api.conf"
)

echo "API configuration locations checked:"
for location in "${API_LOCATIONS[@]}"; do
    if [ -f "$location" ]; then
        echo "✓ Found: $location"
    else
        echo "✗ Not found: $location"
    fi
done