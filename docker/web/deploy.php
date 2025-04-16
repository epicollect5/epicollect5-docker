<?php

/** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */
/** @noinspection PhpUnhandledExceptionInspection */

/*
*************************************
// Migrated to Deployer 7.x for Laravel 11
*************************************
*/

namespace Deployer;

use Throwable;

require 'recipe/laravel.php';

// Configuration
set('repository', 'https://github.com/epicollect5/epicollect5-server.git');
set('ssh_multiplexing', true);
set('keep_releases', 3);
set('default_timeout', 7200);

// Ensure .env is properly set in shared_files
add('shared_files', ['public/.htaccess']);

//we need sudo to be able to set ACL when not the owner of the files
//for example, on legacy volumes with www-data:www-data
set('writable_use_sudo', false);

set('writable_dirs', [
    'bootstrap/cache',
    'storage',
    'storage/app',
    'storage/app/projects',
    'storage/app/temp',
    'storage/framework',
    'storage/framework/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
]);

// List of dirs what will be shared between releases.
// Each release will have symlink to those dirs stored in {{deploy_path}}/shared dir.
set('shared_dirs', ['storage']);

//MYSQL user for the epicollect5 app
define('DB_USERNAME', 'epicollect5_server');
define('DB_NAME', 'epicollect5_prod');

function updatePermissionsApiKeys(): void
{
    $keysPath = '{{deploy_path}}/shared/storage';

    // Get the Apache or Nginx user dynamically
    $httpUser = run('ps aux | grep -E "(apache|nginx)" | grep -v root | head -n 1 | awk \'{print $1}\'');
    $httpUser = trim($httpUser); // Clean up any extra whitespace

    if (empty($httpUser)) {
        writeln('<error>Unable to determine the HTTP server user.<error>');
        exit(1);
    }

    // Change ownership of the keys to the HTTP server user
    run("sudo chown $httpUser:$httpUser $keysPath/oauth-private.key");
    run("sudo chown $httpUser:$httpUser $keysPath/oauth-public.key");

    // Set appropriate permissions for the keys
    run("sudo chmod 600 $keysPath/oauth-private.key");
    run("sudo chmod 644 $keysPath/oauth-public.key");

    writeln('<info>Passport keys permissions updated.<info>');
}

task('setup:check_clean_install', function () {
    $deployPath = get('deploy_path');
    // Define the release path (usually the current release is a symlink to the most recent release)
    $currentReleasePath = $deployPath . '/current';
    // Check if the current release path is a symlink
    $isSymlink = run("test -L $currentReleasePath && echo 'true' || echo 'false'");
    if ($isSymlink === 'true') {
        writeln('<error>A release already exists. Skipping install.</error>');
        // Abort the deployment
        exit(1);
    }
});

task('setup:symlink_deploy_file', function () {
    // Path to the current release's deploy.php file
    $currentDeployFile = 'current/deploy.php';
    // Path where the symlink will be created, adjust it as needed
    $deploySymlinkPath = '{{deploy_path}}/deploy.php';
    // Create a symlink pointing to the latest deploy.php
    run("ln -sf $currentDeployFile $deploySymlinkPath");

    writeln('Symlink to the latest deploy.php has been created.');
});

task('setup:symlink_laravel_storage_folders_file', function () {
    // Path to the current release's deploy.php file
    $currentDeployFile = 'current/laravel_storage_folders.sh';
    // Path where the symlink will be created, adjust it as needed
    $deploySymlinkPath = '{{deploy_path}}/laravel_storage_folders.sh';
    // Create a symlink pointing to the latest deploy.php
    run("ln -sf $currentDeployFile $deploySymlinkPath");

    // Ensure the file is executable
    run("sudo chmod +x $deploySymlinkPath");

    writeln('Symlink to the latest laravel_storage_folders.sh has been created and made executable.');
});

