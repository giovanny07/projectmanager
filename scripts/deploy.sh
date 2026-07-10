#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# deploy.sh — Copy projectmanager plugin to GLPI and activate it.
# Usage:
#   bash scripts/deploy.sh              # deploy + install + activate
#   bash scripts/deploy.sh --no-activate # deploy files only (skip GLPI console)
# -----------------------------------------------------------------------------
set -euo pipefail

PLUGIN_KEY="projectmanager"
GLPI_DIR="/var/www/glpi"
PLUGIN_DIR="${GLPI_DIR}/plugins/${PLUGIN_KEY}"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="$(command -v php || true)"

if [[ -z "$PHP" ]]; then
    echo "ERROR: php not found in PATH" >&2
    exit 1
fi

echo "→ Source : ${REPO_DIR}"
echo "→ Target : ${PLUGIN_DIR}"

# Sync — exclude repo metadata, dev tooling and the scripts themselves
rsync -a --delete \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.gitignore' \
    --exclude='scripts' \
    --exclude='tests' \
    --exclude='*.md' \
    --exclude='phpunit.xml' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='vendor' \
    --exclude='dist' \
    "${REPO_DIR}/" "${PLUGIN_DIR}/"

# Fix ownership for the web server process
chown -R apache:apache  "${PLUGIN_DIR}" 2>/dev/null \
  || chown -R www-data:www-data "${PLUGIN_DIR}" 2>/dev/null \
  || true

echo "✓ Files synced"

if [[ "${1:-}" == "--no-activate" ]]; then
    echo "Skipping plugin activation (--no-activate)."
    exit 0
fi

echo "→ Installing plugin via GLPI console"
"${PHP}" "${GLPI_DIR}/bin/console" glpi:plugin:install -u glpi -n --force "${PLUGIN_KEY}"

echo "→ Activating plugin"
"${PHP}" "${GLPI_DIR}/bin/console" glpi:plugin:activate "${PLUGIN_KEY}"

echo "✓ Done — ${PLUGIN_KEY} is installed and active."
