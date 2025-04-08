#!/bin/bash

# Strict error handling
set -e
set -o pipefail

echo "Starting deployment with strict error handling..."

# Configuration
DEPLOY_PATH="/var/www/html_prod"
RELEASE_PATH="$DEPLOY_PATH/current"
SHARED_PATH="$DEPLOY_PATH/shared"
SOURCE_PATH="/var/www/html"
HTTP_USER="www-data"
TIMESTAMP=$(date +%Y%m%d%H%M%S)
RELEASE_NAME="release_$TIMESTAMP"

# Database configuration
DB_HOST="db"
DB_PORT="3306"
DB_USERNAME="epicollect5"
DB_PASSWORD="${DB_PASSWORD:-password}"
DB_NAME="epicollect5_prod"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-root_password}"

# Superadmin credentials
SUPER_ADMIN_EMAIL="${SUPER_ADMIN_EMAIL:-admin@example.com}"
SUPER_ADMIN_FIRST_NAME="${SUPER_ADMIN_FIRST_NAME:-Admin}"
SUPER_ADMIN_LAST_NAME="${SUPER_ADMIN_LAST_NAME:-User}"
SUPER_ADMIN_PASSWORD="${SUPER_ADMIN_PASSWORD:-AdminPassword123!}"
SYSTEM_EMAIL="${SYSTEM_EMAIL:-alerts@example.com}"

# Helper function for error handling
log_error_and_exit() {
    echo "ERROR: $1"
    exit 1
}

# Task: check:not_root
check_not_root() {
    echo "Checking if running as root..."
    if [ "$(id -u)" -eq 0 ]; then
        echo "ERROR: Deployment must not be run as root. Aborting."
        exit 1
    fi
}

# Task: setup:check_clean_install
check_clean_install() {
    echo "Checking if this is a clean install..."
    if [ -L "$RELEASE_PATH" ]; then
        echo "ERROR: A release already exists. Skipping install."
        exit 1
    fi
}

# Task: deploy:prepare
deploy_prepare() {
    echo "Preparing deployment directories..."
    mkdir -p "$DEPLOY_PATH/releases"
    mkdir -p "$SHARED_PATH"
    mkdir -p "$SHARED_PATH/storage"
    mkdir -p "$SHARED_PATH/public"
    mkdir -p "$DEPLOY_PATH/releases/$RELEASE_NAME"
}

# Task: deploy:vendors
deploy_vendors() {
    echo "Copying source code and installing dependencies..."
    if ! ls -la "$SOURCE_PATH"; then
        log_error_and_exit "Failed to list source directory"
    fi

    cd "$SOURCE_PATH" || log_error_and_exit "Failed to change to source directory"

    # Check if we're in a Laravel application (has composer.json)
    if [ ! -f "$SOURCE_PATH/composer.json" ]; then
        echo "WARNING: composer.json not found in source directory. Cloning the application repository..."

        # Clone the repository directly into the release directory
        cd "$DEPLOY_PATH/releases/$RELEASE_NAME" || log_error_and_exit "Failed to change to release directory"

        if ! git clone https://github.com/epicollect5/epicollect5-server.git .; then
            log_error_and_exit "Failed to clone the application repository"
        fi

        # Remove .git directory to avoid conflicts
        rm -rf .git
    else
        # Normal copy from source path
        if ! find . -mindepth 1 -maxdepth 1 -not -name ".git" -exec cp -R {} "$DEPLOY_PATH/releases/$RELEASE_NAME/" \;; then
            log_error_and_exit "Failed to copy source code"
        fi
    fi

    cd "$DEPLOY_PATH/releases/$RELEASE_NAME" || log_error_and_exit "Failed to change to release directory"

    COMPOSER_CMD="composer"
    if ! command -v composer &> /dev/null; then
        echo "Composer not found, installing..."
        curl -sS https://getcomposer.org/installer | php
        COMPOSER_CMD="php composer.phar"
    fi

    $COMPOSER_CMD install --no-dev --optimize-autoloader
}

# Task: deploy_publish
deploy_publish() {
    echo "Publishing the new release..."
    # Create symlinks for shared directories
    ln -sfn "$SHARED_PATH/storage" "$DEPLOY_PATH/releases/$RELEASE_NAME/storage"

    # Create the current symlink
    ln -sfn "$DEPLOY_PATH/releases/$RELEASE_NAME" "$RELEASE_PATH"

    echo "New release published."
}