//Ensure the cache folders exist and set correct permissions
task('setup:cache_folders', function () {
    $httpUser = trim(run('ps aux | egrep "(apache|nginx)" | grep -v root | head -n 1 | awk \'{print $1}\''));
    run("sudo mkdir -p {{release_path}}/bootstrap/cache");
    run("sudo chown -R {$httpUser}:{$httpUser} {{release_path}}/bootstrap/cache");
    run("sudo chmod -R 775 {{release_path}}/bootstrap/cache");

    run("sudo mkdir -p {{release_path}}/storage/framework/cache/data");
    run("sudo chown -R {$httpUser}:{$httpUser} {{release_path}}/storage/framework/cache/data");
    run("sudo chmod -R 775 {{release_path}}/storage/framework/cache/data");

    writeln('<info>storage/framework/cache/data created (or ignored if existing) successfully.</info>');
    writeln('<info>bootstrap/cache created (or ignored if existing) successfully.</info>');
});

task('setup:database', function () {
    writeln('<info>Starting database setup with verbose output...</info>');

    // Constants for username and database name
    $dbUsername = DB_USERNAME;
    $dbName = DB_NAME;

    writeln("<comment>Using database name: $dbName and username: $dbUsername</comment>");

    // Generate a random password with at least one uppercase, lowercase, and number
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $numbers = '0123456789';
    $allChars = $lowercase . $uppercase . $numbers;

    // Ensure at least one of each required character
    $dbPassword = $lowercase[random_int(0, strlen($lowercase) - 1)] .
        $uppercase[random_int(0, strlen($uppercase) - 1)] .
        $numbers[random_int(0, strlen($numbers) - 1)];

    // Fill the rest of the password length with random characters
    for ($i = 0; $i < 9; $i++) {
        $dbPassword .= $allChars[random_int(0, strlen($allChars) - 1)];
    }

    // Shuffle the password to ensure randomness
    $dbPassword = str_shuffle($dbPassword);
    // Save the password for use in the next task
    set('dbPassword', $dbPassword);

    writeln("<info>Generated password for '$dbUsername', saving to .env</info>");

    // Write SQL commands to a file
    $sqlFile = "/tmp/db_setup.sql";

    // Debug: Show environment information
    writeln("<comment>DEBUG: Current working directory: " . run('pwd') . "</comment>");
    writeln("<comment>DEBUG: User running script: " . run('whoami') . "</comment>");
    writeln("<comment>DEBUG: Environment variables: </comment>");
    run('env | sort', ['real_time_output' => true]);

    // Check if we're in a Docker environment using multiple methods
    $isDockerEnv = run('[ -f /.dockerenv ] && echo true || echo false');
    writeln("<comment>Docker environment check (/.dockerenv): $isDockerEnv</comment>");

    // If that didn't work, try another method
    if ($isDockerEnv !== 'true') {
        $isDockerEnv = run('grep -q docker /proc/1/cgroup && echo true || echo false');
        writeln("<comment>Docker environment check (/proc/1/cgroup): $isDockerEnv</comment>");
    }

    // Check for Docker-specific environment variables
    $dockerEnvVar = getenv('DOCKER_ENV') ?: 'not_set';
    writeln("<comment>DOCKER_ENV environment variable: $dockerEnvVar</comment>");

    // If any of the checks indicate Docker, set isDockerEnv to true
    if ($isDockerEnv !== 'true' && $dockerEnvVar === 'true') {
        $isDockerEnv = 'true';
        writeln("<comment>Docker environment detected via DOCKER_ENV variable</comment>");
    }

    // In Docker environment, use 'db' as the host instead of 'localhost'
    $dbHost = $isDockerEnv === 'true' ? 'db' : 'localhost';
    writeln("<comment>Using database host: $dbHost (Docker env: $isDockerEnv)</comment>");

    // Debug: Check MySQL connectivity
    writeln("<comment>DEBUG: Testing MySQL connectivity...</comment>");
    try {
        run("which mysql", ['real_time_output' => true]);
        writeln("<comment>DEBUG: MySQL client found</comment>");
    } catch (\Throwable $e) {
        writeln("<error>DEBUG: MySQL client not found: " . $e->getMessage() . "</error>");
    }

    // Debug: Check if MySQL is running
    writeln("<comment>DEBUG: Checking if MySQL service is running...</comment>");
    try {
        run("ps aux | grep mysql | grep -v grep", ['real_time_output' => true]);
    } catch (\Throwable $e) {
        writeln("<comment>DEBUG: MySQL process check failed: " . $e->getMessage() . "</comment>");
    }

    // Create SQL commands
    run("echo \"CREATE USER IF NOT EXISTS '$dbUsername'@'%' IDENTIFIED BY '$dbPassword';\" > $sqlFile");
    run("echo \"ALTER USER '$dbUsername'@'%' IDENTIFIED BY '$dbPassword';\" >> $sqlFile");
    run("echo \"GRANT USAGE ON *.* TO '$dbUsername'@'%';\" >> $sqlFile");
    run("echo \"CREATE DATABASE IF NOT EXISTS $dbName;\" >> $sqlFile");
    run("echo \"GRANT ALL PRIVILEGES ON $dbName.* TO '$dbUsername'@'%';\" >> $sqlFile");
    run("echo \"FLUSH PRIVILEGES;\" >> $sqlFile");

    writeln("<comment>SQL commands prepared in $sqlFile</comment>");
    run("cat $sqlFile", ['real_time_output' => true]);

    // Connect to MySQL using the appropriate host
    if ($isDockerEnv === 'true') {
        writeln("<comment>Attempting to connect to MySQL in Docker environment...</comment>");

        // Debug: Check if db host is reachable
        writeln("<comment>DEBUG: Checking if db host is reachable...</comment>");
        try {
            run("ping -c 1 db", ['real_time_output' => true]);
        } catch (\Throwable $e) {
            writeln("<comment>DEBUG: Cannot ping db host: " . $e->getMessage() . "</comment>");
        }

        // In Docker, try different authentication methods
        try {
            // First try without password (auth socket might work)
            writeln("<comment>Trying connection without password...</comment>");
            run("cat $sqlFile | mysql -vvv -h$dbHost -uroot", ['real_time_output' => true]);
            writeln('<info>Connected to MySQL without password (auth socket).</info>');
        } catch (\Throwable $e) {
            writeln('<comment>Auth socket connection failed: ' . $e->getMessage() . '</comment>');
            writeln('<comment>Trying with password...</comment>');
            // If that fails, try with environment variable
            // Full path to the source .env file from your Docker repo
            $sourceEnv = '/var/www/docker/.env';  // Adjust the path as needed
            // Get the MYSQL_ROOT_PASSWORD value from the source .env file
            $rootPassword = run("grep -E '^MYSQL_ROOT_PASSWORD=' {$sourceEnv} | cut -d'=' -f2");
            writeln("<comment>Using MYSQL_ROOT_PASSWORD environment variable or default</comment>");
            try {
                run("cat $sqlFile | mysql -vvv -h$dbHost -uroot -p$rootPassword", ['real_time_output' => true]);
                writeln('<info>Connected to MySQL with root password.</info>');
            } catch (\Throwable $e) {
                writeln('<error>Failed to connect with password: ' . $e->getMessage() . '</error>');
                throw $e;
            }
        }
    } else {
        // On traditional servers, try multiple connection methods
        writeln("<comment>DEBUG: Checking MySQL socket location...</comment>");
        try {
            run("ls -la /var/run/mysqld/", ['real_time_output' => true]);
        } catch (\Throwable $e) {
            writeln("<comment>DEBUG: Cannot check MySQL socket: " . $e->getMessage() . "</comment>");
        }

        // Try without sudo first
        try {
            writeln("<comment>Trying to connect to MySQL without sudo...</comment>");
            run("cat $sqlFile | mysql -vvv", ['real_time_output' => true]);
            writeln('<info>Connected to MySQL without sudo.</info>');
        } catch (\Throwable $e) {
            writeln('<comment>Connection without sudo failed: ' . $e->getMessage() . '</comment>');

            // Try with host parameter
            try {
                writeln("<comment>Trying with explicit host parameter...</comment>");
                run("cat $sqlFile | mysql -vvv -h$dbHost", ['real_time_output' => true]);
                writeln('<info>Connected to MySQL with explicit host.</info>');
            } catch (\Throwable $e) {
                writeln('<comment>Connection with explicit host failed: ' . $e->getMessage() . '</comment>');

                // Try with sudo
                writeln('<comment>Trying with sudo...</comment>');
                try {
                    run("cat $sqlFile | sudo mysql -vvv", ['real_time_output' => true]);
                    writeln('<info>Connected to MySQL with sudo.</info>');
                } catch (\Throwable $e) {
                    writeln('<error>Failed to connect with sudo: ' . $e->getMessage() . '</error>');

                    // Try with TCP connection
                    writeln('<comment>Trying with TCP connection...</comment>');
                    try {
                        run("cat $sqlFile | mysql -vvv -h127.0.0.1 -P3306 -uroot", ['real_time_output' => true]);
                        writeln('<info>Connected to MySQL via TCP.</info>');
                    } catch (\Throwable $e) {
                        writeln('<error>All connection attempts failed: ' . $e->getMessage() . '</error>');
                        throw $e;
                    }
                }
            }
        }
    }

    // Clean up the SQL file
    run("rm $sqlFile");

    writeln('<info>MySQL user and database created successfully.</info>');
});

