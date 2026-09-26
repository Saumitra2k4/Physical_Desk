import json
import re
import secrets
import subprocess
import time

import requests

from e2e_auth_test import (
    BASE_URL,
    MAILPIT_URL,
    clear_mailpit,
    get_latest_mailpit_messages,
    get_message_detail,
    load_private_auth_config,
)


GROUP_TO_LOGIN = {
    "PD57_HR_L1": "hr@physicaldesk",
    "PD57_IT_L1": "it@physicaldesk",
    "PD57_IT_NETWORK": "it@physicaldesk",
    "PD57_IT_HARDWARE": "it@physicaldesk",
    "PD57_IT_ACCESS": "it@physicaldesk",
    "PD57_IT_SECURITY": "it@physicaldesk",
    "PD57_IT_APPLICATIONS": "it@physicaldesk",
    "PD57_IT_AV": "it@physicaldesk",
    "PD57_PAYROLL_L1": "payroll@physicaldesk",
    "PD57_OPS_L1": "operations@physicaldesk",
    "PD57_OPS_L2": "operations@physicaldesk",
    "PD57_TRIAGE": "operations@physicaldesk",
}


def extract_csrf(html):
    match = re.search(
        r'name=["\']_glpi_csrf_token["\'][^>]*value=["\']([^"\']+)["\']',
        html,
        re.IGNORECASE,
    )
    if not match:
        match = re.search(
            r'value=["\']([^"\']+)["\'][^>]*name=["\']_glpi_csrf_token["\']',
            html,
            re.IGNORECASE,
        )
    assert match, "CSRF token was not present in the application response"
    return match.group(1)


def read_runtime_config():
    config = load_private_auth_config()
    result = subprocess.run(
        ["docker", "--context", "default", "exec", "pd57-app", "php",
         "/var/www/glpi/tools/pd57_ticket_facts.php", "users",
         "employee@physicaldesk", "hr@physicaldesk", "it@physicaldesk",
         "payroll@physicaldesk", "operations@physicaldesk"],
        capture_output=True,
        text=True,
        check=True,
    )
    config["user_ids"] = json.loads(result.stdout)
    return config


def login_with_email_otp(login, config):
    password = config["accounts"][login]["password"]
    expected_mailbox = config["otp_delivery_map"][login]
    clear_mailpit()

    session = requests.Session()
    login_page = session.get(f"{BASE_URL}/index.php", timeout=60)
    login_page.raise_for_status()
    payload = {
        "login_name": login,
        "login_password": password,
        "_glpi_csrf_token": extract_csrf(login_page.text),
    }
    login_response = session.post(
        f"{BASE_URL}/front/login.php",
        data=payload,
        allow_redirects=False,
        timeout=60,
    )
    assert login_response.status_code in (302, 303)
    assert "otp_verify.php" in login_response.headers.get("Location", "")

    otp_page = session.get(
        f"{BASE_URL}/plugins/pd57auth/front/otp_verify.php",
        timeout=60,
    )
    otp_page.raise_for_status()

    message = None
    for _ in range(20):
        for candidate in get_latest_mailpit_messages():
            detail = get_message_detail(candidate["ID"])
            recipients = [recipient["Address"] for recipient in detail.get("To", [])]
            if recipients == [expected_mailbox] and re.search(r"\b\d{6}\b", detail.get("Text", "")):
                message = detail
                break
        if message:
            break
        time.sleep(0.5)
    assert message, "Login OTP email was not delivered to the expected mailbox"
    recipients = [recipient["Address"] for recipient in message.get("To", [])]
    assert recipients == [expected_mailbox], "OTP was delivered to an unexpected mailbox"
    body = message.get("Text", "")
    otp_match = re.search(r"\b(\d{6})\b", body)
    assert otp_match, "OTP email did not contain a six-digit code"
    otp = otp_match.group(1)

    log_check = subprocess.run(
        [
            "docker", "--context", "default", "exec", "pd57-app",
            "grep", "-rn", otp, "/var/www/glpi/files/_log/",
        ],
        capture_output=True,
        text=True,
    )
    assert not log_check.stdout.strip(), "OTP appeared in ordinary application logs"

    verify_response = session.post(
        f"{BASE_URL}/plugins/pd57auth/front/otp_verify.php",
        data={
            "pd57_otp_code": otp,
            "pd57_otp_submit": "1",
            "_glpi_csrf_token": extract_csrf(otp_page.text),
        },
        allow_redirects=True,
        timeout=60,
    )
    assert verify_response.status_code == 200
    assert "pd57_otp_code" not in verify_response.text
    return session, verify_response


def ticket_facts(ticket_name):
    result = subprocess.run(
        [
            "docker", "--context", "default", "exec", "pd57-app",
            "php", "/var/www/glpi/tools/pd57_ticket_facts.php", "ticket", ticket_name,
        ],
        capture_output=True,
        text=True,
        check=True,
    )
    return json.loads(result.stdout)


def followup_exists(ticket_id, marker):
    result = subprocess.run(
        [
            "docker", "--context", "default", "exec", "pd57-app",
            "php", "/var/www/glpi/tools/pd57_ticket_facts.php", "followup", str(ticket_id), marker,
        ],
        capture_output=True,
        text=True,
        check=True,
    )
    return int(result.stdout.strip()) == 1


