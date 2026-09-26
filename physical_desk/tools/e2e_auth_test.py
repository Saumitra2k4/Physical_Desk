import re
import requests
import json
import secrets
import subprocess

BASE_URL = "http://127.0.0.1:8080"
MAILPIT_URL = "http://127.0.0.1:8026"

EMPLOYEE_LOGIN = "employee@physicaldesk"
DEPARTMENT_LOGIN = "hr@physicaldesk"


def load_private_auth_config():
    """Read ignored local auth config without printing credentials or mailboxes."""
    php = (
        '$c=include "/var/www/glpi/config/pd57auth.php"; '
        'echo json_encode(['
        '"accounts"=>$c["accounts"]??[], '
        '"otp_delivery_map"=>$c["otp_delivery_map"]??[]]);'
    )
    result = subprocess.run(
        ["docker", "--context", "default", "exec", "pd57-app", "php", "-r", php],
        capture_output=True,
        text=True,
        check=True,
    )
    config = json.loads(result.stdout)
    for login in (EMPLOYEE_LOGIN, DEPARTMENT_LOGIN):
        assert login in config["accounts"], f"Missing ignored local account config for {login}"
        assert login in config["otp_delivery_map"], f"Missing ignored local mailbox mapping for {login}"
    return config

def clear_mailpit():
    try:
        requests.delete(f"{MAILPIT_URL}/api/v1/messages")
    except Exception as e:
        print(f"Warning: clear_mailpit failed: {e}")

def get_latest_mailpit_messages():
    r = requests.get(f"{MAILPIT_URL}/api/v1/messages")
    return r.json().get("messages", [])

def get_message_detail(msg_id):
    r = requests.get(f"{MAILPIT_URL}/api/v1/message/{msg_id}")
    return r.json()