// Task to update the .env file
task('setup:env', function () {

    // Save the password for use in the next task
    $dbPassword = get('dbPassword');

    // Constants for username and database name
    $dbUsername = DB_USERNAME;
    $dbName = DB_NAME;

    // Resolve the deploy path and current path
    $sharedEnvFile = get('deploy_path') . '/shared/.env';
    $sharedHTAccessFile = get('deploy_path') . '/shared/public/.htaccess';
    $currentEnvExample = get('release_path') . '/.env.example';
    $currentHTAccessExample = get('release_path') . '/public/.htaccess-example';

    run("cp $currentEnvExample $sharedEnvFile");
    writeln('.env file copied from .env.example.');

    run("cp $currentHTAccessExample $sharedHTAccessFile");
    writeln('.htaccess file copied from public/.htaccess-example.');

    // Update the .env file
    $envContent = file_get_contents($sharedEnvFile);
    $envContent = preg_replace(
        '/^DB_DATABASE=.*/m',
        "DB_DATABASE=$dbName",
        $envContent
    );
    $envContent = preg_replace(
        '/^DB_USERNAME=.*/m',
        "DB_USERNAME=$dbUsername",
        $envContent
    );
    $envContent = preg_replace(
        '/^DB_PASSWORD=.*/m',
        "DB_PASSWORD=$dbPassword",
        $envContent
    );

    //replace help text
    $envContent = preg_replace(
        '/^#key below is an example to make php artisan key:generate works.*/m',
        '',
        $envContent
    );

    file_put_contents($sharedEnvFile, $envContent);

    writeln('.env file updated successfully.');
});

