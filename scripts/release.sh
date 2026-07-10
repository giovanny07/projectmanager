#!/bin/bash
# Package and publish a glpi-projectmanager release to S3.
#
# Usage:
#   ./scripts/release.sh v1.1.1
#   AWS_PROFILE=imagu ./scripts/release.sh v1.1.1
#
# Requires: aws-cli, git, zip, python3.

set -euo pipefail

export AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-us-east-1}"

BUCKET="s3://imagu-binaries/glpi-projectmanager"
PLUGIN_KEY="projectmanager"
VERSION="${1:-}"

if [[ -z "$VERSION" ]]; then
  echo "Usage: $0 <version>  (e.g. v1.1.1)" >&2
  exit 1
fi

if [[ ! "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "ERROR: Version must be in format vX.Y.Z (e.g. v1.1.1)." >&2
  exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# ── verify the version matches the source of truth ─────────────────────────────
# The version lives in two places that must both agree with the tag being
# released: PLUGIN_PROJECTMANAGER_VERSION in setup.php, and the top-most
# <num> in plugin.xml. Catch drift here before anything is checked or uploaded.
NUM="${VERSION#v}"

SETUP_VERSION=$(grep -oP "define\('PLUGIN_PROJECTMANAGER_VERSION',\s*'\K[0-9]+\.[0-9]+\.[0-9]+" "${ROOT}/setup.php" || true)
if [[ "$SETUP_VERSION" != "$NUM" ]]; then
  echo "ERROR: setup.php PLUGIN_PROJECTMANAGER_VERSION is '${SETUP_VERSION:-<none>}', expected '${NUM}'." >&2
  echo "       Bump setup.php to match the tag before releasing." >&2
  exit 1
fi

XML_VERSION=$(grep -oP '<num>\K[0-9]+\.[0-9]+\.[0-9]+' "${ROOT}/plugin.xml" | head -n1 || true)
if [[ "$XML_VERSION" != "$NUM" ]]; then
  echo "ERROR: plugin.xml top <num> is '${XML_VERSION:-<none>}', expected '${NUM}'." >&2
  echo "       Add a matching <version> block at the top of plugin.xml before releasing." >&2
  exit 1
fi

# ── check dependencies ─────────────────────────────────────────────────────────
for cmd in aws git zip python3; do
  if ! command -v "$cmd" &>/dev/null; then
    echo "ERROR: '$cmd' not found in PATH." >&2
    exit 1
  fi
done

if ! aws sts get-caller-identity &>/dev/null; then
  echo "ERROR: AWS credentials not configured." >&2
  echo "" >&2
  echo "  Option 1 -- env vars:      AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=... $0 $VERSION" >&2
  echo "  Option 2 -- named profile: AWS_PROFILE=imagu $0 $VERSION" >&2
  exit 1
fi

# ── package the plugin into a zip ──────────────────────────────────────────────
# git archive ensures only tracked files are included and the archive is
# reproducible from any clean checkout. The --prefix puts everything under
# projectmanager/ so unzipping into a GLPI plugins folder works directly.
#
# Plugin files: setup.php, hook.php, src/, front/, ajax/, templates/, locales/,
#   public/, plugin.xml, LICENSE. GLPI 11 autoloads GlpiPlugin\Projectmanager\
#   from src/ natively and the plugin has no runtime deps, so composer.json /
#   vendor/ are intentionally excluded.
# Excluded automatically: .git/, .github/, scripts/, tests/, README.md,
#   CHANGELOG.md, CICD.md, composer.*, phpunit.xml, .gitignore
echo "Packaging ${PLUGIN_KEY} ${VERSION}..."
mkdir -p "${ROOT}/dist"
ZIP_FILE="${ROOT}/dist/${PLUGIN_KEY}-${VERSION}.zip"

git -C "$ROOT" archive \
  --format=zip \
  --prefix="${PLUGIN_KEY}/" \
  HEAD \
  setup.php \
  hook.php \
  src/ \
  front/ \
  ajax/ \
  templates/ \
  locales/ \
  public/ \
  plugin.xml \
  LICENSE \
  -o "$ZIP_FILE"

SIZE=$(du -sh "$ZIP_FILE" | cut -f1)
echo "  Built:  ${ZIP_FILE}"
echo "  Size:   ${SIZE}"

# ── upload zip ─────────────────────────────────────────────────────────────────
echo "Uploading to ${BUCKET}/${VERSION}/..."
aws s3 cp "$ZIP_FILE" "${BUCKET}/${VERSION}/${PLUGIN_KEY}-${VERSION}.zip" \
  --content-type "application/zip"
echo "  Done: ${PLUGIN_KEY}-${VERSION}.zip"

# ── update manifest ────────────────────────────────────────────────────────────
echo "Updating manifest..."
MANIFEST_TMP=$(mktemp /tmp/manifest.XXXXXX.json)
trap 'rm -f "$MANIFEST_TMP"' EXIT

EXISTING=$(aws s3 cp "${BUCKET}/manifest.json" - 2>/dev/null || echo '{}')
echo "$EXISTING" | python3 -c "
import sys, json

raw = sys.stdin.read().strip() or '{}'
data = json.loads(raw)
versions = data.get('versions', [])

if '$VERSION' not in versions:
    versions.append('$VERSION')

def semver_key(v):
    return [int(x) for x in v.lstrip('v').split('.')]

data['versions'] = sorted(versions, key=semver_key)
data['latest'] = '$VERSION'
print(json.dumps(data, indent=2))
" > "$MANIFEST_TMP"

aws s3 cp "$MANIFEST_TMP" "${BUCKET}/manifest.json" \
  --content-type "application/json" \
  --cache-control "no-cache, no-store"

# ── upload installer script ────────────────────────────────────────────────────
echo "Uploading install.sh..."
aws s3 cp "${ROOT}/scripts/install.sh" "${BUCKET}/install.sh" \
  --content-type "text/x-shellscript" \
  --cache-control "no-cache, no-store"

echo ""
echo "=== Released ${VERSION} ==="
echo ""
echo "Install commands:"
echo "  Latest:"
echo "    curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/install.sh | bash"
echo ""
echo "  Pinned to ${VERSION}:"
echo "    curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/install.sh | VERSION=${VERSION} bash"
echo ""
echo "  Custom plugins directory:"
echo "    curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/install.sh | PLUGINS_DIR=/your/glpi/plugins bash"
