#!/bin/bash

# Define the base directory
BASE_DIR="/var/www/video-ai"

echo "Checking if base directory $BASE_DIR exists..."

# Create the directory structure
if [ ! -d "$BASE_DIR" ]; then
    echo "Creating base directory $BASE_DIR..."
    sudo mkdir -p "$BASE_DIR"
fi

echo "Creating subdirectories..."
sudo mkdir -p "$BASE_DIR/public"
sudo mkdir -p "$BASE_DIR/app"
sudo mkdir -p "$BASE_DIR/storage"
sudo mkdir -p "$BASE_DIR/config"
sudo mkdir -p "$BASE_DIR/views"

# Set permissions
echo "Setting ownership and permissions..."

# Change ownership of the base directory (optional: adjust as needed)
# Here we set the owner to the current user and the group to www-data
sudo chown -R $USER:www-data "$BASE_DIR"

# Set ownership for storage to www-data so Nginx/PHP-FPM can write
sudo chown -R www-data:www-data "$BASE_DIR/storage"

# Set permissions: 775 for storage (owner & group can write)
sudo chmod -R 775 "$BASE_DIR/storage"

# Ensure other directories are readable
sudo chmod -R 755 "$BASE_DIR/public"
sudo chmod -R 755 "$BASE_DIR/app"
sudo chmod -R 755 "$BASE_DIR/config"
sudo chmod -R 755 "$BASE_DIR/views"

echo "Directory structure created successfully at $BASE_DIR"
ls -la "$BASE_DIR"
echo "Permissions for storage folder:"
ls -ld "$BASE_DIR/storage"