task('setup:alerts', function () {
    // Get system email from environment variable
    $email = getenv('SYSTEM_EMAIL') ?: 'alerts@example.com';

    writeln("<info>Using system email from environment variable: $email</info>");

    // Update the .env file
    $sharedEnvFile = get('deploy_path') . '/shared/.env';
    $envContent = file_get_contents($sharedEnvFile);

    $envContent = preg_replace(
        '/^SYSTEM_EMAIL=.*/m',
        "SYSTEM_EMAIL=$email",
        $envContent
    );

    file_put_contents($sharedEnvFile, $envContent);

    writeln('<info>.env file updated successfully with system email.</info>');
});


task('setup:superadmin', function () {
    // Get superadmin credentials from environment variables
    $email = getenv('SUPER_ADMIN_EMAIL') ?: 'admin@example.com';
    $name = getenv('SUPER_ADMIN_FIRST_NAME') ?: 'Admin';
    $surname = getenv('SUPER_ADMIN_LAST_NAME') ?: 'User';
    $password = getenv('SUPER_ADMIN_PASSWORD') ?: 'AdminPassword123!';

    writeln("<info>Using superadmin credentials from environment variables</info>");
    writeln("Email: $email");
    writeln("Name: $name $surname");

    // Update the .env file
    $sharedEnvFile = get('deploy_path') . '/shared/.env';
    $envContent = file_get_contents($sharedEnvFile);
    $envContent = preg_replace(
        '/^SUPER_ADMIN_FIRST_NAME=.*/m',
        "SUPER_ADMIN_FIRST_NAME=$name",
        $envContent
    );
    $envContent = preg_replace(
        '/^SUPER_ADMIN_LAST_NAME=.*/m',
        "SUPER_ADMIN_LAST_NAME=$surname",
        $envContent
    );

    $envContent = preg_replace(
        '/^SUPER_ADMIN_EMAIL=.*/m',
        "SUPER_ADMIN_EMAIL=$email",
        $envContent
    );

    $envContent = preg_replace(
        '/^SUPER_ADMIN_PASSWORD=.*/m',
        "SUPER_ADMIN_PASSWORD=$password",
        $envContent
    );

    file_put_contents($sharedEnvFile, $envContent);

    writeln('<info>.env file updated successfully with superadmin credentials.</info>');
});

