# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0]

### Added

- Task dependencies (`TaskDependency`) between `ProjectTask` items: FS, SS, FF, SF types with lead/lag in days, cycle detection (DFS) and a "Dependencies" tab per task.
- Cascade rescheduling engine: topological sort (Kahn's algorithm) over the dependency graph, computing earliest start per dependency type and pushing dependent tasks' planned dates forward. Runs automatically on task save (`cascade_auto`, on by default) or via a manual "Force cascade recalculation" action; optionally logged to GLPI history (`cascade_log`).
- Schedule baseline (`Baseline`): a "Baseline" tab on `Project` to freeze the currently planned dates of every task on demand, and a comparison table showing baseline vs. current plan with variance in days.
- Real dependency blocking (`block_unmet_dependencies`, opt-in, off by default): prevents starting or finishing a task while a predecessor doesn't satisfy the FS/SS/FF/SF relationship, instead of only warning as GLPI core does.
- Milestone tasks (`is_milestone`) are flagged with an icon wherever this plugin lists tasks.
- Plugin configuration page under Setup > General with per-module toggles.

### Fixed

Issues found while auditing and live-testing the plugin against a real GLPI 11.0.6 instance, before this first tagged release:

- Duplicate, stale copies of JS/CSS/AJAX assets across the plugin root, `public/`, and a legacy top-level copy — consolidated onto `public/css`/`public/js` (the only paths GLPI 11 actually serves for `add_css`/`add_javascript`) plus `ajax/`/`front/` at plugin root (grandfathered by GLPI 11 for direct access).
- License mismatch: `setup.php` declared AGPL-3.0-or-later while the repository's `LICENSE` is GPLv3 — aligned to GPL-3.0-or-later throughout.
- `ajax/get_project_tasks.php` leaked raw `$_GET`/`$_POST` in a debug response block, and was missing the 403 status code on access-denied.
- `Plugin::checkPluginState()` was called statically; it's an instance method in GLPI 11 and this was a fatal error that broke every dependency add/purge/reschedule request.
- `front/taskdependency.php` and `front/baseline.php` called `Session::checkCSRF()` explicitly on top of GLPI 11's kernel-level `CheckCsrfListener`, which already validates and consumes the token before the controller runs — the redundant check always failed with a false "action not allowed" 403.
- `hook.php`'s `install()` never registered the plugin's own rights via `ProfileRight::addProfileRights()`, so the Dependencies/Baseline tabs were invisible to every profile, including Super-Admin.
- `ProfileRight::addProfileRights()` is a raw `INSERT` with no existence check; re-running `install()` (e.g. `plugin:install --force`) crashed with a duplicate-key error. Now only rights without any existing row are registered.
- The predecessor/successor `<select>` always failed with 403: the JS read `window.glpiCsrfToken`, which does not exist in GLPI 11. Fixed to read the token from `<meta property="glpi:csrf_token">`, the actual source GLPI 11 exposes to client-side JS.
- None of the plugin's tab classes overrode `getIcon()`, so GLPI's invisible placeholder icon was used everywhere instead of a real one.

### Removed

- Dead code: the AJAX-driven `rescheduleProject()`/`_pmToast()`/`_pmSetBtnLoading()` JS helpers and their associated CSS, never wired to any template (the reschedule button uses a plain form submit) — along with the translation strings that were only referenced from them.
- The per-module "v1.0.0" badge in the config form.
