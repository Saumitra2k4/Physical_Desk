<?php
/**
 * PD57 Auth Plugin — OTP Interceptor for front/login.php
 *
 * This file is included by the modified login flow.
 * It intercepts a successful password login, generates an OTP,
 * sends it to the configured delivery mailbox, and redirects
 * to the OTP verification page.
 *
 * The user DOES NOT get a valid GLPI session yet.
 * The pending state is stored in $_SESSION["pd57_otp_pending"].
 */

require_once __DIR__ . "/../inc/constants.php";
require_once __DIR__ . "/../hook.php";

/**
 * Called after GLPI validates username+password but BEFORE the final session is established.
 * Stores the pre-auth state in session and redirects to OTP verify page.
 *
 * @param User $user          The authenticated user object
 * @param Auth $auth          The GLPI Auth object (auth_succeded=true at this point)
 * @param bool $remember_me   Whether remember-me was requested
 * @param bool $noauto        Whether noAUTO was set
 * @param string $redirect    Original redirect target
 */
function pd57auth_intercept_login(User $user, Auth $auth, bool $remember_me = false, bool $noauto = false, string $redirect = ""): never
{
    global $CFG_GLPI;

    $users_id = (int)$user->fields["id"];
    $username = $user->fields["name"];

    // Generate a session binding token (ties OTP to this exact pending session)
    $session_token = pd57auth_generate_session_token();

    // Generate and store OTP (hashed)
    $otp_plaintext = pd57auth_store_otp($users_id, $session_token);

    // Get the delivery address (never derives authorization, never in logs)
    $delivery_address = pd57auth_get_otp_delivery_address($username);

    // Send OTP email
    $sent = pd57auth_send_otp_email($delivery_address, $otp_plaintext, $username, PD57_AUTH_OTP_EXPIRY_MINUTES);

    // Unset plaintext OTP immediately — do not keep it in memory longer than needed
    unset($otp_plaintext);

    // Store pending pre-auth state (NOT a valid session — glpiname is NOT set)
    // This is analogous to GLPI's own mfa_pre_auth mechanism.
    $_SESSION["pd57_otp_pending"] = [
        "user_id"      => $users_id,
        "username"     => $username,
        "session_token"=> $session_token,
        "created_at"   => time(),
        "remember_me"  => $remember_me,
        "noauto"       => $noauto,
        "redirect"     => $redirect,
    ];

    // Critically: do NOT call Session::init($auth) — that would create a full authenticated session
    // Only a successful OTP verify can do that.

    // Redirect to OTP verify page
    $base = $CFG_GLPI["root_doc"] ?? "";
    $query = $sent ? "" : "?send_error=1";
    Html::redirect($base . "/plugins/pd57auth/front/otp_verify.php" . $query);
}
