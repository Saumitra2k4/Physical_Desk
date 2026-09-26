<?php
/**
 * PD57 Auth Plugin — OTP Verify Front Controller
 *
 * This page is the second step of the enterprise login flow.
 * The user is NOT yet authenticated (no glpiID in session).
 * Only pd57_otp_pending state is in the session.
 *
 * URL: /plugins/pd57auth/front/otp_verify.php
 *
 * GET  - Show the OTP entry form
 * POST with "pd57_otp_submit" - Validate the submitted code
 * POST with "pd57_otp_resend" - Resend the OTP (rate-limited)
 * POST with "pd57_otp_cancel" - Abort login and return to login page
 */

// Bootstrap GLPI with minimal session check — we need DB + mailer but NOT an auth check
// GLPI 11 has already bootstrapped the application, database and session
// before this legacy plugin controller is included by LegacyFileLoadController.
// Starting another Kernel here breaks the active PHP session.
if (!defined("GLPI_ROOT")) {
    http_response_code(500);
    exit("Physical Desk framework bootstrap unavailable.");
}

global $CFG_GLPI;


use Glpi\Security\Attribute\SecurityStrategy;
use function Safe\session_destroy;

// Require the pd57auth functions
require_once __DIR__ . "/../inc/constants.php";
require_once __DIR__ . "/../hook.php";
require_once __DIR__ . "/../inc/interceptor.php";

// Basic CSRF protection — all POSTs must include the GLPI token
// No standard page_header guard here (user not authed), but we validate CSRF manually.
// GLPI 11 validates POST CSRF tokens in CheckCsrfListener before this
// legacy controller executes. Do not duplicate or consume that validation here.

// Check that a valid pending OTP session exists
if (
    !isset($_SESSION["pd57_otp_pending"])
    || !isset($_SESSION["pd57_otp_pending"]["user_id"])
    || (time() - $_SESSION["pd57_otp_pending"]["created_at"]) > 900 // max 15 min pending
) {
    // No valid pending session — redirect back to login
    Html::redirect($CFG_GLPI["root_doc"] . "/index.php?error=4");
}

$pending      = $_SESSION["pd57_otp_pending"];
$users_id     = (int)$pending["user_id"];
$username     = $pending["username"];
$session_tok  = $pending["session_token"];
$pending_age  = time() - $pending["created_at"];

$error_msg    = "";
$info_msg     = "";

// ----- Handle: cancel login -----
if (isset($_POST["pd57_otp_cancel"])) {
    unset($_SESSION["pd57_otp_pending"]);
    Html::redirect($CFG_GLPI["root_doc"] . "/index.php");
}

// ----- Handle: resend OTP -----
if (isset($_POST["pd57_otp_resend"])) {
    if (!pd57auth_can_resend($users_id)) {
        $error_msg = "Please wait before requesting a new code.";
    } else {
        $delivery_address = pd57auth_get_otp_delivery_address($username);
        $new_session_tok = pd57auth_generate_session_token();
        $otp_plaintext = pd57auth_store_otp($users_id, $new_session_tok);

        $sent = pd57auth_send_otp_email($delivery_address, $otp_plaintext, $username, PD57_AUTH_OTP_EXPIRY_MINUTES);
        unset($otp_plaintext);

        // Update session binding token
        $_SESSION["pd57_otp_pending"]["session_token"] = $new_session_tok;
        $_SESSION["pd57_otp_pending"]["created_at"] = time();
        $session_tok = $new_session_tok;

        $info_msg = $sent
            ? "A new verification code has been sent."
            : "Could not send email — please try again or contact IT support.";
    }
}