task('setup:passport:keys', function () {
    // Run artisan passport:keys to generate the keys
    run('cd {{deploy_path}}/current && {{bin/php}} artisan passport:keys');

    writeln('Passport keys generated.');

    //Update keys permissions
    updatePermissionsApiKeys();
});


task('setup:update_permissions:api_keys', function () {
    updatePermissionsApiKeys();
});

task('setup:update_permissions:.env', function () {
    $envPath = '{{deploy_path}}/shared';

    // Get the Apache or Nginx user dynamically
    $httpUser = run('ps aux | grep -E "(apache|nginx)" | grep -v root | head -n 1 | awk \'{print $1}\'');
    $httpUser = trim($httpUser); // Clean up any extra whitespace

    if (empty($httpUser)) {
        writeln('<error>Unable to determine the HTTP server user.<error>');
        exit(1);
    }

    $user = run('whoami');

    // Change ownership of the .env to the deployer user and group to HTTP server
    // user can rw, apache can just read
    run("sudo chown $user:$httpUser $envPath/.env");

    // Set appropriate permissions for the .env file
    run("sudo chmod 640 $envPath/.env");

    writeln('<info>.env permissions updated.<info>');
});

task('setup:update_permissions:bash_scripts', function () {

    //only owner can rwx
    run("sudo chmod 700 {{release_path}}/after_pull-dev.sh");
    run("sudo chmod 700 {{release_path}}/after_pull-prod.sh");
    run("sudo chmod 700 {{release_path}}/laravel_storage_folders.sh");

    writeln('<info>Bash scripts permissions updated.<info>');
});

task('setup:storage:link', function () {
    // Run artisan passport:keys to generate the keys
    run('cd {{deploy_path}}/current && {{bin/php}} artisan storage:link');
    writeln('artisan storage:link executed.');
});

task('setup:key:generate', function () {
    // Run artisan passport:keys to generate the keys
    run('cd {{deploy_path}}/current && {{bin/php}} artisan key:generate');
    writeln('<info>artisan key:generate executed.</info>');
});

