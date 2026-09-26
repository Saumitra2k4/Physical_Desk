<?php
/**
 * PD57 Auth Plugin — Constants
 * Loaded from plugin hook.php early.
 */
define("PD57_AUTH_OTP_TTL", 300);             // 5 minutes expiry
define("PD57_AUTH_OTP_MAX_ATTEMPTS", 5);      // max failed verifications
define("PD57_AUTH_OTP_RESEND_COOLDOWN", 60);  // 60 seconds between resends
define("PD57_AUTH_OTP_EXPIRY_MINUTES", 5);
define("PD57_AUTH_OTP_RETENTION_SECONDS", 7 * DAY_TIMESTAMP); // retain audit rows for 7 days
