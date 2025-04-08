FROM ubuntu:24.04

# Set noninteractive mode to avoid prompts
ENV DEBIAN_FRONTEND=noninteractive

# Update package list and install necessary dependencies (without MySQL)
RUN apt update && apt install -y \
    apache2 \
    php8.3 \
    php8.3-cli \
    php8.3-mysql \
    php8.3-xml \
    php8.3-mbstring \
    php8.3-curl \
    php8.3-zip \
    php8.3-gd \
    php8.3-bcmath \
    php8.3-common \
    php8.3-pdo \
    php8.3-imagick \
    php-pear \
    libapache2-mod-php8.3 \
    imagemagick \
    unzip \
    curl \
    git \
    brotli \
    ca-certificates \
    certbot \
    python3-certbot-apache \
    acl \
    mysql-client-8.0 \
    --no-install-recommends \
    && apt clean \
    && update-ca-certificates

# Enable Apache modules
RUN a2enmod rewrite
RUN a2enmod headers
RUN a2enmod brotli
RUN a2enmod php8.3
RUN a2enmod ssl

# Create dev user and add to www-data group
RUN useradd -m dev && usermod -aG www-data dev

# Install sudo
RUN apt update && apt install -y sudo

# Add dev user to sudoers with NOPASSWD option for specific commands
RUN echo "dev ALL=(ALL) NOPASSWD: /usr/sbin/service apache2 start, /usr/sbin/service apache2 stop, /usr/sbin/service apache2 restart, /usr/bin/setfacl, /usr/bin/chown, /usr/bin/chmod, /usr/bin/mkdir, /usr/bin/mysql, /usr/bin/mysqldump, /usr/bin/mysql_tzinfo_to_sql" >> /etc/sudoers.d/dev
RUN chmod 0440 /etc/sudoers.d/dev

# Install Git and clone the repository
RUN apt update && apt install -y git \
    && mkdir -p /tmp/epicollect5 \
    && git clone https://github.com/epicollect5/epicollect5-server.git /tmp/epicollect5 \
    && cp -R /tmp/epicollect5/. /var/www/html/ \
    && rm -rf /tmp/epicollect5 \
    && chown -R www-data:www-data /var/www/html

# Set working directory
WORKDIR /var/www/html

# Set ownership of /var/www/html to www-data group and add ACLs
RUN chown -R root:www-data /var/www/html && \
    chmod -R g+rwxs /var/www/html && \
    setfacl -R -m u:dev:rwx,g:www-data:rwx /var/www/html && \
    setfacl -dR -m u:dev:rwx,g:www-data:rwx /var/www/html

# Install Deployer on the fly
RUN curl -LO https://deployer.org/releases/v7.5.12/deployer.phar \
    && chmod +x deployer.phar \
    && mv deployer.phar /usr/local/bin/dep

# Download deploy.php from the repository
RUN curl -o /var/www/html/deploy.php "https://raw.githubusercontent.com/epicollect5/epicollect5-server/refs/heads/master/deploy.php" \
    && chmod +x /var/www/html/deploy.php

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Configure Apache
ENV APACHE_RUN_USER www-data
ENV APACHE_RUN_GROUP www-data
ENV APACHE_LOG_DIR /var/log/apache2
ENV APACHE_RUN_DIR /var/run/apache2
ENV APACHE_LOCK_DIR /var/lock/apache2
ENV APACHE_PID_FILE /var/run/apache2/apache2.pid

# Expose Apache & MySQL ports
EXPOSE 80 3306

# Copy SSL setup script
COPY docker/web/setup-ssl.sh /setup-ssl.sh
RUN chmod +x /setup-ssl.sh

# Start services and run deployment
COPY docker/web/start.sh /start.sh
RUN chmod +x /start.sh

CMD ["/start.sh"]

# Add this to your Dockerfile
RUN apt-get update && apt-get install -y \
    php8.3-dev \
    && pecl install simdjson \
    && echo "extension=simdjson.so" > /etc/php/8.3/cli/conf.d/20-simdjson.ini \
    && echo "extension=simdjson.so" > /etc/php/8.3/apache2/conf.d/20-simdjson.ini \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
