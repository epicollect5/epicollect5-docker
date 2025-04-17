#!/bin/bash
# Import logging functions
source ./log.sh

echo "===== DEBUG INFO ====="
echo "UPDATE_CODEBASE: '$UPDATE_CODEBASE'"
echo "env file exists: $(ls -l /var/www/html_prod/shared/.env 2>/dev/null || echo 'No')"
echo "======================"

# Configure Apache to use the correct configuration file
if [ -f "/etc/apache2/sites-available/000-default.conf" ]; then
    # Copy the prepared configuration file
    cp /var/www/docker/docker/apache/epicollect5.conf /etc/apache2/sites-available/000-default.conf
fi

# Define the remote repository URL
REPO_URL="https://github.com/epicollect5/epicollect5-server.git"

# Fetch the latest tag from the master branch
LATEST_TAG=$(git ls-remote --tags "$REPO_URL" | grep "refs/tags/" | awk '{print $2}' | sed 's/refs\/tags\///' | sort -V | tail -n 1)

# Clean the tag by removing any trailing `^{}` suffix
LATEST_TAG=${LATEST_TAG//\^\{\}/}

echo "Latest tag from master branch: $LATEST_TAG"

# Get the current version from the .env file (PRODUCTION_SERVER_VERSION)
CURRENT_VERSION=$(grep 'PRODUCTION_SERVER_VERSION' /var/www/html_prod/shared/.env | cut -d '=' -f2 | xargs)
echo "Current version from .env: $CURRENT_VERSION"

  # Start Apache if needed
    service apache2 start

# Compare the current version and the latest tag
if [ "$CURRENT_VERSION" == "$LATEST_TAG" ]; then
    # If they match, skip the update
    echo "Latest tag from master branch: $LATEST_TAG"
    echo "Current version from .env: $CURRENT_VERSION"
    echo "Versions match, skipping update..."
    log_success "🎉 Epicollect5 is up to date!"
else
    log_warning "⚠️ Versions do not match, update required."

   # Setup SSL if in production mode
   if [ -f "/setup-ssl.sh" ]; then
       bash /setup-ssl.sh
   fi

   # Check if application is already installed
   if [ -f "/var/www/html_prod/shared/.env" ]; then

       #Update or skip based on env
       if [ "$UPDATE_CODEBASE" == "false" ]; then
           log_warning "⚠️Skipping update as UPDATE_CODEBASE is set to false."
       else
           echo "⚙️Application already installed. Running update..."
           su dev -c "cd /var/www/docker && dep update -f docker/web/deploy.php production -vvv"
       fi
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
           cd /var/www/docker || exit 1
           su dev -c "cd /var/www/docker && dep install -f docker/web/deploy.php production -vvv"
       else
           echo "Deployment was already attempted once. Skipping to prevent infinite loops."
       fi
   fi
fi

# Keep container running
tail -f /dev/null
