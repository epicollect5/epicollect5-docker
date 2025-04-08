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

add('shared_files', ['public/.htaccess']);
//we need sudo to be able to set ACL when not the owner of the files
//for example, on legacy volumes with www-data:www-data
set('writable_use_sudo', true);

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
            // If that fails, try with environment variable or default password
            $rootPassword = getenv('MYSQL_ROOT_PASSWORD') ?: 'root_password';
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
    while (true) {
        // Prompt for alert email
        $email = ask('Enter system email for alerts:');

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            writeln('<error>Invalid email format. Please try again.</error>');
            continue; // Restart the task if email is invalid
        }

        // Proceed with the task if inputs are valid
        writeln('<info>Email is valid, saving to .env</info>');

        // Update the .env file
        $sharedEnvFile = get('deploy_path') . '/shared/.env';
        $envContent = file_get_contents($sharedEnvFile);

        $envContent = preg_replace(
            '/^SYSTEM_EMAIL=.*/m',
            "SYSTEM_EMAIL=$email",
            $envContent
        );

        file_put_contents($sharedEnvFile, $envContent);

        writeln('<info>.env file updated successfully.</info>');
        break; // Exit the loop once the task is successfully completed
    }
});


task('setup:superadmin', function () {

    while (true) {
        // Prompt for superadmin email
        $email = ask('Enter superadmin email:');
        // Prompt for superadmin name
        $name = ask('Enter superadmin name:');
        // Prompt for superadmin surname
        $surname = ask('Enter superadmin surname:');

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            writeln('<error>Invalid email format. Please try again.</error>');
            continue; // Restart the task if email is invalid
        }

        // Prompt for superadmin password twice
        $password = askHiddenResponse('Enter superadmin password:');
        $confirmPassword = askHiddenResponse('Confirm superadmin password:');

        // Check if passwords match
        if ($password !== $confirmPassword) {
            writeln('<error>Passwords do not match. Please try again.</error>');
            continue; // Restart the task if passwords don't match
        }

        // Show entered details and ask for confirmation
        writeln("<info>Details entered:</info>");
        writeln("<info>Email:</info> $email");
        writeln("<info>Name:</info> $name");
        writeln("<info>Surname:</info> $surname");

        $confirmation = ask('Do you want to proceed with these details? (yes/no)', true);
        if (strtolower($confirmation) !== 'yes') {
            writeln('<error>Operation aborted by user. Please try again.</error>');
            continue; // Restart the task if user aborts
        }

        // Proceed with the task if inputs are valid and confirmed
        writeln('<info>Superadmin credentials are valid, saving to .env</info>');

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

        writeln('<info>.env file updated successfully.</info>');
        break; // Exit the loop once the task is successfully completed
    }
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
    // Run artisan passport:keys to generate the keys
    run('cd {{deploy_path}}/current && {{bin/php}} artisan system:stats --deployer');
    writeln('<info>Initial system stats executed.</info>');
});

// Production server
localhost('production')
    ->set('deploy_path', '/var/www/html_prod')
    ->set('branch', 'master');

// Tasks
desc('Execute artisan migrate');
task('artisan:migrate', function () {
    $output =  run('{{bin/php}} {{release_path}}/artisan migrate --force', [
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

desc('Execute artisan down with secret');
task('artisan:down_with_secret', function () {
    $output =   run('cd {{deploy_path}}/current && {{bin/php}} artisan down --with-secret', [
        'real_time_output' => true
    ]);
    writeln("<info>$output</info>");
});

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

desc('Update Epicollect5 to a new release');
task('update', [
    'check:not_root',
    'artisan:down_with_secret',
    'deploy:prepare',
    'deploy:vendors',
    'artisan:migrate',
    'artisan:config:cache',
    'artisan:route:cache',
    'artisan:view:cache',
    'deploy:publish',
    'setup:symlink_deploy_file',
    'setup:symlink_laravel_storage_folders_file',
    'setup:update_permissions:bash_scripts',
    'setup:update_permissions:api_keys',
    'setup:update_permissions:.env',
    'composer:dump-autoload',
    'artisan:about'
    // 'artisan:up', // go back online manually after checking all works
]);

// Task to check if running as root
task('check:not_root', function () {
    $user = run('whoami');
    if ($user === 'root') {
        writeln('<error>Deployment must not be run as root. Aborting.</error>');
        exit(1);
    }
});

desc('Install Epicollect5 release from scratch');
try {
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
        'artisan:view:clear',
        'artisan:config:cache',
        'artisan:migrate',
        'setup:symlink_deploy_file',
        'setup:symlink_laravel_storage_folders_file',
        'setup:update_permissions:bash_scripts',
        'setup:update_permissions:api_keys',
        'setup:update_permissions:.env',
        'setup:stats'
    ]);

} catch (Throwable $e) {
    writeln(__METHOD__ . ' failed '. $e->getMessage());
}

// Custom task to display a reminder message
try {
    task('reminder:update_release', function () {
        writeln('<info>App is currently in maintenance mode.</info>');
        writeln('<info>Remember to update the release in .env before running artisan up!</info>');
    });
} catch (Throwable $e) {
    error(__METHOD__ . ' failed '. $e->getMessage());
}

// Hook the custom task to run after the deployment
after('deploy', 'reminder:update_release');
// If deploy fails automatically unlock.
after('deploy:failed', 'deploy:unlock');
//show message if success
after('deploy', 'deploy:success');