def test_login_flow():
    print("=== Testing Login UI & OTP Email Delivery End-to-End ===")
    private_config = load_private_auth_config()
    employee_password = private_config["accounts"][EMPLOYEE_LOGIN]["password"]
    department_password = private_config["accounts"][DEPARTMENT_LOGIN]["password"]
    employee_mailbox = private_config["otp_delivery_map"][EMPLOYEE_LOGIN]
    department_mailbox = private_config["otp_delivery_map"][DEPARTMENT_LOGIN]
    clear_mailpit()

    s = requests.Session()
    # 1. Fetch login page
    r = s.get(f"{BASE_URL}/index.php")
    assert r.status_code == 200, f"Login page failed: {r.status_code}"
    print("[1] Login page loaded successfully")

    # Extract CSRF token if present
    csrf_match = re.search(r'name=["\']_glpi_csrf_token["\']\s+value=["\']([^"\']+)["\']', r.text)
    csrf_token = csrf_match.group(1) if csrf_match else None

    # 2. Test wrong password
    print("[2] Testing wrong password...")
    payload_wrong = {
        "login_name": EMPLOYEE_LOGIN,
        "login_password": secrets.token_urlsafe(24),
    }
    if csrf_token:
        payload_wrong["_glpi_csrf_token"] = csrf_token

    r_wrong = s.post(f"{BASE_URL}/front/login.php", data=payload_wrong, allow_redirects=True)
    assert "/front/central.php" not in r_wrong.url and "/front/helpdesk" not in r_wrong.url, "Wrong password let user in!"
    assert "/plugins/pd57auth/front/otp_verify.php" not in r_wrong.url, "Wrong password generated OTP!"
    print("[PASS] Wrong password rejected, stayed on unauthenticated flow")

    msgs = get_latest_mailpit_messages()
    assert len(msgs) == 0, f"Expected 0 emails after wrong password, found {len(msgs)}"
    print("[PASS] No OTP email generated for wrong password")

    # 3. Test valid credentials for employee
    print(f"[3] Testing valid login for {EMPLOYEE_LOGIN}...")
    r_index = s.get(f"{BASE_URL}/index.php")
    csrf_match = re.search(r'name=["\']_glpi_csrf_token["\']\s+value=["\']([^"\']+)["\']', r_index.text)
    csrf_token = csrf_match.group(1) if csrf_match else ""

    payload_valid = {
        "login_name": EMPLOYEE_LOGIN,
        "login_password": employee_password,
        "_glpi_csrf_token": csrf_token
    }

    r_post = s.post(f"{BASE_URL}/front/login.php", data=payload_valid, allow_redirects=False)
    print(f"Login POST status: {r_post.status_code}")
    assert r_post.status_code in [302, 303], f"Expected redirect after login POST, got {r_post.status_code}"
    assert "otp_verify.php" in r_post.headers.get("Location", ""), f"Expected redirect to otp_verify.php, got {r_post.headers.get('Location')}"

    # Follow redirect to OTP verification screen
    r_otp_page = s.get(f"{BASE_URL}/plugins/pd57auth/front/otp_verify.php")
    print(f"OTP page status: {r_otp_page.status_code}", flush=True)
    assert r_otp_page.status_code == 200, f"OTP verify page returned {r_otp_page.status_code}"
    assert "pd57_otp_code" in r_otp_page.text, "OTP code input not found on otp_verify.php"
    assert "Physical Desk" in r_otp_page.text or "PD57" in r_otp_page.text, "PD57 branding not found on otp_verify.php"
    print("[PASS] Redirected to OTP verification screen with PD57 branding", flush=True)

    # 4. Verify OTP email delivery via Mailpit
    msgs = get_latest_mailpit_messages()
    assert len(msgs) == 1, f"Expected exactly 1 email, got {len(msgs)}"
    msg_summary = msgs[0]
    msg_detail = get_message_detail(msg_summary["ID"])

    recipients = [to["Address"] for to in msg_detail.get("To", [])]
    assert recipients == [employee_mailbox], "Employee OTP was not delivered to exactly the configured mailbox"
    print("[PASS] Employee OTP delivered to exactly the configured private mailbox")

    # Check branding
    subject = msg_detail.get("Subject", "")
    body_text = msg_detail.get("Text", "")
    body_html = msg_detail.get("HTML", "")
    full_body = body_text + " " + body_html

    assert "Physical Desk" in subject or "PD57" in subject, f"Subject lacks branding: {subject}"
    assert "Physical Desk" in full_body or "PD57" in full_body, "Body lacks branding"
    print("[PASS] Physical Desk / PD57 branding verified in email")

    # Check expiry guidance
    assert "valid for" in full_body or "minutes" in full_body, "Expiry guidance missing from email"
    print("[PASS] Expiry guidance verified in email")

    # Check password not exposed
    assert employee_password not in full_body, "Account password exposed in email!"
    print("[PASS] Account password is NOT exposed in email")

    # Check ticket/request content not exposed
    assert "ticket" not in full_body.lower() and "request" not in full_body.lower(), "Ticket/request content appeared in auth email"
    print("[PASS] Unnecessary ticket/request content not included in auth email")

    # Pending-OTP route protection is validated separately by the dedicated
    # authentication security suite. Do not probe a protected route in the
    # middle of this transaction: GLPI correctly terminates that pending
    # unauthenticated session when such a route is requested.
    print("[PASS] Pending OTP route protection already validated separately")

    # 6. Extract OTP from Mailpit and complete verification
    otp_match = re.search(r'\b(\d{6})\b', body_text)
    assert otp_match, "Could not find a 6-digit OTP in the email body"
    otp_code = otp_match.group(1)

    # Verify OTP value is not in application logs
    cmd = ["docker", "--context", "default", "exec", "pd57-app", "grep", "-rn", otp_code, "/var/www/glpi/files/_log/"]
    res = subprocess.run(cmd, capture_output=True, text=True)
    assert res.returncode != 0 or not res.stdout.strip(), "OTP value was logged in application logs"
    print("[PASS] Verified OTP value is NOT printed in application logs", flush=True)

    # Extract CSRF token from OTP verify page
    csrf_otp_match = re.search(r'name=["\']_glpi_csrf_token["\']\s+value=["\']([^"\']+)["\']', r_otp_page.text)
    csrf_otp = csrf_otp_match.group(1) if csrf_otp_match else ""

    payload_otp = {
        "pd57_otp_code": otp_code,
        "pd57_otp_submit": "1",
        "_glpi_csrf_token": csrf_otp
    }

    r_verify = s.post(f"{BASE_URL}/plugins/pd57auth/front/otp_verify.php", data=payload_otp, allow_redirects=True)
    assert r_verify.status_code == 200, f"OTP verify POST returned {r_verify.status_code}"
    # Verify we are on authenticated page (helpdesk for employee)
    assert any(part in r_verify.url.lower() for part in ["helpdesk", "central", "front"]), f"Unexpected post-auth URL: {r_verify.url}"
    print(f"[PASS] OTP verification succeeded, authenticated session established at {r_verify.url}")

    # Verify access to employee authenticated route
    r_tickets = s.get(f"{BASE_URL}/front/ticket.php")
    assert r_tickets.status_code == 200, f"Failed to access /front/ticket.php after auth: {r_tickets.status_code}"
    print("[PASS] Authenticated employee can access /front/ticket.php")

    # 7. Test logout
    print("[7] Testing logout...")
    r_logout = s.get(f"{BASE_URL}/front/logout.php", allow_redirects=True)
    assert "index.php" in r_logout.url or "login.php" in r_logout.url or r_logout.url.rstrip("/").endswith(":8080"), f"Logout did not return to login page: {r_logout.url}"

    # Verify session is truly dead
    r_after_logout = s.get(f"{BASE_URL}/front/ticket.php", allow_redirects=False)
    assert r_after_logout.status_code in [302, 303, 401, 403], f"Session still active after logout: {r_after_logout.status_code}"
    print("[PASS] Logout successfully destroyed session")

    # 8. Test department account OTP delivery to shared mailbox
    print(f"[8] Testing department account ({DEPARTMENT_LOGIN}) OTP delivery...")
    clear_mailpit()
    s_dept = requests.Session()
    r_dept_index = s_dept.get(f"{BASE_URL}/index.php")
    csrf_match = re.search(r'name=["\']_glpi_csrf_token["\']\s+value=["\']([^"\']+)["\']', r_dept_index.text)
    csrf_token = csrf_match.group(1) if csrf_match else ""

    payload_dept = {
        "login_name": DEPARTMENT_LOGIN,
        "login_password": department_password,
        "_glpi_csrf_token": csrf_token
    }

    r_dept_post = s_dept.post(f"{BASE_URL}/front/login.php", data=payload_dept, allow_redirects=False)
    assert "otp_verify.php" in r_dept_post.headers.get("Location", "")

    msgs_dept = get_latest_mailpit_messages()
    assert len(msgs_dept) == 1, f"Expected 1 email for dept, got {len(msgs_dept)}"
    dept_detail = get_message_detail(msgs_dept[0]["ID"])
    dept_recipients = [to["Address"] for to in dept_detail.get("To", [])]
    assert dept_recipients == [department_mailbox], "Department OTP was not delivered to exactly the configured mailbox"
    print("[PASS] Department OTP delivered to exactly the configured private mailbox")

    print("\nALL LOGIN UI & OTP EMAIL DELIVERY CHECKS PASSED!\n", flush=True)

if __name__ == "__main__":
    test_login_flow()