task('setup:stats', function () {
    // Get the database credentials
    $dbUsername = DB_USERNAME; // This is 'epicollect5_server'
    $dbName = DB_NAME; // This is 'epicollect5_prod'
    $dbPassword = get('dbPassword', ''); // This was generated in setup:database

    // In Docker environment, use 'db' as the host
    $isDockerEnv = run('[ -f /.dockerenv ] || grep -q docker /proc/1/cgroup && echo true || echo false');
    $dbHost = $isDockerEnv === 'true' ? 'db' : '127.0.0.1';

    writeln("<info>Using database connection: host=$dbHost, name=$dbName, user=$dbUsername</info>");

    // Debug: Show current .env file content (with sensitive info masked)
    writeln("<comment>DEBUG: Current .env file in current directory:</comment>");
    run('grep -v PASSWORD {{deploy_path}}/current/.env | grep DB_', ['real_time_output' => true]);

    // Update .env file directly in the current path
    writeln("<comment>Updating .env file with correct database settings...</comment>");
    run("sed -i 's/^DB_HOST=.*/DB_HOST=$dbHost/' {{deploy_path}}/current/.env");
    run("sed -i 's/^DB_DATABASE=.*/DB_DATABASE=$dbName/' {{deploy_path}}/current/.env");
    run("sed -i 's/^DB_USERNAME=.*/DB_USERNAME=$dbUsername/' {{deploy_path}}/current/.env");

    // Only update password if we have one
    if (!empty($dbPassword)) {
        run("sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=$dbPassword/' {{deploy_path}}/current/.env");
    }

    // Clear config cache
    writeln("<comment>Clearing config cache...</comment>");
    run('cd {{deploy_path}}/current && {{bin/php}} artisan config:clear', ['real_time_output' => true]);

    // Show updated .env file
    writeln("<comment>DEBUG: Updated .env file content:</comment>");
    run('grep -v PASSWORD {{deploy_path}}/current/.env | grep DB_', ['real_time_output' => true]);

    // Run the stats command with environment variables set directly
    writeln("<comment>Running system:stats with direct environment variables...</comment>");
    try {
        $envVars = "DB_HOST=$dbHost DB_DATABASE=$dbName DB_USERNAME=$dbUsername";
        if (!empty($dbPassword)) {
            $envVars .= " DB_PASSWORD=$dbPassword";
        }

        $output = run("cd {{deploy_path}}/current && $envVars {{bin/php}} artisan system:stats --deployer", [
            'timeout' => 300,
            'real_time_output' => true
        ]);
        writeln("<info>Initial system stats executed.</info>");
    } catch (\Throwable $e) {
        writeln("<error>System stats failed: " . $e->getMessage() . "</error>");

        // If we still have issues, try to get more diagnostic information
        writeln("<comment>Getting more diagnostic information...</comment>");
        run("cd {{deploy_path}}/current && {{bin/php}} artisan db:show", ['real_time_output' => true]);

        throw $e;
    }
});

// Production server
localhost('production')
    ->set('deploy_path', '/var/www/html_prod')
    ->set('branch', 'master');

// Tasks
desc('Execute artisan migrate');
task('artisan:migrate', function () {
    $output = run('{{bin/php}} {{release_path}}/artisan migrate --force', [
        'timeout' => 2000, // increasing timeout for long migrations,
        'real_time_output' => false
    ]);
    writeln("<info>$output</info>");

})->once();

desc('Execute artisan migrate:rollback');
task('artisan:migrate:rollback', function () {
    $output = run('{{bin/php}} {{release_path}}/artisan migrate:rollback --force', [
        'timeout' => 2000, // increasing timeout for long migrations
        'real_time_output' => false
    ]);
    writeln("<info>$output</info>");
})->once();

desc('Execute artisan migrate:status');
task('artisan:migrate:status', function () {
    run('{{bin/php}} {{release_path}}/artisan migrate:status');
})->once();


task('artisan:about', function () {
    run('cd {{deploy_path}}/current && {{bin/php}} artisan about', [
        'real_time_output' => true
    ]);
});

