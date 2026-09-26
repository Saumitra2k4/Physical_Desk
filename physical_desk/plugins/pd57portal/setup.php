<?php
define('PD57_PORTAL_VERSION', '0.1.0');
function plugin_version_pd57portal(): array {
    return ['name' => 'Physical Desk Portal', 'version' => PD57_PORTAL_VERSION,
        'author' => 'Physical Desk Engineering', 'license' => 'GPLv3', 'homepage' => '',
        'requirements' => ['glpi' => ['min' => '11.0']]];
}
function plugin_pd57portal_check_prerequisites(): bool { return true; }
function plugin_pd57portal_check_config(bool $verbose = false): bool { return true; }
function plugin_init_pd57portal(): void {
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['pd57portal'] = true;
}
