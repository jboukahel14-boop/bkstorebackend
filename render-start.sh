#!/usr/bin/env bash
set -e

echo "Starting Laravel application deployment on Render..."

# Cache configuration & routes for production speed
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run database migrations
php artisan migrate --force

# Seed categories and Algerian wilayas if needed
php artisan db:seed --class=AlgeriaWilayasSeeder --force || true
php artisan db:seed --class=CategoriesSeeder --force || true

# Serve the application
echo "Starting Apache / PHP server on port $PORT..."
apache2-foreground