def logout(session):
    response = session.get(f"{BASE_URL}/front/logout.php", allow_redirects=False, timeout=30)
    assert response.status_code in (302, 303), "Logout did not end the session"


def run_employee_to_department_e2e():
    config = read_runtime_config()
    employee_login = "employee@physicaldesk"
    employee, employee_home = login_with_email_otp(employee_login, config)

    marker = "PD57-E2E-" + str(int(time.time())) + "-" + secrets.token_hex(4)
    native_create = employee.get(
        f"{BASE_URL}/front/ticket.form.php",
        timeout=20,
    )
    native_create.raise_for_status()
    csrf = extract_csrf(native_create.text)
    create_response = employee.post(
        f"{BASE_URL}/front/ticket.form.php",
        data={
            "add": "1",
            "name": marker,
            "content": "Studio wireless access point cannot connect staff devices during class setup.",
            "type": "1",
            "urgency": "3",
            "impact": "3",
            "entities_id": "0",
            "users_id_recipient": str(config["user_ids"][employee_login]),
            "_users_id_requester": str(config["user_ids"][employee_login]),
            "_glpi_csrf_token": csrf,
        },
        headers={"Referer": f"{BASE_URL}/ServiceCatalog"},
        allow_redirects=True,
        timeout=40,
    )
    assert create_response.status_code == 200

    facts = ticket_facts(marker)
    assert facts["id"] > 0
    assert facts["suggestions"], "Classifier metadata did not persist"
    assert facts["category_id"] == 0, "AI suggestion was silently made authoritative"
    suggestion = facts["suggestions"][0]
    ticket_url = f"{BASE_URL}/front/ticket.form.php?id={facts['id']}"
    employee_detail = employee.get(ticket_url, timeout=20)
    employee_detail.raise_for_status()
    confirm = employee.post(
        f"{BASE_URL}/plugins/pd57classifier/ajax/suggestion_action.php",
        data={"action": "confirm", "suggestion_id": str(suggestion["id"]),
              "ticket_id": str(facts["id"]), "category_id": str(suggestion["category_id"]),
              "_glpi_csrf_token": extract_csrf(employee_detail.text)},
        timeout=30,
    )
    assert confirm.status_code == 200, f"Employee category confirmation failed: {confirm.text[:400]}"
    facts = ticket_facts(marker)
    assert facts["category_id"] == suggestion["category_id"], "Confirmed category did not persist"
    assert facts["suggestions"][0]["human_confirmed"] == 1, "Human decision was not audited"
    assert facts["suggestions"][0]["final_confirmed_category_id"] == facts["category_id"]
    assert facts["time_to_own"] and facts["time_to_resolve"], "TTO/TTR were not assigned"
    assigned_groups = [group for group in facts["groups"] if group in GROUP_TO_LOGIN]
    assert assigned_groups, "No PD57 assignment group was resolved"
    assigned_group = assigned_groups[0]
    department_login = GROUP_TO_LOGIN[assigned_group]
    logout(employee)

    department, _ = login_with_email_otp(department_login, config)
    ticket_url = f"{BASE_URL}/front/ticket.form.php?id={facts['id']}"
    department_view = department.get(ticket_url, allow_redirects=False, timeout=20)
    assert department_view.status_code == 200, (
        f"Department view HTTP {department_view.status_code}, "
        f"location={department_view.headers.get('Location', '')}, "
        f"body={department_view.text[:200]!r}"
    )
    assert marker in department_view.text, "Assigned department could not view its ticket"

    action_marker = "PD57-E2E-ACTION-" + secrets.token_hex(8)
    action_response = department.post(
        f"{BASE_URL}/front/itilfollowup.form.php",
        data={
            "add": "1",
            "itemtype": "Ticket",
            "items_id": str(facts["id"]),
            "content": action_marker,
            "is_private": "0",
            "_glpi_csrf_token": extract_csrf(department_view.text),
        },
        allow_redirects=True,
        timeout=30,
    )
    assert action_response.status_code == 200
    assert followup_exists(facts["id"], action_marker), "Assigned department could not act on its ticket"
    logout(department)

    unrelated_login = next(
        login for group, login in GROUP_TO_LOGIN.items()
        if group != assigned_group and login != department_login
    )
    unrelated, _ = login_with_email_otp(unrelated_login, config)
    unrelated_view = unrelated.get(ticket_url, allow_redirects=False, timeout=20)
    unrelated_isolated = unrelated_view.status_code in (302, 303, 401, 403) or marker not in unrelated_view.text
    assert unrelated_isolated, "Unrelated department could access the routed ticket"
    logout(unrelated)

    print("EMPLOYEE_TO_DEPARTMENT_E2E=PASS")
    print(f"ticket_id={facts['id']}")
    print(f"category={facts['category']}")
    print(f"assignment_group={assigned_group}")
    print(f"department_login={department_login}")
    print("unrelated_department_isolated=True")


if __name__ == "__main__":
    run_employee_to_department_e2e()