# Task: setup:database
setup_database() {
    echo "Setting up database..."
    echo "DEBUG: Starting database setup process"

    # Generate a random alphanumeric password (mix of lowercase, uppercase, and numbers)
    echo "DEBUG: Generating random password"
    DB_APP_PASSWORD=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 16 | head -n 1)
    echo "Generated password: $DB_APP_PASSWORD"
    echo "DEBUG: Password generation complete"

    # Get the IP address of the app container for MySQL permissions
    APP_IP=$(hostname -i || ip addr show | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | grep -v '127.0.0.1' | head -n 1)
    echo "DEBUG: App container IP: $APP_IP"

    # Create SQL commands with IP-specific grants for skip-name-resolve mode
    echo "DEBUG: Creating SQL setup file with IP-specific grants"
    cat > /tmp/db_setup.sql << EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME;
DROP USER IF EXISTS '$DB_USERNAME'@'%';
DROP USER IF EXISTS '$DB_USERNAME'@'$APP_IP';
CREATE USER '$DB_USERNAME'@'%' IDENTIFIED BY '$DB_APP_PASSWORD';
CREATE USER '$DB_USERNAME'@'$APP_IP' IDENTIFIED BY '$DB_APP_PASSWORD';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USERNAME'@'%';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USERNAME'@'$APP_IP';
FLUSH PRIVILEGES;
EOF
    echo "DEBUG: SQL setup file created at /tmp/db_setup.sql"

    # Execute SQL commands
    echo "DEBUG: Executing SQL commands with mysql client"
    echo "DEBUG: Command: mysql -h $DB_HOST -P $DB_PORT -u root -p[MASKED] < /tmp/db_setup.sql"
    if ! mysql -h $DB_HOST -P $DB_PORT -u root -p$MYSQL_ROOT_PASSWORD < /tmp/db_setup.sql; then
        echo "ERROR: Failed to execute SQL setup commands"
        exit 1
    fi
    echo "DEBUG: SQL commands executed successfully"

    # Save the password for later use
    echo "DEBUG: Saving password to temporary file"
    echo "$DB_APP_PASSWORD" > /tmp/db_password.txt
    chmod 600 /tmp/db_password.txt  # Secure the password file
    echo "Password saved to /tmp/db_password.txt"
    echo "DEBUG: Password file created successfully"

    # Test the connection with the new credentials
    echo "DEBUG: Testing database connection with new credentials"
    echo "DEBUG: Command: mysql -h $DB_HOST -P $DB_PORT -u $DB_USERNAME -p[MASKED] -e \"SELECT 'Connection successful!';\" $DB_NAME"
    if mysql -h $DB_HOST -P $DB_PORT -u $DB_USERNAME -p$DB_APP_PASSWORD -e "SELECT 'Connection successful!';" $DB_NAME; then
        echo "DEBUG: Database connection test successful"
    else
        echo "WARNING: Database connection test failed. Check credentials."
        echo "DEBUG: Continuing despite connection test failure"
    fi

    echo "Database setup completed."
    echo "DEBUG: setup_database function completed"
}

