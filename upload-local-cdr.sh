#!/bin/bash

# Helper script to upload CDR files from local Windows directories
# This is for testing purposes when FTP server is not accessible

echo "=========================================="
echo "CDR Local Upload Script"
echo "=========================================="
echo ""
echo "This will upload CDR files from your local computer to the database."
echo ""
echo "Default paths:"
echo "  MMG: /mnt/c/Users/Ahmed Amin Bejoui/Desktop/CDR MMG"
echo "  OCC: /mnt/c/Users/Ahmed Amin Bejoui/Desktop/CDR OCC"
echo ""
echo "Starting upload..."
echo ""

# Run the Laravel command
php artisan cdr:run-local

echo ""
echo "=========================================="
echo "Upload completed!"
echo "=========================================="
