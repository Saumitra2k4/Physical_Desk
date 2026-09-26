<?php
/**
 * PD57 Auth Plugin — Enterprise Email OTP Authentication
 *
 * OTP delivery address is configured per-PD57-account outside Git.
 * Multiple enterprise accounts may share one OTP delivery mailbox.
 * Authorization is derived solely from the authenticated PD57 user account.
 *
 * @package    PD57Auth
 */

use Glpi\Plugin\Hooks;

define("PD57_AUTH_VERSION", "1.0.0");

function plugin_version_pd57auth(): array {
    return [
        "name" => "PD57 Enterprise Auth",
        "version" => PD57_AUTH_VERSION,
        "author" => "Physical Desk Engineering",
        "license" => "GPLv3",
        "homepage" => "",
        "requirements" => ["glpi" => ["min" => "11.0"]],
    ];
}

function plugin_pd57auth_check_prerequisites(): bool { return true; }
function plugin_pd57auth_check_config(bool $verbose = false): bool { return true; }

function plugin_init_pd57auth(): void {
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS["csrf_compliant"]["pd57auth"] = true;
    $PLUGIN_HOOKS[Hooks::POST_INIT]["pd57auth"] = "plugin_pd57auth_post_init";

    // Register the OTP verify page as publicly accessible (no auth required —
    // the user is in a pending pre-auth state during OTP entry).
    \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
        'pd57auth',
        '#^/front/otp_verify\.php$#',
        \Glpi\Http\Firewall::STRATEGY_NO_CHECK
    );

    require_once __DIR__ . "/inc/constants.php";
    require_once __DIR__ . "/hook.php";
    require_once __DIR__ . "/inc/interceptor.php";
}
