<?php

/**
 * PHPUnit bootstrap for the Project Manager plugin.
 *
 * GLPI 11 ships no tests/ directory or PHPUnit base classes in its
 * release build, and its official plugin-testing docs still describe
 * the old Atoum/PSR-0 approach — this bootstrap is self-contained and
 * only relies on GLPI's own Kernel, the same boot sequence bin/console
 * uses.
 *
 * Run as: GLPI_ROOT=/path/to/glpi vendor/bin/phpunit
 */

$glpiRoot = getenv('GLPI_ROOT');
if (!$glpiRoot) {
    fwrite(STDERR, "GLPI_ROOT environment variable is not set.\n");
    fwrite(STDERR, "Run tests as: GLPI_ROOT=/path/to/glpi vendor/bin/phpunit\n");
    exit(1);
}

chdir($glpiRoot);
require $glpiRoot . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel('production');
$kernel->boot();

// Minimal session context: some of the plugin's own code calls
// Session::getLoginUserID()/addMessageAfterRedirect(), which need a
// session to exist even outside of an HTTP request.
$_SESSION = $_SESSION ?? [];
if (!isset($_SESSION['glpiID'])) {
    global $DB;
    $user = $DB->request([
        'FROM'  => 'glpi_users',
        'WHERE' => ['name' => 'glpi'],
        'LIMIT' => 1,
    ])->current();

    if ($user) {
        $_SESSION['glpiID']   = (int)$user['id'];
        $_SESSION['glpiname'] = $user['name'];
    }
}
