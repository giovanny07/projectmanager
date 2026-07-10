# Scripts inventory

## deploy.sh

**Purpose:** Local/dev-server deployment. Rsyncs the repo directly into the GLPI plugins folder and optionally installs and activates the plugin via the GLPI console. Use this on servers where you have direct filesystem access to the repo.

**Requirements:** `rsync`, `php`, access to the GLPI installation path (hardcoded to `/var/www/glpi`).

**Usage:**
```bash
# Deploy, install, and activate
bash scripts/deploy.sh

# Deploy files only (skip GLPI console activation)
bash scripts/deploy.sh --no-activate
```

**Notes:**
- Excludes `.git`, `.github`, `.gitignore`, `scripts/`, `tests/`, `*.md`, `phpunit.xml`, `composer.*`, `vendor/` and `dist/` from the sync.
- Attempts `chown apache:apache` then `chown www-data:www-data` on the plugin directory.
- Runs `glpi:plugin:install -u glpi -n --force` then `glpi:plugin:activate`.

---

## install.sh

**Purpose:** Remote/curl-based installation for any server. Downloads the versioned zip from S3, auto-detects the GLPI plugins directory, and extracts it in place. This is what end users and production servers use.

**Requirements:** `curl`, `python3`, `unzip`. `sudo` when the plugins directory is not writable by the current user.

**Usage:**
```bash
# Always installs latest
curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/install.sh | bash

# Pin a specific version
curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/install.sh | VERSION=v1.1.1 bash

# Override auto-detected GLPI path
curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/install.sh | PLUGINS_DIR=/var/www/html/glpi/plugins bash

# Uninstall
curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/install.sh | UNINSTALL=true bash
```

**GLPI auto-detection logic:**
1. Checks 8 well-known fixed paths (`/var/www/html/glpi/plugins`, `/opt/glpi/plugins`, etc.).
2. Scans `/var/www`, `/opt`, `/usr/share`, `/srv`, `/app` up to 7 levels deep for any `plugins/` directory whose parent contains `front/central.php` (the reliable GLPI root marker).
3. If exactly one candidate is found, it is used. If multiple are found, the script lists them and asks you to re-run with `PLUGINS_DIR=` set.

---

## release.sh

**Purpose:** Packages the plugin into a zip and publishes it to S3. Run automatically by the GitHub Actions release workflow when a `v*.*.*` tag is pushed. Can also be run locally with valid AWS credentials.

**Requirements:** `aws-cli`, `git`, `zip`, `python3`. AWS credentials with write access to `s3://imagu-binaries/glpi-projectmanager/`.

**Usage:**
```bash
# Via GitHub Actions (automatic on tag push -- preferred)
git tag v1.1.1 && git push origin v1.1.1

# Local run with a named profile
AWS_PROFILE=imagu bash scripts/release.sh v1.1.1
```

**What it does:**
1. Validates the version format (`vX.Y.Z`).
2. Verifies the tag matches `PLUGIN_PROJECTMANAGER_VERSION` in `setup.php` **and** the top-most `<num>` in `plugin.xml`; aborts on drift.
3. Packages `setup.php`, `hook.php`, `src/`, `front/`, `ajax/`, `templates/`, `locales/`, `public/`, `plugin.xml`, `LICENSE` using `git archive` with prefix `projectmanager/`.
4. Uploads the zip to `s3://imagu-binaries/glpi-projectmanager/vX.Y.Z/projectmanager-vX.Y.Z.zip`.
5. Updates `s3://imagu-binaries/glpi-projectmanager/manifest.json` -- adds the version to the sorted `versions` list and sets it as `latest`.
6. Re-uploads `scripts/install.sh` to `s3://imagu-binaries/glpi-projectmanager/install.sh` with no-cache headers.
