<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Best-effort: when the plugin is checked out inside (or beside) a Grav
// install, pull in Grav core's autoloader too so tests that exercise real
// Grav types (PageInterface, the PSR packages this plugin `replace`s) can
// run. Pure-unit tests don't need this; the Grav-dependent ones skip
// themselves when these classes aren't available.
(static function (): void {
    if (getenv('GRAV_ROOT') && is_file(getenv('GRAV_ROOT') . '/vendor/autoload.php')) {
        require_once getenv('GRAV_ROOT') . '/vendor/autoload.php';
        return;
    }
    // Walk up from the plugin dir looking for a Grav install root
    // (user/plugins/sync → ../../../) or a sibling `grav` checkout.
    $candidates = [
        __DIR__ . '/../../../../vendor/autoload.php', // user/plugins/sync inside a Grav install
        __DIR__ . '/../../grav/vendor/autoload.php',  // sibling grav core checkout
    ];
    foreach ($candidates as $autoload) {
        if (is_file($autoload) && is_dir(dirname($autoload, 2) . '/system/src/Grav')) {
            require_once $autoload;
            return;
        }
    }
})();
