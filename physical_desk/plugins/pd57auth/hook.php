<?php
/**
 * PD57 Auth Plugin — Hook Functions and OTP Core Logic
 *
 * This file contains:
 *  - plugin_pd57auth_install(): Creates the otp_tokens table
 *  - plugin_pd57auth_uninstall(): Drops the table
 *  - OtpMailboxMap: Maps PD57 login names -> OTP delivery addresses from env/config
 *  - OtpManager: Generates, stores (hashed), verifies, and expires OTP tokens
 *  - Mailer: Sends OTP emails using GLPIMailer
 *  - Route intercept: The post-init hook checks if an OTP is required
 *
 * Session design:
 *  After password success but before OTP, $_SESSION["pd57_otp_pending"] holds
 *  the pre-auth state. The user is NOT in a valid GLPI session during this window.
 *  Direct navigation to authenticated pages is blocked by the Firewall / Session::checkLoginUser.
 *
 * @package    PD57Auth
 */

/**
 * Create the OTP tokens table.
 */
function plugin_pd57auth_install(): bool
{
    global $DB;
    if (!$DB->tableExists("glpi_plugin_pd57auth_otp_tokens")) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_pd57auth_otp_tokens` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `users_id`        INT UNSIGNED NOT NULL,
                `token_hash`      VARCHAR(255) NOT NULL COMMENT \"bcrypt hash of the OTP\",
                `session_token`   VARCHAR(64)  NOT NULL COMMENT \"binds OTP to specific pending session\",
                `expires_at`      DATETIME     NOT NULL,
                `verified_at`     DATETIME     DEFAULT NULL,
                `attempts`        TINYINT      NOT NULL DEFAULT 0,
                `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `users_id` (`users_id`),
                KEY `session_token` (`session_token`),
                KEY `expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    return true;
}

/**
 * Drop the OTP tokens table.
 */
function plugin_pd57auth_uninstall(): bool
{
    global $DB;
    if ($DB->tableExists("glpi_plugin_pd57auth_otp_tokens")) {
        $DB->doQuery("DROP TABLE `glpi_plugin_pd57auth_otp_tokens`");
    }
    return true;
}

/**
 * Map a PD57 login name to the OTP delivery email address.
 *
 * The mapping is sourced from environment variables or /var/www/glpi/config/pd57auth.php
 * which is excluded from Git. This keeps real mailbox addresses out of the repository.
 *
 * Multiple PD57 accounts can safely share one OTP delivery mailbox.
 * Authorization is NEVER derived from the delivery address.
 *
 * Env var format:
 *   PD57_OTP_EMAIL_<UPPERCASE_USERNAME_ALPHANUMONLY>=address@domain.example
 * e.g.
 *   PD57_OTP_EMAIL_HRPHYSICALDESK=dept-test@gmail.com
 *   PD57_OTP_EMAIL_ITPHYSICALDESK=dept-test@gmail.com
 *   PD57_OTP_EMAIL_EMPLOYEEPHYSICALDESK=employee-test@gmail.com
 *
 * For local development, falls back to a configured default Mailpit address
 * or the GLPI admin email if no specific mapping is found.
 */
function pd57auth_get_otp_delivery_address(string $username): string
{
    // Load local config if present (never committed to git)
    $localConfig = GLPI_ROOT . "/config/pd57auth.php";
    $localMap = [];
    if (file_exists($localConfig)) {
        $cfg = include $localConfig;
        if (is_array($cfg) && isset($cfg["otp_delivery_map"])) {
            $localMap = $cfg["otp_delivery_map"];
        }
    }

    // Check local config map first
    if (isset($localMap[$username])) {
        return $localMap[$username];
    }

    // Then environment variables: PD57_OTP_EMAIL_<USERNAME_UPPERCASED_ALNUM>
    $envKey = "PD57_OTP_EMAIL_" . strtoupper(preg_replace("/[^a-zA-Z0-9]/", "", $username));
    $envAddr = getenv($envKey);
    if ($envAddr !== false && filter_var($envAddr, FILTER_VALIDATE_EMAIL)) {
        return $envAddr;
    }

    // A PD57 person may be linked to a logical GLPI identity. This lookup is
    // contact routing only: group membership remains the authorization source.
    global $DB;
    if (isset($DB) && $DB->tableExists('glpi_plugin_pd57portal_people')) {
        $user = $DB->request([
            'SELECT' => ['id'], 'FROM' => 'glpi_users',
            'WHERE' => ['name' => $username, 'is_active' => 1], 'LIMIT' => 1,
        ])->current();
        if ($user) {
            $person = $DB->request([
                'SELECT' => ['delivery_email'], 'FROM' => 'glpi_plugin_pd57portal_people',
                'WHERE' => ['linked_users_id' => (int)$user['id'], 'is_active' => 1], 'LIMIT' => 1,
            ])->current();
            if ($person && filter_var($person['delivery_email'], FILTER_VALIDATE_EMAIL)) {
                return (string)$person['delivery_email'];
            }
        }
    }

    // Final fallback: GLPI admin email (delivers to Mailpit in local dev)
    global $CFG_GLPI;
    return $CFG_GLPI["admin_email"] ?? "pd57-otp@physicaldesk.test";
}

/**
 * Generate a cryptographically secure 6-digit numeric OTP.
 */
function pd57auth_generate_otp(): string
{
    return str_pad((string)random_int(0, 999999), 6, "0", STR_PAD_LEFT);
}

/**
 * Generate a secure session binding token (64 hex chars).
 */
function pd57auth_generate_session_token(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Purge OTP audit rows after the bounded retention window.
 *
 * Every token is expired after five minutes, so deleting rows whose expiry is
 * older than the retention cutoff cannot invalidate a current login. Cleanup
 * runs during issuance to avoid requiring a separate scheduler integration.
 */
function pd57auth_cleanup_tokens(): int
{
    global $DB;

    $cutoff = date("Y-m-d H:i:s", time() - PD57_AUTH_OTP_RETENTION_SECONDS);

    return $DB->delete("glpi_plugin_pd57auth_otp_tokens", [
        ["expires_at" => ["<", $cutoff]],
    ]);
}

/**
 * Store a new OTP for a user. Invalidates all prior unexpired OTPs for this user.
 * Returns the plaintext OTP (used only to send the email — never stored again).
 */
function pd57auth_store_otp(int $users_id, string $session_token): string
{
    global $DB;

    pd57auth_cleanup_tokens();

    $otp = pd57auth_generate_otp();
    $hash = password_hash($otp, PASSWORD_BCRYPT);
    $expires_at = date("Y-m-d H:i:s", time() + PD57_AUTH_OTP_TTL);

    // Expire all previous pending OTPs for this user (replacement invalidation)
    $DB->update("glpi_plugin_pd57auth_otp_tokens", [
        "expires_at" => date("Y-m-d H:i:s", time() - 1),
    ], [
        "users_id"    => $users_id,
        "verified_at" => null,
        new QueryExpression("`expires_at` > NOW()"),
    ]);

    $DB->insert("glpi_plugin_pd57auth_otp_tokens", [
        "users_id"      => $users_id,
        "token_hash"    => $hash,
        "session_token" => $session_token,
        "expires_at"    => $expires_at,
        "attempts"      => 0,
        "created_at"    => date("Y-m-d H:i:s"),
    ]);

    return $otp;
}

/**
 * Verify an OTP attempt.
 *
 * @return string "ok", "wrong", "expired", "used", "no_pending", "limit_exceeded", "wrong_session"
 */
function pd57auth_verify_otp(int $users_id, string $session_token, string $submitted_otp): string
{
    global $DB;

    // Find the most recent non-expired token for this user+session
    $row = $DB->request([
        "FROM"  => "glpi_plugin_pd57auth_otp_tokens",
        "WHERE" => [
            "users_id"      => $users_id,
            "session_token" => $session_token,
            ["expires_at"   => [">=", new QueryExpression("NOW()")]],
        ],
        "ORDER" => ["created_at DESC"],
        "LIMIT" => 1,
    ])->current();

    if (!$row) {
        // Check if expired row exists for session (different error messages)
        $expiredRow = $DB->request([
            "FROM"  => "glpi_plugin_pd57auth_otp_tokens",
            "WHERE" => [
                "users_id"      => $users_id,
                "session_token" => $session_token,
                ["expires_at"   => ["<", new QueryExpression("NOW()")]],
            ],
            "LIMIT" => 1,
        ])->current();
        return $expiredRow ? "expired" : "no_pending";
    }

    $id = (int)$row["id"];

    // Already verified OTP cannot be reused
    if ($row["verified_at"] !== null) {
        return "used";
    }

    // Attempt rate limiting
    if ((int)$row["attempts"] >= PD57_AUTH_OTP_MAX_ATTEMPTS) {
        return "limit_exceeded";
    }

    // Increment attempt counter
    $DB->update("glpi_plugin_pd57auth_otp_tokens", [
        "attempts" => new QueryExpression("`attempts` + 1"),
    ], ["id" => $id]);

    // Verify hash
    if (!password_verify($submitted_otp, $row["token_hash"])) {
        return "wrong";
    }

    // Mark as consumed
    $DB->update("glpi_plugin_pd57auth_otp_tokens", [
        "verified_at" => date("Y-m-d H:i:s"),
    ], ["id" => $id]);

    return "ok";
}

/**
 * Check the resend cooldown for a user.
 * Returns true if a resend is allowed, false if rate-limited.
 */
function pd57auth_can_resend(int $users_id): bool
{
    global $DB;

    $recentRow = $DB->request([
        "FROM"  => "glpi_plugin_pd57auth_otp_tokens",
        "WHERE" => [
            "users_id"   => $users_id,
            ["created_at" => [">=", new QueryExpression("DATE_SUB(NOW(), INTERVAL " . PD57_AUTH_OTP_RESEND_COOLDOWN . " SECOND)")]],
        ],
        "ORDER" => ["created_at DESC"],
        "LIMIT" => 1,
    ])->current();

    return !$recentRow;
}

/**
 * Send OTP email via GLPIMailer.
 * OTP value is NEVER logged. Recipient is the mapped delivery address, NOT derived from authorization.
 */
function pd57auth_send_otp_email(string $delivery_address, string $otp, string $pd57_login, int $expiry_minutes): bool
{
    global $CFG_GLPI;

    try {
        $mailer = new GLPIMailer();
        $email = $mailer->getEmail();

        $from = $CFG_GLPI["admin_email"] ?? "pd57-noreply@physicaldesk.test";
        $email->from($from);
        $email->to($delivery_address);
        $email->subject("[Physical Desk / PD57] Your verification code");

        $text = implode("\n", [
            "Physical Desk / PD57 — Login Verification",
            "",
            "Your one-time verification code is: " . $otp,
            "",
            "This code is valid for " . $expiry_minutes . " minutes.",
            "Do not share this code with anyone.",
            "",
            "If you did not request this, ignore this email.",
            "",
            "— Physical Desk Security",
        ]);

        $email->text($text);
        // HTML version with the code formatted prominently but without exposing it in logs
        $safeCode = htmlspecialchars($otp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $email->html('<!doctype html><html><body style="margin:0;background:#f4f6f7;color:#25313b;font-family:Arial,sans-serif"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px"><table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border:1px solid #dbe2e6"><tr><td style="padding:28px 32px;border-top:4px solid #17b8c8"><p style="margin:0 0 8px;font-size:12px;letter-spacing:1px;color:#60717c">PHYSICAL DESK / PD57</p><h1 style="margin:0 0 20px;font-size:24px;color:#24313a">Verify your sign-in</h1><p>Your one-time security code is:</p><p style="margin:20px 0;padding:16px;text-align:center;background:#f4fafa;border:1px solid #b9e7eb;font:700 30px monospace;letter-spacing:8px;color:#24313a">'.$safeCode.'</p><p>This code expires in <strong>'.$expiry_minutes.' minutes</strong>. Do not share it with anyone.</p><p style="color:#60717c">If you did not request this, ignore this email.</p></td></tr></table></td></tr></table></body></html>');

        return $mailer->send();
    } catch (Throwable $e) {
        // Log error to GLPI log, never expose OTP
        Toolbox::logInFile("pd57auth", "OTP email send failed: " . $e->getMessage() . "\n");
        return false;
    }
}

/**
 * POST_INIT hook — called once after GLPI finishes its init sequence.
 * We use this to register our custom URL routes for the OTP flow.
 */
function plugin_pd57auth_post_init(): void
{
    // This hook is a no-op at runtime; our actual interception happens via
    // the pd57auth front-end PHP files which are guarded by Firewall::STRATEGY_NO_CHECK.
    // See plugins/pd57auth/front/otp_verify.php
}
