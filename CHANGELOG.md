# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.1.1]

### Added

- `.github/workflows/tests.yml`: GitHub Actions CI running the full PHPUnit suite against a freshly-installed GLPI 11.0.6 on every push/PR — checks out GLPI, builds its JS/CSS bundles and compiles its `.po` files, installs its database, links this plugin in, installs/activates it, then runs the suite for real.
- `plugin.xml` and `plugin-icon.png`: marketplace-style distribution metadata (name, description in EN/ES, per-version compatibility, tags), modeled on the `credit` plugin's own `plugin.xml`.

### Fixed

- The "Risk Management" card in the config form said "Coming in v1.1.0"; 1.1.0 shipped without it, since Risk Management is a different PMBOK knowledge area than Schedule Management (this plugin's actual scope) with no defined build plan. Swapped for a generic "Planned" badge that doesn't commit to a release it doesn't have.

### Removed

- `module_dashboard` and `module_evm`: config columns that existed in the schema and were sanitized on every save, but had no UI card and nothing ever gated on them — dead scaffolding for modules that were never built. Migrated via `Migration::dropField()` for existing installs.

## [1.1.0]

### Added

- Critical path (`CriticalPath`): a "Critical path" tab on `Project` running the standard CPM forward/backward pass (early/late start/finish, float) over the dependency graph, honoring FS/SS/FF/SF and lag on both passes. Tasks with zero float are flagged critical. No new table — it's a pure computation over `TaskDependency`'s existing data, reusing its right rather than adding a new one to configure.
- A PHPUnit test suite (25 tests): dependency cycle detection/rejection, the cascade engine's date math per dependency type, baseline capture and variance, opt-in blocking (including the `SS` "only needs the predecessor started" case), critical-path float computation (linear chain, parallel branch with slack, isolated task, cycle handling), and `Config`'s default-value fallback. GLPI 11's release build ships neither `tests/` nor PHPUnit, so this plugin bootstraps its own — see the Testing section in README.md.

### Changed

- `TaskDependency::buildDependencyGraph()` extracted from `rescheduleProject()` (task/dependency loading, adjacency maps, Kahn's topological sort and cycle detection) so `CriticalPath` reuses the exact same graph-building and cycle-safety logic instead of duplicating it. `durationSeconds()` made public for the same reason. Behavior-preserving — `rescheduleProject()`'s own tests are unchanged and still pass.

### Fixed

Found while writing the test suite:

- `TaskDependency::rescheduleProject()` called `Toolbox::logError()`, which does not exist in GLPI 11 (`Toolbox` only exposes `logDebug()`/`logInfo()`/`logInFile()`). The cycle-detection safety net would fatal instead of returning the "cascade aborted" error the moment it ever actually caught a cycle. Switched to `Toolbox::logInFile('php-errors', ...)`.
- `DependencyType` lived inside `TaskDependency.php`, a second class in a file not named after it — violating GLPI 11's strict PSR-4 plugin autoloading. It only worked in production because every access path happened to load `TaskDependency` first; anything autoloading `DependencyType` on its own hit "Class not found". Split into its own `src/DependencyType.php`.

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
