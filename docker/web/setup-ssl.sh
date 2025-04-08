#!/bin/bash

# Check if domain name is provided
if [ -z "$DOMAIN" ]; then
    echo "No domain specified, skipping SSL setup"
    exit 0
fi

# Check if we're in production mode
if [ "$ENVIRONMENT" != "production" ]; then
    echo "Not in production environment, skipping SSL setup"
    exit 0
fi

# Check if certificates already exist
if [ -d "/etc/letsencrypt/live/$DOMAIN" ]; then
    echo "Certificates for $DOMAIN already exist"
else
    echo "Obtaining SSL certificate for $DOMAIN"
    certbot --apache --non-interactive --agree-tos --email admin@$DOMAIN -d $DOMAIN
fi

# Set up automatic renewal
echo "0 0,12 * * * root certbot renew --quiet" > /etc/cron.d/certbot-renew