#!/bin/bash

# Build script for Orchestra Projects plugin
# Creates a distributable zip file for WordPress plugin installation

set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

cd "$(dirname "$0")"

# Get plugin version from main plugin file
PLUGIN_FILE="orchestra-projects.php"
VERSION=$(grep "Version:" "$PLUGIN_FILE" | head -n 1 | sed -E 's/.*Version:[[:space:]]*([0-9.]+).*/\1/')
if [ -z "$VERSION" ]; then
    echo -e "${RED}Error: Could not extract version from $PLUGIN_FILE${NC}"
    exit 1
fi

if ! command -v composer > /dev/null; then
    echo -e "${RED}Error: composer is required to build the plugin.${NC}"
    exit 1
fi

BUILD_DIR="build"
PLUGIN_NAME="orchestra-projects"
ZIP_NAME="${PLUGIN_NAME}-${VERSION}.zip"
DIST_DIR="${BUILD_DIR}/${PLUGIN_NAME}"

echo -e "${YELLOW}Building Orchestra Projects v${VERSION}${NC}"
echo ""

rm -rf "$BUILD_DIR"
mkdir -p "$DIST_DIR"

echo "Copying plugin files..."
cp "$PLUGIN_FILE" uninstall.php README.md LICENSE composer.json composer.lock "$DIST_DIR/"
cp -r src "$DIST_DIR/"

# Install production dependencies only, exactly as locked
echo "Installing production dependencies..."
composer install \
    --working-dir="$DIST_DIR" \
    --no-dev \
    --no-interaction \
    --no-progress \
    --classmap-authoritative \
    --quiet

# Composer metadata is not needed at runtime
rm "$DIST_DIR/composer.json" "$DIST_DIR/composer.lock"

echo "Creating zip file..."
(cd "$BUILD_DIR" && zip -r -q "$ZIP_NAME" "$PLUGIN_NAME" -x "*.DS_Store")

ZIP_SIZE=$(du -h "$BUILD_DIR/$ZIP_NAME" | cut -f1)

echo ""
echo -e "${GREEN}✓ Build successful!${NC}"
echo ""
echo -e "Plugin: ${YELLOW}${PLUGIN_NAME}${NC}"
echo -e "Version: ${YELLOW}${VERSION}${NC}"
echo -e "Location: ${YELLOW}${BUILD_DIR}/${ZIP_NAME}${NC}"
echo -e "Size: ${YELLOW}${ZIP_SIZE}${NC}"