# Task: setup:env
setup_env() {
    echo "Setting up environment file..."

    # Make sure we can read the password file
    if [ ! -f "/tmp/db_password.txt" ]; then
        echo "ERROR: Password file not found at /tmp/db_password.txt"
        exit 1
    fi

    DB_APP_PASSWORD=$(cat /tmp/db_password.txt)
    echo "Using database password: $DB_APP_PASSWORD"

    # Copy .env.example to shared .env from the release directory, not current
    cp "$DEPLOY_PATH/releases/$RELEASE_NAME/.env.example" "$SHARED_PATH/.env"

    # Copy .htaccess-example to shared .htaccess from the release directory, not current
    cp "$DEPLOY_PATH/releases/$RELEASE_NAME/public/.htaccess-example" "$SHARED_PATH/public/.htaccess"

    # Update .env file - IMPORTANT: Force database name to be epicollect5_prod
    sed -i "s/^DB_DATABASE=.*/DB_DATABASE=epicollect5_prod/" "$SHARED_PATH/.env"
    sed -i "s/^DB_USERNAME=.*/DB_USERNAME=$DB_USERNAME/" "$SHARED_PATH/.env"
    sed -i "s/^DB_HOST=.*/DB_HOST=$DB_HOST/" "$SHARED_PATH/.env"
    sed -i "s/^DB_PORT=.*/DB_PORT=$DB_PORT/" "$SHARED_PATH/.env"

    # Direct replacement for password to ensure exact match
    sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$DB_APP_PASSWORD/" "$SHARED_PATH/.env"

    # Create symlink for .env in the release directory
    ln -sf "$SHARED_PATH/.env" "$DEPLOY_PATH/releases/$RELEASE_NAME/.env"

    # Create symlink for .htaccess in the release directory
    ln -sf "$SHARED_PATH/public/.htaccess" "$DEPLOY_PATH/releases/$RELEASE_NAME/public/.htaccess"

    # Verify .env file contents
    echo "Verifying .env file database settings:"
    grep "^DB_" "$SHARED_PATH/.env"

    # Double-check that the password in .env matches the one we generated
    DB_PASSWORD_IN_ENV=$(grep "^DB_PASSWORD=" "$SHARED_PATH/.env" | cut -d= -f2)
    DB_DATABASE_IN_ENV=$(grep "^DB_DATABASE=" "$SHARED_PATH/.env" | cut -d= -f2)
    echo "DEBUG: Password in .env: $DB_PASSWORD_IN_ENV"
    echo "DEBUG: Database in .env: $DB_DATABASE_IN_ENV"
    echo "DEBUG: Original password: $DB_APP_PASSWORD"

    if [ "$DB_PASSWORD_IN_ENV" != "$DB_APP_PASSWORD" ]; then
        echo "ERROR: Password mismatch between generated password and .env file"
        echo "This could cause database connection issues later"
    fi

    if [ "$DB_DATABASE_IN_ENV" != "epicollect5_prod" ]; then
        echo "ERROR: Database name in .env is not epicollect5_prod"
        echo "Fixing database name in .env file"
        sed -i "s/^DB_DATABASE=.*/DB_DATABASE=epicollect5_prod/" "$SHARED_PATH/.env"
    fi

    echo "Environment file setup completed."
}

# Task: setup:key:generate
key_generate() {
    echo "Generating application key..."

    # Run the command exactly as in the original task
    cd "$RELEASE_PATH" && php artisan key:generate --force

    echo "Application key generated."
}

# Task: storage_link
storage_link() {
    echo "Creating storage symbolic link..."
    cd "$RELEASE_PATH"
    php artisan storage:link

    echo "Storage link created."
}

# Task: setup:passport:keys
passport_keys() {
    echo "Generating Passport keys..."
    cd "$RELEASE_PATH"
    php artisan passport:keys

    echo "Passport keys generated."
}

# Task: setup:superadmin
setup_superadmin() {
    echo "Setting up superadmin account..."
    # Update .env file with superadmin details
    sed -i "s/^SUPER_ADMIN_FIRST_NAME=.*/SUPER_ADMIN_FIRST_NAME=$SUPER_ADMIN_FIRST_NAME/" "$SHARED_PATH/.env"
    sed -i "s/^SUPER_ADMIN_LAST_NAME=.*/SUPER_ADMIN_LAST_NAME=$SUPER_ADMIN_LAST_NAME/" "$SHARED_PATH/.env"
    sed -i "s/^SUPER_ADMIN_EMAIL=.*/SUPER_ADMIN_EMAIL=$SUPER_ADMIN_EMAIL/" "$SHARED_PATH/.env"
    sed -i "s/^SUPER_ADMIN_PASSWORD=.*/SUPER_ADMIN_PASSWORD=$SUPER_ADMIN_PASSWORD/" "$SHARED_PATH/.env"

    echo "Superadmin account setup completed."
}

# Task: setup:alerts
setup_alerts() {
    echo "Setting up system alerts email..."
    # Update .env file with system email
    sed -i "s/^SYSTEM_EMAIL=.*/SYSTEM_EMAIL=$SYSTEM_EMAIL/" "$SHARED_PATH/.env"

    echo "System alerts email setup completed."
}

# Task: view:clear
view_clear() {
    echo "Clearing view cache..."
    cd "$RELEASE_PATH"
    php artisan view:clear

    echo "View cache cleared."
}

# Task: config:cache
config_cache() {
    echo "Caching configuration..."
    cd "$RELEASE_PATH"
    php artisan config:cache

    echo "Configuration cached."
}

# Task: migrate
migrate() {
    echo "Running database migrations..."
    cd "$RELEASE_PATH"
    php artisan migrate --force

    echo "Database migrations completed."
}