// ----- Handle: OTP submission -----
if (isset($_POST["pd57_otp_submit"])) {
    $submitted = trim($_POST["pd57_otp_code"] ?? "");

    // Basic format guard — 6 digits
    if (!preg_match("/^\d{6}$/", $submitted)) {
        $error_msg = "Please enter the 6-digit code from your email.";
    } else {
        $result = pd57auth_verify_otp($users_id, $session_tok, $submitted);

        switch ($result) {
            case "ok":
                // OTP verified — complete the authentication.
                // Re-create an Auth object and restore session as if login just succeeded.
                $auth = new Auth();
                $auth->user = new User();
                if (!$auth->user->getFromDB($users_id)) {
                    $error_msg = "Session error: user not found. Please log in again.";
                    break;
                }
                $auth->auth_succeded = true;
                $auth->extauth = 0;
                $auth->user_present = 1;
                $auth->password_expired = false;

                // Store pending params before clearing session
                $remember_me = $pending["remember_me"];
                $noauto = $pending["noauto"];
                $redirect = $pending["redirect"];

                // Clear the pending OTP state
                unset($_SESSION["pd57_otp_pending"]);

                // Mark as pd57auth-completed so intercept does NOT fire again
                $_SESSION["pd57_otp_completed"] = true;

                // Initialize the full GLPI session
                Session::init($auth);

                if (!$auth->auth_succeded) {
                    $error_msg = "Account access denied. Contact your administrator.";
                    break;
                }

                // Set remember-me cookie if requested
                if ($CFG_GLPI["login_remember_time"] > 0 && $remember_me) {
                    $token = $auth->user->getAuthToken("cookie_token", true);
                    if ($token) {
                        $data = json_encode([$auth->user->fields["id"], $token]);
                        Auth::setRememberMeCookie($data);
                    }
                }

                if ($noauto) {
                    $_SESSION["noAUTO"] = 1;
                }

                // Redirect to final destination
                if (!empty($redirect) && filter_var($redirect, FILTER_VALIDATE_URL)) {
                    Html::redirect($redirect);
                } else {
                    Html::redirect($CFG_GLPI["root_doc"] . "/plugins/pd57portal/front/index.php");
                }
                exit;

            case "expired":
                $error_msg = "Your verification code has expired. Please request a new one.";
                break;
            case "limit_exceeded":
                $error_msg = "Too many incorrect attempts. Please request a new code.";
                break;
            case "used":
                $error_msg = "This code has already been used. Please request a new code.";
                break;
            case "wrong_session":
                $error_msg = "Session mismatch. Please log in again.";
                unset($_SESSION["pd57_otp_pending"]);
                Html::redirect($CFG_GLPI["root_doc"] . "/index.php");
                break;
            case "wrong":
                $error_msg = "Incorrect code. Please check your email and try again.";
                break;
            default:
                $error_msg = "Verification error. Please log in again.";
        }
    }
}

// Also check for send_error from initial redirect
if (isset($_GET["send_error"])) {
    $error_msg = "Could not send verification email. Please contact IT support or try again.";
}

// ----- Render the OTP form -----
$title = "Verify Your Identity — Physical Desk";

