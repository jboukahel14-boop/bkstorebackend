#!/usr/bin/env bash
set -e

echo "Starting Laravel application deployment on Render..."

# Ensure views directory exists for pure API applications
mkdir -p /var/www/html/resources/views

# Cache configuration & routes for production speed
php artisan config:cache
php artisan route:cache

# Run database migrations
php artisan migrate --force

# Seed categories and Algerian wilayas if needed
php artisan db:seed --class=AlgeriaWilayasSeeder --force || true
php artisan db:seed --class=CategoriesSeeder --force || true

# Serve the application
echo "Starting Apache / PHP server on port $PORT..."
apache2-foreground