# Task: symlink_deploy_file
symlink_deploy_file() {
    echo "Creating symlink for deploy.php..."
    ln -sf "$RELEASE_PATH/deploy.php" "$DEPLOY_PATH/deploy.php"

    echo "Deploy.php symlink created."
}

# Task: symlink_laravel_storage_folders_file
symlink_storage_folders_file() {
    echo "Creating symlink for laravel_storage_folders.sh..."
    ln -sf "$RELEASE_PATH/laravel_storage_folders.sh" "$DEPLOY_PATH/laravel_storage_folders.sh"
    chmod +x "$DEPLOY_PATH/laravel_storage_folders.sh"

    echo "Laravel storage folders script symlink created."
}

# Task: setup:update_permissions:bash_scripts
update_permissions_bash_scripts() {
    echo "Updating permissions for bash scripts..."
    chmod 700 "$RELEASE_PATH/after_pull-dev.sh"
    chmod 700 "$RELEASE_PATH/after_pull-prod.sh"
    chmod 700 "$RELEASE_PATH/laravel_storage_folders.sh"

    echo "Bash scripts permissions updated."
}

# Task: setup:update_permissions:api_keys
update_permissions_api_keys() {
    echo "Updating permissions for API keys..."
    # Get the HTTP server user
    chown -R $(whoami):$HTTP_USER "$RELEASE_PATH/storage/oauth-private.key"
    chown -R $(whoami):$HTTP_USER "$RELEASE_PATH/storage/oauth-public.key"
    chmod 640 "$RELEASE_PATH/storage/oauth-private.key"
    chmod 640 "$RELEASE_PATH/storage/oauth-public.key"

    echo "API keys permissions updated."
}

# Task: setup:update_permissions:.env
update_permissions_env() {
    echo "Updating permissions for .env file..."
    chown $(whoami):$HTTP_USER "$SHARED_PATH/.env"
    chmod 640 "$SHARED_PATH/.env"

    echo ".env file permissions updated."
}

# Task: setup:stats
setup_stats() {
    echo "Setting up database for system stats..."
    cd "$RELEASE_PATH"

    # Force Laravel to reload the environment
    echo "DEBUG: Clearing config cache to ensure fresh environment variables"
    php artisan config:clear
    php artisan cache:clear

    # Get credentials directly from the .env file
    DB_PASSWORD_IN_ENV=$(grep "^DB_PASSWORD=" "$SHARED_PATH/.env" | cut -d= -f2)
    DB_USERNAME_IN_ENV=$(grep "^DB_USERNAME=" "$SHARED_PATH/.env" | cut -d= -f2)
    DB_NAME_IN_ENV=$(grep "^DB_DATABASE=" "$SHARED_PATH/.env" | cut -d= -f2)
    DB_HOST_IN_ENV=$(grep "^DB_HOST=" "$SHARED_PATH/.env" | cut -d= -f2)

    # Check if Laravel is using a different database name than what's in .env
    echo "DEBUG: Verifying Laravel database connection"
    LARAVEL_DB_NAME=$(php artisan tinker --execute="echo config('database.connections.mysql.database');" 2>/dev/null | grep -v "Psy Shell" | grep -v ">>>" | tr -d '\r\n')

    if [ -n "$LARAVEL_DB_NAME" ] && [ "$LARAVEL_DB_NAME" != "$DB_NAME_IN_ENV" ]; then
        echo "DEBUG: Database name mismatch detected. Laravel is using $LARAVEL_DB_NAME but .env has $DB_NAME_IN_ENV"
        echo "DEBUG: Updating .env file to use Laravel's database name"
        sed -i "s/^DB_DATABASE=.*/DB_DATABASE=$LARAVEL_DB_NAME/" "$SHARED_PATH/.env"
        DB_NAME_IN_ENV="$LARAVEL_DB_NAME"

        # Reload config after changing .env
        php artisan config:clear
        php artisan cache:clear
    fi

    echo "DEBUG: Using database credentials: DB_HOST=$DB_HOST_IN_ENV, DB_USERNAME=$DB_USERNAME_IN_ENV, DB_NAME=$DB_NAME_IN_ENV"

    # Create wildcard user as a fallback for skip-name-resolve mode
    echo "DEBUG: Attempting to create user with wildcard hostname as last resort"
    cat > /tmp/db_wildcard.sql << EOF
CREATE USER IF NOT EXISTS '$DB_USERNAME_IN_ENV'@'%' IDENTIFIED BY '$DB_PASSWORD_IN_ENV';
GRANT ALL PRIVILEGES ON $DB_NAME_IN_ENV.* TO '$DB_USERNAME_IN_ENV'@'%';
CREATE DATABASE IF NOT EXISTS $DB_NAME_IN_ENV;
FLUSH PRIVILEGES;
EOF

    if mysql -h "$DB_HOST_IN_ENV" -P "$DB_PORT" -u root -p"$MYSQL_ROOT_PASSWORD" < /tmp/db_wildcard.sql; then
        echo "DEBUG: Created wildcard user permissions"
    else
        echo "WARNING: Failed to create wildcard user permissions"
    fi

    # Reload config one more time
    php artisan config:clear
    php artisan cache:clear

    # Run the stats command with error handling
    echo "DEBUG: Running system:stats command"
    if php artisan migrate --force && php artisan system:stats --deployer; then
        echo "Initial system stats generated successfully."
    else
        echo "WARNING: Failed to generate system stats. This is non-fatal, continuing deployment."
    fi
}