task('composer:dump-autoload', function () {
    run('cd {{deploy_path}}/current && {{bin/composer}} dump-autoload -o', [
        'real_time_output' => true
    ]);
});

task('setup:merge_overrides', function () {
    $sourceEnv = '/var/www/docker/.env';
    $sharedEnvPath = '{{deploy_path}}/shared/.env';

    writeln("<comment>→ Merging keys from Docker .env into shared/.env</comment>");

    $keys = [
        'API_RATE_LIMIT_ENTRIES',
        'API_RATE_LIMIT_MEDIA',
        'API_RATE_LIMIT_PROJECT',
        'APP_ENV',
        'APP_LOG',
        'APP_LOG_LEVEL',
        'APP_LOG_MAX_FILES',
        'APP_NAME',
        'APP_URL',
        'BULK_DELETION_CHUNK_SIZE',
        'JWT_EXPIRE',
        'JWT_FORGOT_EXPIRE',
        'JWT_PASSWORDLESS_EXPIRE',
        'LOG_CHANNEL',
        'MAIL_ENCRYPTION',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'MAIL_HOST',
        'MAIL_MAILER',
        'MAIL_PASSWORD',
        'MAIL_PORT',
        'MAIL_USERNAME',
        'MAILGUN_DOMAIN',
        'MAILGUN_ENDPOINT',
        'MAILGUN_SECRET',
        'MAILGUN_ZONE',
        'OPENCAGE_ENDPOINT',
        'OPENCAGE_KEY',
        'PASSWORDLESS_TOKEN_EXPIRES_IN',
        'PHPINFO_ENABLED',
        'RESPONSE_DELAY_MEDIA_REQUEST',
        'RESPONSE_DELAY_UPLOAD_REQUEST',
        'SESSION_EXPIRE',
        'SESSION_SAME_SITE',
        'SESSION_SECURE_COOKIE',
        'STORAGE_AVAILABLE_MIN_THRESHOLD',
        'SUPER_ADMIN_EMAIL',
        'SUPER_ADMIN_FIRST_NAME',
        'SUPER_ADMIN_LAST_NAME',
        'SUPER_ADMIN_PASSWORD',
        'SYSTEM_EMAIL',
    ];

    foreach ($keys as $key) {
        $value = run("grep -E '^{$key}=' {$sourceEnv} | tail -n1 || true");

        if (!empty($value)) {
            $escapedValue = str_replace(['/', '&'], ['\/', '\&'], $value);

            // Update or append the value
            run("grep -q '^{$key}=' {$sharedEnvPath} && sed -i 's/^{$key}=.*/{$escapedValue}/' {$sharedEnvPath} || echo '{$value}' >> {$sharedEnvPath}");

            // Log the applied value
            writeln("  - <info>{$value}</info>");
        } else {
            writeln("  - <fg=yellow>{$key} not found in Docker .env, skipping</>");
        }
    }

    // Fetch latest tag
    writeln("<comment>→ Fetching latest tag from master branch of repo</comment>");
    $repo = get('repository');
    $tmpDir = '/tmp/latest-tag-check';

    run("rm -rf {$tmpDir} && git clone --quiet --depth=1 --branch=master {$repo} {$tmpDir}");
    $latestTag = run("cd {$tmpDir} && git fetch --tags && git describe --tags \$(git rev-list --tags --max-count=1)");
    run("rm -rf {$tmpDir}");

    $version = trim($latestTag);
    $release = str_replace('.', '', $version);

    //Authentication only via email
    run("grep -E '^#?AUTH_METHODS=' {$sharedEnvPath} && sed -i 's/^#\\?AUTH_METHODS=.*/AUTH_METHODS=passwordless/' {$sharedEnvPath} || echo 'AUTH_METHODS=passwordless' >> {$sharedEnvPath}");

    //DB Host
    run("grep -E '^#?DB_HOST=' {$sharedEnvPath} && sed -i 's/^#\\?DB_HOST=.*/DB_HOST=db/' {$sharedEnvPath} || echo 'DB_HOST=db' >> {$sharedEnvPath}");

    // Apply release values
    run("grep -q '^RELEASE=' {$sharedEnvPath} && sed -i 's/^RELEASE=.*/RELEASE={$release}/' {$sharedEnvPath} || echo 'RELEASE={$release}' >> {$sharedEnvPath}");
    run("grep -q '^PRODUCTION_SERVER_VERSION=' {$sharedEnvPath} && sed -i 's/^PRODUCTION_SERVER_VERSION=.*/PRODUCTION_SERVER_VERSION={$version}/' {$sharedEnvPath} || echo 'PRODUCTION_SERVER_VERSION={$version}' >> {$sharedEnvPath}");

    writeln("  - <info>RELEASE={$release}</info>");
    writeln("  - <info>PRODUCTION_SERVER_VERSION={$version}</info>");

    writeln("<info>shared/.env updated successfully</info>");
});