// Build a simple secure OTP form (no full GLPI page_header as user isn't authenticated)
echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
echo "<meta charset=\"UTF-8\">\n";
echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
echo "<title>" . htmlspecialchars($title) . "</title>\n";
echo "<style>\n";
echo "body { margin:0; font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:#fbf8f6; color:#252331; display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px; box-sizing:border-box; }\n";
echo ".card { background:#fff; border:1px solid #ece5e6; border-radius:20px; padding:38px; max-width:430px; width:100%; box-shadow:0 24px 65px #35233312; }\n";
echo ".brand {font-size:20px;font-weight:800;letter-spacing:-.05em;margin-bottom:28px}.mark{display:inline-grid;place-items:center;width:36px;height:36px;border-radius:11px;background:#252331;color:#fff;margin-right:9px}.eyebrow{color:#bd365f;font-size:11px;letter-spacing:.16em;font-weight:800;margin:0 0 8px;text-transform:uppercase}\n";
echo "h2 { margin:0 0 8px; font-size:1.8rem; letter-spacing:-.05em; font-weight:750; color:#252331; }\n";
echo "p.sub { margin:0 0 27px; font-size:0.9rem; color:#77737e; line-height:1.55; }\n";
echo "label { display:block; margin-bottom:8px; font-size:0.8rem; font-weight:700; color:#5c5660; }\n";
echo "input[type=text] { width:100%; box-sizing:border-box; background:#fff; border:1px solid #ded7dd; border-radius:10px; color:#252331; padding:12px 16px; font-size:1.5rem; letter-spacing:8px; text-align:center; }\n";
echo "input[type=text]:focus { outline:none; border-color:#e75582; box-shadow:0 0 0 3px #e7558222; }\n";
echo ".btn-primary { display:block; width:100%; background:#e75582; color:#fff; border:none; border-radius:10px; padding:13px; font-size:.95rem; font-weight:700; cursor:pointer; margin-top:16px; }\n";
echo ".btn-primary:hover { background:#bd365f; }\n";
echo ".btn-ghost { background:transparent; border:1px solid #e7dfe3; color:#6f6771; border-radius:10px; padding:10px; width:100%; font-size:0.85rem; cursor:pointer; margin-top:10px; }\n";
echo ".btn-ghost:hover { border-color:#e75582; color:#bd365f; }\n";
echo ".error { background:#fff0f0; border-radius:8px; padding:12px 16px; margin-bottom:16px; color:#a13e4e; font-size:0.875rem; }\n";
echo ".info { background:#f7edf0; border-radius:8px; padding:12px 16px; margin-bottom:16px; color:#9d3154; font-size:0.875rem; }\n";
echo ".divider { height:1px; background:#ece5e6; margin:20px 0; }\n";
echo ".hint { font-size:0.8rem; color:#9f979e; margin-top:16px; text-align:center; }\n";
echo "</style>\n";
echo "</head>\n<body>\n";
echo "<div class=\"card\">\n";
echo "<div class=\"brand\"><span class=\"mark\">P</span>Physical Desk</div>\n";
echo "<p class=\"eyebrow\">ONE MORE STEP</p>\n";
echo "<h2>Verify your sign-in</h2>\n";
echo "<p class=\"sub\">A 6-digit code has been sent to the email on file for <strong>" . htmlspecialchars($username) . "</strong>. Enter it below to complete sign-in.</p>\n";

if ($error_msg) {
    echo "<div class=\"error\">" . htmlspecialchars($error_msg) . "</div>\n";
}
if ($info_msg) {
    echo "<div class=\"info\">" . htmlspecialchars($info_msg) . "</div>\n";
}

$token = Session::getNewCSRFToken();

// Verify form
echo "<form method=\"post\" autocomplete=\"off\">\n";
echo "<input type=\"hidden\" name=\"_glpi_csrf_token\" value=\"" . htmlspecialchars($token) . "\">\n";
echo "<label for=\"pd57_otp_code\">Verification Code</label>\n";
echo "<input type=\"text\" id=\"pd57_otp_code\" name=\"pd57_otp_code\" maxlength=\"6\" pattern=\"[0-9]{6}\" placeholder=\"000000\" inputmode=\"numeric\" autofocus>\n";
echo "<button type=\"submit\" name=\"pd57_otp_submit\" class=\"btn-primary\">Verify & Sign In</button>\n";
echo "</form>\n";

echo "<div class=\"divider\"></div>\n";

// Resend form (separate form with its own CSRF)
echo "<form method=\"post\">\n";
echo "<input type=\"hidden\" name=\"_glpi_csrf_token\" value=\"" . htmlspecialchars($token) . "\">\n";
echo "<button type=\"submit\" name=\"pd57_otp_resend\" class=\"btn-ghost\">&#8635; Resend Code</button>\n";
echo "</form>\n";

// Cancel form
echo "<form method=\"post\">\n";
echo "<input type=\"hidden\" name=\"_glpi_csrf_token\" value=\"" . htmlspecialchars($token) . "\">\n";
echo "<button type=\"submit\" name=\"pd57_otp_cancel\" class=\"btn-ghost\">&#8592; Cancel &amp; Return to Login</button>\n";
echo "</form>\n";

echo "<p class=\"hint\">Code valid for " . PD57_AUTH_OTP_EXPIRY_MINUTES . " minutes &bull; Check spam if not received</p>\n";

echo "</div>\n</body>\n</html>\n";
