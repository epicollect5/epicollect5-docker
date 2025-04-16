#!/bin/bash

if [ ! -f "/var/www/html_prod/shared/.installed" ]; then
    touch /var/www/html_prod/shared/.installed
fi

# Define the remote repository URL
REPO_URL="https://github.com/epicollect5/epicollect5-server.git"

# Fetch the latest tag from the master branch
LATEST_TAG=$(git ls-remote --tags "$REPO_URL" | grep "refs/tags/" | awk '{print $2}' | sed 's/refs\/tags\///' | sort -V | tail -n 1)
echo "Latest tag from master branch: $LATEST_TAG"

# Get the current version from the .env file (PRODUCTION_SERVER_VERSION)
CURRENT_VERSION=$(grep 'PRODUCTION_SERVER_VERSION' /var/www/html_prod/shared/.env | cut -d '=' -f2 | xargs)
echo "Current version from .env: $CURRENT_VERSION"

# Compare the current version and the latest tag
if [ "$CURRENT_VERSION" == "$LATEST_TAG" ]; then
    echo "Versions match, skipping update..."
    # Start Apache if needed
    service apache2 start
    exit 0
else
    echo "Versions do not match, update required."
    # Proceed with the update process
    # (Add any further actions here)
fi


# Configure Apache to use the correct configuration file
if [ -f "/etc/apache2/sites-available/000-default.conf" ]; then
    # Copy the prepared configuration file
    cp /var/www/docker/docker/apache/epicollect5.conf /etc/apache2/sites-available/000-default.conf
fi

# Start Apache
service apache2 start

# Setup SSL if in production mode
if [ -f "/setup-ssl.sh" ]; then
    bash /setup-ssl.sh
fi

# Check if application is already installed
if [ -f "/var/www/html_prod/shared/.env" ]; then
    echo "Application already installed. Running update..."
    su dev -c "cd /var/www/docker && dep update -f docker/web/deploy.php production -vvv"
else
    # Only attempt deployment once to prevent infinite loops
    if [ ! -f "/tmp/deployment_attempted" ]; then
        touch /tmp/deployment_attempted

        echo "Running deployment with Deployer..."

        # Ensure deployment dir exists and is writable by dev
        echo "Creating deployment directory with correct permissions..."
        mkdir -p /var/www/html_prod/shared
        chown -R dev:www-data /var/www/html_prod
        chmod -R 775 /var/www/html_prod

        # Set proper ownership for the source directory
        echo "Setting proper ownership for source directory..."
        find /var/www/docker -not -path "*/\.git*" -exec chown dev:www-data {} \;

        # Run the deployment as the dev user using Deployer
        echo "Switching to dev user for deployment..."
        cd /var/www/docker
        su dev -c "cd /var/www/docker && dep install -f docker/web/deploy.php production -vvv"
    else
        echo "Deployment was already attempted once. Skipping to prevent infinite loops."
    fi
fi



# Keep container running
tail -f /dev/null