task('artisan:down', function () {
    $currentPath = run('readlink /var/www/html_prod/current');
    writeln("Current symlink points to: {$currentPath}");
    run("cd {$currentPath} && php artisan down");
});

// This will unlock the deployment (remove .deploy.lock file)
task('deploy:unlock', function () {
    if (test('[ -f {{deploy_path}}/.deploy.lock ]')) {
        run('rm -f {{deploy_path}}/.deploy.lock');
    }
});

desc('Update Epicollect5 to a new release');
task('update', [
    'check:not_root',
    'artisan:down',
    'deploy:prepare',
    'deploy:vendors',
    'deploy:publish',
    'setup:symlink_deploy_file',
    'setup:symlink_laravel_storage_folders_file',
    'setup:update_permissions:bash_scripts',
    'setup:update_permissions:api_keys',
    'setup:update_permissions:.env',
    'setup:merge_overrides',
    'setup:after_pull',
    'artisan:migrate',
    'artisan:up',
    'install:success'
]);

// Task to check if running as root
task('check:not_root', function () {
    $user = run('whoami');
    if ($user === 'root') {
        writeln('<error>Deployment must not be run as root. Aborting.</error>');
        exit(1);
    }
});

task('setup:after_pull', function () {
    $scriptPath = '{{deploy_path}}/current/after_pull-prod.sh';

    // Ensure the script is executable
    run("chmod +x {$scriptPath}");

    // Run the script and capture the output
    $output = run("cd {{deploy_path}}/current && ./after_pull-prod.sh");

    // Display the output in the deploy logs
    writeln("<info>Output of after_pull-prod.sh:</info>\n" . $output);
});

task('setup:ensure_env_symlink', function () {
    $command = 'ln -nfs {{deploy_path}}/shared/.env {{deploy_path}}/current/.env';
    run("echo 'Running command: $command'");
    run($command);
});

desc('Install Epicollect5 release from scratch');
task('install:success', function () {
    writeln("<info>🎉 Epicollect5 installation completed successfully!</info>");
});
task('install', [
    'check:not_root',
    'setup:check_clean_install',
    'deploy:prepare',
    'deploy:vendors',
    'deploy:publish',
    'setup:database',
    'setup:env',
    'setup:key:generate',
    'setup:storage:link',
    'setup:passport:keys',
    'setup:superadmin',
    'setup:alerts',
    'setup:symlink_deploy_file',
    'setup:symlink_laravel_storage_folders_file',
    'setup:update_permissions:bash_scripts',
    'setup:update_permissions:api_keys',
    'setup:update_permissions:.env',
    'setup:merge_overrides',
    'setup:after_pull',
    'artisan:migrate',
    'setup:stats',
    'artisan:up',
    'install:success'
]);
// If deploy fails automatically unlock.
after('deploy:failed', 'deploy:unlock');




