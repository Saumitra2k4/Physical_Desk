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
$title = "Verify your sign-in — PD57";
$token = Session::getNewCSRFToken();
$safeUsername = htmlspecialchars((string)$username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeToken = htmlspecialchars((string)$token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeError = htmlspecialchars($error_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeInfo = htmlspecialchars($info_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$expiryMinutes = (int)PD57_AUTH_OTP_EXPIRY_MINUTES;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <style>
        :root{--ink:#11161a;--charcoal:#20282e;--steel:#65717b;--line:#dce2e6;--canvas:#f4f6f7;--cyan:#65c8e8;--cyan-deep:#14788e;--danger:#a32929}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--canvas);color:var(--ink);font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:24px;display:grid;place-items:center}
        .shell{display:grid;grid-template-columns:minmax(320px,.9fr) minmax(420px,1.1fr);width:min(1120px,100%);min-height:min(720px,calc(100vh - 48px));overflow:hidden;background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 24px 70px rgba(17,22,26,.10)}
        .brand-panel{position:relative;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;padding:clamp(32px,5vw,64px);background:var(--ink);color:#fff}.brand-panel:before{content:"";position:absolute;width:280px;height:1px;right:-54px;top:34%;background:rgba(101,200,232,.65);transform:rotate(-38deg)}.brand-panel:after{content:"";position:absolute;width:190px;height:190px;right:-84px;bottom:54px;border:1px solid rgba(255,255,255,.14);border-radius:50%;box-shadow:0 0 0 44px rgba(255,255,255,.025),0 0 0 88px rgba(255,255,255,.018)}
        .wordmark{display:inline-flex;align-items:center;gap:13px;color:#fff;position:relative;z-index:1}.mark{display:grid;grid-template-columns:repeat(2,1fr);width:48px;height:48px;border:1px solid rgba(255,255,255,.38)}.mark span{display:grid;place-items:center;font-size:14px;font-weight:800}.mark .number{background:var(--cyan);color:var(--ink)}.wordmark-copy strong{display:block;font-size:18px;letter-spacing:.08em;line-height:1.05}.wordmark-copy small{display:block;margin-top:5px;color:#aab4bb;font-size:10px;letter-spacing:.16em;text-transform:uppercase}
        .statement{position:relative;z-index:1;max-width:430px}.kicker,.eyebrow{color:var(--cyan);font-size:11px;font-weight:750;letter-spacing:.16em;text-transform:uppercase}.statement h1{color:#fff;font-size:clamp(34px,4.2vw,58px);line-height:1.02;letter-spacing:-.055em;margin:16px 0 20px}.statement p{color:#c4cbd0;font-size:15px;line-height:1.7;margin:0;max-width:370px}.brand-meta{position:relative;z-index:1;color:#8f9aa2;font-size:11px;letter-spacing:.06em;text-transform:uppercase}
        .main{display:grid;align-content:center;padding:clamp(32px,6vw,80px);min-width:0}.content{width:100%;max-width:440px;margin:auto}.eyebrow{color:var(--cyan-deep);margin:0 0 10px}.content h2{font-size:clamp(30px,4vw,40px);letter-spacing:-.045em;margin:0;font-weight:720}.sub{color:var(--steel);margin:10px 0 28px;font-size:14px;line-height:1.6}.sub strong{color:var(--charcoal)}
        label{display:block;margin-bottom:8px;color:#2b343a;font-size:13px;font-weight:650}.otp{width:100%;min-height:62px;border:1px solid #cbd3d8;border-radius:8px;background:#fff;color:var(--ink);padding:10px 16px;font-size:28px;font-weight:700;letter-spacing:.34em;text-align:center}.otp:hover{border-color:#9ca8b0}.otp:focus{outline:none;border-color:var(--cyan-deep);box-shadow:0 0 0 3px rgba(101,200,232,.18)}
        button{width:100%;min-height:48px;border-radius:8px;padding:11px 16px;font:inherit;font-weight:700;cursor:pointer}.primary{margin-top:16px;background:var(--ink);border:1px solid var(--ink);color:#fff}.primary:hover{background:var(--charcoal)}.secondary{margin-top:10px;background:#fff;border:1px solid #cbd3d8;color:var(--charcoal)}.secondary:hover{background:#f7f9fa;border-color:#9ca8b0}.quiet{background:transparent;border:0;color:var(--cyan-deep);min-height:40px;margin-top:4px}.quiet:hover{text-decoration:underline;text-underline-offset:3px}button:focus-visible,.otp:focus-visible{outline:3px solid rgba(101,200,232,.5);outline-offset:2px}
        .message{border-radius:8px;padding:12px 14px;margin:0 0 18px;font-size:13px;line-height:1.5}.error{background:#fff4f3;border:1px solid #efc8c4;color:var(--danger)}.info{background:#edf9fc;border:1px solid #b9e4ed;color:#1b6170}.divider{height:1px;background:var(--line);margin:22px 0 10px}.hint{font-size:12px;color:#7b858d;margin:18px 0 0;text-align:center;line-height:1.5}
        @media(max-width:820px){body{padding:0}.shell{grid-template-columns:1fr;min-height:100vh;border:0;border-radius:0;box-shadow:none}.brand-panel{min-height:250px;padding:28px clamp(24px,7vw,52px)}.statement h1{font-size:clamp(30px,8vw,44px);margin:20px 0 10px}.statement p{display:none}.main{padding:40px clamp(24px,7vw,52px) 32px}}@media(max-width:430px){.brand-panel{min-height:220px}.main{align-content:start}.otp{font-size:24px;letter-spacing:.26em}}
    </style>
</head>
<body>
<main class="shell">
    <section class="brand-panel" aria-label="Physical Desk">
        <div class="wordmark"><span class="mark" aria-hidden="true"><span>PD</span><span class="number">57</span></span><span class="wordmark-copy"><strong>PD57</strong><small>Physical Desk</small></span></div>
        <div class="statement"><span class="kicker">Employee Request Management</span><h1>Secure by design.<br>Simple by default.</h1><p>A second verification step protects your requests and operational information.</p></div>
        <div class="brand-meta">Secure sign-in · Code expires automatically</div>
    </section>
    <section class="main">
        <div class="content">
            <p class="eyebrow">One more step</p>
            <h2>Verify your sign-in</h2>
            <p class="sub">We sent a 6-digit code to the email on file for <strong><?= $safeUsername ?></strong>.</p>
            <?php if ($safeError !== ''): ?><div class="message error" role="alert"><?= $safeError ?></div><?php endif; ?>
            <?php if ($safeInfo !== ''): ?><div class="message info" role="status"><?= $safeInfo ?></div><?php endif; ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="_glpi_csrf_token" value="<?= $safeToken ?>">
                <label for="pd57_otp_code">Verification code</label>
                <input class="otp" type="text" id="pd57_otp_code" name="pd57_otp_code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" inputmode="numeric" autocomplete="one-time-code" autofocus required aria-describedby="otp-guidance">
                <button type="submit" name="pd57_otp_submit" class="primary">Verify and sign in</button>
            </form>
            <div class="divider"></div>
            <form method="post"><input type="hidden" name="_glpi_csrf_token" value="<?= $safeToken ?>"><button type="submit" name="pd57_otp_resend" class="secondary">Resend code</button></form>
            <form method="post"><input type="hidden" name="_glpi_csrf_token" value="<?= $safeToken ?>"><button type="submit" name="pd57_otp_cancel" class="quiet">Cancel and return to login</button></form>
            <p class="hint" id="otp-guidance">Code valid for <?= $expiryMinutes ?> minutes. Check your spam folder if it has not arrived.</p>
        </div>
    </section>
</main>
</body>
</html>
