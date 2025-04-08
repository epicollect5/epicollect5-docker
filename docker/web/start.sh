#!/bin/bash

# Create file to indicate deployment is in progress
touch /tmp/deployment_in_progress

# Configure Apache to use the correct DocumentRoot
if [ -f "/etc/apache2/sites-available/000-default.conf" ]; then
    # Create or update the Apache configuration
    cat > /etc/apache2/sites-available/000-default.conf << 'EOL'
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/public

    <Directory /var/www/html/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOL

    # Ensure the public directory exists
    mkdir -p /var/www/html/public

    # Set proper permissions (excluding .git directory)
    find /var/www/html -not -path "*/\.git*" -exec chown www-data:www-data {} \;
fi

# Start Apache
service apache2 start

# Setup SSL if in production mode
if [ -f "/setup-ssl.sh" ]; then
    bash /setup-ssl.sh
fi

# Make deploy.sh executable
chmod +x /var/www/html/docker/web/deploy.sh

# Check if application is already installed
if [ -d "/var/www/html_prod/current" ] && [ -f "/var/www/html_prod/current/.env" ]; then
    echo "Application is already installed. Skipping deployment."
    # Remove the health check file since we're not deploying
    rm -f /tmp/deployment_in_progress
else
    # Only attempt deployment once to prevent infinite loops
    if [ ! -f "/tmp/deployment_attempted" ]; then
        touch /tmp/deployment_attempted

        echo "Running deployment with Deployer..."

        # Create deployment directory with correct permissions
        echo "Creating deployment directory with correct permissions..."
        mkdir -p /var/www/html_prod
        chown -R dev:www-data /var/www/html_prod

        # Set proper ownership for the source directory
        echo "Setting proper ownership for source directory..."
        find /var/www/html -not -path "*/\.git*" -exec chown dev:www-data {} \;

        # Run the deployment as the dev user using Deployer
        echo "Switching to dev user for deployment..."
        cd /var/www/html
        su dev -c "cd /var/www/html && dep install -f docker/web/deploy.php production"

        DEPLOY_STATUS=$?
        if [ $DEPLOY_STATUS -ne 0 ]; then
            echo "Deployment failed with exit code $DEPLOY_STATUS"
            rm -f /tmp/deployment_in_progress
            echo "Container will continue running despite deployment failure."
            # Don't exit here - let the container keep running
        fi
    else
        echo "Deployment was already attempted once. Skipping to prevent infinite loops."
    fi
fi

# Remove the deployment in progress file
rm -f /tmp/deployment_in_progress

# Keep container running
tail -f /dev/null
