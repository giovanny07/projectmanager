# CI/CD

How Project Manager is built, tested, packaged, released, and installed.

## Overview

There are three GitHub Actions workflows and three shell scripts. The flow has three
stages:

```
  push / PR ──▶  CI: tests.yml + package.yml          (correctness + packaging guards)
                       │
  tag vX.Y.Z ──▶  release.yml ──▶ scripts/release.sh   (build zip, publish to S3, update manifest)
                       │
  end user   ──▶  curl … | bash  (scripts/install.sh)  (download from S3 into GLPI plugins dir)
```

Distribution mirrors the sibling `glpi-ticketrouter` plugin: versioned zips live in a private
S3 bucket behind a semver-sorted `manifest.json`, and there is a single self-contained
installer. Releases authenticate to AWS with **OIDC** — no long-lived secrets are stored in
the repository.

## Workflows

| Workflow | Trigger | What it does |
|----------|---------|--------------|
| `.github/workflows/tests.yml` | push to `main`, PRs, manual | Checks out a real GLPI 11.0.6, installs its database, links this plugin in, installs/activates it, and runs the full PHPUnit suite. This is the correctness gate. |
| `.github/workflows/package.yml` | push to `main`, PRs | Two fast guards: **package-verify** builds the release zip with `git archive` and asserts `setup.php`, `hook.php`, `src/`, `ajax/`, `templates/` are present; **version-consistency** asserts the version in `setup.php` equals the top `<num>` in `plugin.xml`. Catches packaging and version-drift problems before a tag. |
| `.github/workflows/release.yml` | push of a `v*.*.*` tag, or manual `workflow_dispatch` | Assumes the AWS role in `vars.AWS_ROLE_ARN` via OIDC and runs `scripts/release.sh <version>`. |

## Distribution (S3)

Bucket layout under `s3://imagu-binaries/glpi-projectmanager/`:

```
  glpi-projectmanager/
  ├── install.sh                                  # the curl|bash installer (always latest)
  ├── manifest.json                               # { "versions": [...], "latest": "vX.Y.Z" }
  ├── v1.1.1/projectmanager-v1.1.1.zip
  ├── v1.2.0/projectmanager-v1.2.0.zip
  └── …
```

- Each zip unpacks to a top-level `projectmanager/` directory, so it drops straight into a
  GLPI `plugins/` folder. It contains only runtime files (`setup.php`, `hook.php`, `src/`,
  `front/`, `ajax/`, `templates/`, `locales/`, `public/`, `plugin.xml`, `LICENSE`) — no
  `tests/`, `.github/`, `scripts/`, `composer.*`, or `vendor/`. GLPI 11 autoloads the
  `GlpiPlugin\Projectmanager\` namespace from `src/` natively and the plugin has no runtime
  dependencies, so no Composer install is needed on the target.
- `manifest.json` is the source of truth for which versions exist and which is `latest`. The
  installer reads it; `release.sh` maintains it (semver-sorted).

### Authentication (OIDC, no secrets)

`release.yml` requests an OIDC token (`permissions: id-token: write`) and assumes the IAM
role named in the repository variable **`vars.AWS_ROLE_ARN`** (region `us-east-1`). That role
must trust this repository's GitHub OIDC provider and have write access to
`s3://imagu-binaries/glpi-projectmanager/*`. Nothing is stored as a GitHub secret.

> **Setup note:** if the role/policy is not yet scoped to this repo, `release.yml` fails at the
> "Configure AWS credentials" step. All local scripts and the CI guards work without it.

## Releasing with SemVer

Versions follow [Semantic Versioning](https://semver.org/): `MAJOR.MINOR.PATCH`.

| Bump | When |
|------|------|
| **MAJOR** (`2.0.0`) | Backward-incompatible change: dropped/renamed config, a schema migration that isn't safe to run against old data, or raising the minimum GLPI version. |
| **MINOR** (`1.2.0`) | New backward-compatible feature — a new module, tab, or config option. |
| **PATCH** (`1.1.2`) | Backward-compatible bug fix only. |

The version lives in **two** places that must agree with the tag (CI and `release.sh` both
enforce this):

- `PLUGIN_PROJECTMANAGER_VERSION` in `setup.php`
- the top-most `<version><num>` in `plugin.xml`

### Release procedure

1. **Bump `setup.php`** — set `PLUGIN_PROJECTMANAGER_VERSION` to the new `X.Y.Z`.
2. **Bump `plugin.xml`** — prepend a new `<version>` block at the top of `<versions>`:
   ```xml
   <version>
      <num>X.Y.Z</num>
      <compatibility>&gt;=11.0.0 &lt;12.0.0</compatibility>
      <download_url>https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/vX.Y.Z/projectmanager-vX.Y.Z.zip</download_url>
   </version>
   ```
3. **Update `CHANGELOG.md`** — move the notes under `## [Unreleased]` into a new dated
   heading `## [X.Y.Z] - YYYY-MM-DD`, and leave a fresh empty `## [Unreleased]` at the top.
4. **Commit** the bump: `git commit -am "Release vX.Y.Z"`.
5. **Tag and push:**
   ```bash
   git tag vX.Y.Z
   git push origin main --tags
   ```
6. The tag fires `release.yml`, which builds and publishes the zip, updates `manifest.json`
   (`latest = vX.Y.Z`), and re-uploads `install.sh`.

To re-run a release manually (e.g. after fixing AWS access), use **Actions → Release → Run
workflow** and enter the version, or run locally with credentials:
`AWS_PROFILE=imagu bash scripts/release.sh vX.Y.Z`.

## Installing

End users install straight from S3 — no clone, no Composer:

```bash
# Latest
curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/install.sh | bash

# Pin a version
curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/install.sh | VERSION=v1.1.1 bash

# Non-standard GLPI location
curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/install.sh | PLUGINS_DIR=/var/www/html/glpi/plugins bash

# Uninstall (removes the plugin directory)
curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/install.sh | UNINSTALL=true bash
```

The installer auto-detects the GLPI plugins directory (well-known paths, then a depth-scan for
a `plugins/` folder next to `front/central.php`), drops `projectmanager/` in place, and prints
the console commands to install/activate it. After it runs, activate the plugin in
**Setup → Plugins → Project Manager**, or via
`php <glpi-root>/bin/console glpi:plugin:install --username=glpi projectmanager` then
`glpi:plugin:activate projectmanager`.

For local development on a server with filesystem access to the repo, use
`bash scripts/deploy.sh` instead (rsync + console install/activate). See
[`scripts/scripts.md`](scripts/scripts.md) for details on all three scripts.
