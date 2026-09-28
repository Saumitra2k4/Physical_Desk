<?php
function plugin_pd57portal_install(): bool {
    require_once __DIR__ . '/inc/admin.php';
    return pd57_admin_install_schema();
}
function plugin_pd57portal_uninstall(): bool { return true; }
