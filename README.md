# Project Manager

[![Tests](https://github.com/giovanny07/projectmanager/actions/workflows/tests.yml/badge.svg)](https://github.com/giovanny07/projectmanager/actions/workflows/tests.yml)

Advanced project scheduling for [GLPI](https://glpi-project.org) 11: task dependencies with automatic cascade rescheduling, a schedule baseline to track variance, and optional real enforcement of dependency order — the parts PMI-style schedule management needs that GLPI core's Project/ProjectTask module doesn't cover on its own.

## Requirements

- GLPI 11.0.0 – 11.x
- PHP 8.1+

## Features

- **Task dependencies** — FS, SS, FF, SF relationship types with lead/lag in days, cycle detection, and a dedicated tab on each `ProjectTask`.
- **Cascade rescheduling** — moving a task's planned dates automatically pushes its dependents forward, computed via topological sort over the dependency graph. Runs automatically on task save (configurable) or on demand.
- **Schedule baseline** — freeze a project's currently planned dates on demand, then track schedule variance (in days) as the live plan moves. Lives in its own table, decoupled from `plan_start_date`/`plan_end_date`, so the cascade never overwrites your original commitment.
- **Real dependency blocking** *(opt-in, off by default)* — GLPI core only warns when a predecessor isn't done; this plugin can actually prevent starting/finishing a task until its dependencies are satisfied.
- **Milestones** — tasks flagged `is_milestone` are marked with a flag icon everywhere this plugin lists tasks.

## Installation

**Recommended — one-line installer** (downloads the latest release into your GLPI plugins directory):

```sh
curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/glpi-projectmanager/install.sh | bash
```

It auto-detects the GLPI plugins directory; pass `VERSION=vX.Y.Z` to pin a version or
`PLUGINS_DIR=/path/to/glpi/plugins` for a non-standard layout. See [CICD.md](CICD.md) for all options.

**Manual:** copy (or symlink) this directory into GLPI's plugin directory as `projectmanager`.

Then, in either case:

1. From **Setup > Plugins**, install and activate **Project Manager**.
2. Go to **Setup > Plugins > Project Manager > Configure** to enable the Task Dependencies module and adjust cascade/blocking behavior.

Maintainers: see [CICD.md](CICD.md) for the build/release pipeline and the SemVer tagging procedure.

## Configuration

All settings live under **Setup > General > Project Manager**:

| Setting | Default | Description |
|---|---|---|
| Task Dependencies | off | Master switch for dependencies, cascade, baseline and blocking. |
| Automatic cascade on task save | on | Reschedule dependent tasks automatically when a task's dates change. |
| Log cascade changes in GLPI history | on | Record each cascade-driven date change in the task's history log. |
| Block tasks with unmet dependencies | off | Enforce FS/SS/FF/SF dependencies instead of only warning. |

## Testing

The GLPI 11 release build ships neither `tests/` nor PHPUnit, so this plugin brings its own:

```sh
composer install
GLPI_ROOT=/path/to/glpi vendor/bin/phpunit
```

Tests boot the real GLPI Kernel and run against the real database (the same one GLPI itself uses), so run them as the webserver user (e.g. `sudo -u apache`) — GLPI writes to its own log directory on boot regardless of who's running it. Each test creates its own throwaway Project/ProjectTasks and cleans them up in `tearDown()`.

## Coexisting with other plugins

This plugin does not ship its own Gantt visualization — GLPI core dropped its bundled Gantt view in 10.0.1 (AGPL/GPL license conflict), and visualization today comes from separately-installed plugins such as TECLIB's `gantt`. Project Manager is designed to coexist with it: both add their own tabs on `Project`/`ProjectTask` without conflict.

## License

GPL-3.0-or-later — see [LICENSE](LICENSE).
