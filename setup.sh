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

echo "Setup completed successfully"
echo "- Directory: /root/schemas/"
echo "- PHP script: /root/schemas/deploy.php"
echo "- Temp directory: /root/schemas/temp/"
echo "- Log file: /root/schemas/schema_deploy.log"
echo "- Cron log: /root/schemas/cron.log"
echo "- Cron job: runs every minute"