# Task: setup:laravel_directories
setup_laravel_directories() {
    echo "Setting up Laravel directory structure..."

    # Create all required Laravel directories
    mkdir -p "$SHARED_PATH/storage/app/projects"
    mkdir -p "$SHARED_PATH/storage/app/temp"
    mkdir -p "$SHARED_PATH/storage/framework/cache/data"
    mkdir -p "$SHARED_PATH/storage/framework/sessions"
    mkdir -p "$SHARED_PATH/storage/framework/views"
    mkdir -p "$SHARED_PATH/storage/logs"

    # Create bootstrap/cache directory in the release path
    mkdir -p "$DEPLOY_PATH/releases/$RELEASE_NAME/bootstrap/cache"

    # Set proper permissions
    chmod -R 775 "$SHARED_PATH/storage"
    chmod -R 775 "$DEPLOY_PATH/releases/$RELEASE_NAME/bootstrap/cache"

    # Set proper ownership
    chown -R $(whoami):$HTTP_USER "$SHARED_PATH/storage"
    chown -R $(whoami):$HTTP_USER "$DEPLOY_PATH/releases/$RELEASE_NAME/bootstrap/cache"

    echo "Laravel directory structure setup completed."
}

# Task: setup:symlinks
setup_symlinks() {
    echo "Setting up symlinks..."

    # Create symlink for storage directory
    rm -rf "$DEPLOY_PATH/releases/$RELEASE_NAME/storage"
    ln -sfn "$SHARED_PATH/storage" "$DEPLOY_PATH/releases/$RELEASE_NAME/storage"

    echo "Symlinks setup completed."
}

# Task: clear:cache
clear_cache() {
    echo "Clearing cache..."

    # Clear Laravel cache files
    rm -rf "$SHARED_PATH/storage/framework/cache/data/*"
    rm -rf "$DEPLOY_PATH/releases/$RELEASE_NAME/bootstrap/cache/*"

    echo "Cache cleared."
}

# Run all tasks in sequence with error handling
echo "Starting deployment process..."

run_task() {
    local task_name="$1"
    local task_function="$2"

    echo "DEBUG: Starting task: $task_name"
    if ! $task_function; then
        echo "ERROR: Task '$task_name' failed with exit code $?"
        exit 1
    fi
    echo "DEBUG: Completed task: $task_name"
}

run_task "check_not_root" check_not_root
run_task "check_clean_install" check_clean_install
run_task "deploy_prepare" deploy_prepare
run_task "deploy_vendors" deploy_vendors
run_task "setup_laravel_directories" setup_laravel_directories
run_task "deploy_publish" deploy_publish
run_task "setup_database" setup_database
run_task "setup_env" setup_env
run_task "key_generate" key_generate
run_task "storage_link" storage_link
run_task "passport_keys" passport_keys
run_task "setup_superadmin" setup_superadmin
run_task "setup_alerts" setup_alerts
run_task "view_clear" view_clear
run_task "config_cache" config_cache
run_task "migrate" migrate
run_task "symlink_deploy_file" symlink_deploy_file
run_task "symlink_storage_folders_file" symlink_storage_folders_file
run_task "update_permissions_bash_scripts" update_permissions_bash_scripts
run_task "update_permissions_api_keys" update_permissions_api_keys
run_task "update_permissions_env" update_permissions_env
run_task "setup_stats" setup_stats

echo "Deployment completed successfully!"
