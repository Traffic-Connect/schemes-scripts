#!/bin/bash

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

# Setup Hestia v-install-wordpress command
echo "Setting up Hestia v-install-wordpress command..."

# Copy the v-install-wordpress script to Hestia bin directory
if [ -f "./v-install-wordpress" ]; then
    cp ./v-install-wordpress /usr/local/hestia/bin/v-install-wordpress
    chmod +x /usr/local/hestia/bin/v-install-wordpress
    chown root:root /usr/local/hestia/bin/v-install-wordpress
    echo "Added v-install-wordpress command to Hestia"
else
    echo "Warning: v-install-wordpress script not found in current directory"
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
API_IP2="185.38.219.89"  # Replace with your second IP

if [ -n "$API_IP1" ] || [ -n "$API_IP2" ]; then
    API_CONF="/usr/local/hestia/conf/api.conf"

    # Create API config file if it doesn't exist
    if [ ! -f "$API_CONF" ]; then
        touch "$API_CONF"
        chmod 640 "$API_CONF"
        chown root:hestia "$API_CONF"
    fi

    # Add first IP
    if [ -n "$API_IP1" ] && [ "$API_IP1" != "YOUR_FIRST_IP" ]; then
        if ! grep -q "^ALLOW_IP='.*$API_IP1.*'" "$API_CONF"; then
            # If ALLOW_IP already exists, append to it
            if grep -q "^ALLOW_IP=" "$API_CONF"; then
                sed -i "s/^ALLOW_IP='\(.*\)'/ALLOW_IP='\1,$API_IP1'/" "$API_CONF"
            else
                echo "ALLOW_IP='$API_IP1'" >> "$API_CONF"
            fi
            echo "Added $API_IP1 to API access list"
        else
            echo "$API_IP1 already in API access list"
        fi
    fi

    # Add second IP
    if [ -n "$API_IP2" ] && [ "$API_IP2" != "YOUR_SECOND_IP" ]; then
        if ! grep -q "^ALLOW_IP='.*$API_IP2.*'" "$API_CONF"; then
            # If ALLOW_IP already exists, append to it
            if grep -q "^ALLOW_IP=" "$API_CONF"; then
                sed -i "s/^ALLOW_IP='\(.*\)'/ALLOW_IP='\1,$API_IP2'/" "$API_CONF"
            else
                echo "ALLOW_IP='$API_IP2'" >> "$API_CONF"
            fi
            echo "Added $API_IP2 to API access list"
        else
            echo "$API_IP2 already in API access list"
        fi
    fi

    # Reload nginx to apply changes
    systemctl reload nginx >/dev/null 2>&1
fi

echo "Setup completed successfully"
echo "- Directory: /root/schemas/"
echo "- PHP script: /root/schemas/deploy.php"
echo "- Temp directory: /root/schemas/temp/"
echo "- Log file: /root/schemas/schema_deploy.log"
echo "- Cron log: /root/schemas/cron.log"
echo "- Cron job: runs every minute"
echo "- Hestia command: v-install-wordpress added"
echo "- Hestia API: enabled with IP restrictions"