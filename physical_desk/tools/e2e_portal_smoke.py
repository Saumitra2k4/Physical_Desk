import secrets

from e2e_employee_department_test import (
    BASE_URL, extract_csrf, followup_exists, login_with_email_otp, logout,
    read_runtime_config, ticket_facts,
)

PORTAL = BASE_URL + "/plugins/pd57portal/front"


def run():
    config = read_runtime_config()
    employee, landing = login_with_email_otp("employee@physicaldesk", config)
    assert landing.status_code == 200 and "Physical Desk" in landing.text
    home = employee.get(PORTAL + "/index.php", timeout=30)
    assert home.status_code == 200 and "Create a Request" in home.text
    form = employee.get(PORTAL + "/request.php", timeout=30)
    assert form.status_code == 200 and "Category and department" in form.text
    token = extract_csrf(form.text)
    preview = employee.post(PORTAL + "/suggest.php", data={"text": "Studio wireless access point cannot connect staff devices during class setup."},
                            headers={"X-Requested-With": "XMLHttpRequest", "X-Glpi-Csrf-Token": token}, timeout=30)
    assert preview.status_code == 200 and preview.json().get("suggestions")
    marker = "PD57-PORTAL-" + secrets.token_hex(5)
    created = employee.post(PORTAL + "/request.php", data={
        "_glpi_csrf_token": token, "name": marker,
        "content": "Studio wireless access point cannot connect staff devices during class setup.",
        "itilcategories_id": "21", "locations_id": "0",
    }, timeout=40)
    assert created.status_code == 200 and "Request submitted" in created.text, (created.status_code, created.url, created.text[:150])
    facts = ticket_facts(marker)
    assert facts["category_id"] == 21 and "PD57_IT_NETWORK" in facts["groups"]
    assert facts["time_to_own"] and facts["time_to_resolve"]
    detail = employee.get(PORTAL + f"/ticket.php?id={facts['id']}", timeout=30)
    assert detail.status_code == 200 and marker in detail.text
    logout(employee)

    department, landing = login_with_email_otp("it@physicaldesk", config)
    assert landing.status_code == 200 and "IT queue" in landing.text
    queue = department.get(PORTAL + "/index.php", timeout=30)
    assert queue.status_code == 200 and marker in queue.text
    detail = department.get(PORTAL + f"/ticket.php?id={facts['id']}", timeout=30)
    assert detail.status_code == 200 and "Add an update" in detail.text
    update_marker = "PD57-PORTAL-UPDATE-" + secrets.token_hex(4)
    update = department.post(PORTAL + f"/ticket.php?id={facts['id']}", data={
        "_glpi_csrf_token": extract_csrf(detail.text), "id": str(facts["id"]),
        "content": update_marker, "followup": "1",
    }, timeout=40)
    assert update.status_code == 200 and followup_exists(facts["id"], update_marker)
    detail = department.get(PORTAL + f"/ticket.php?id={facts['id']}", timeout=30)
    correction = department.post(PORTAL + f"/ticket.php?id={facts['id']}", data={
        "_glpi_csrf_token": extract_csrf(detail.text), "id": str(facts["id"]),
        "suggestion_id": str(facts["suggestions"][0]["id"]),
        "category_id": "22", "override_category": "1",
    }, timeout=40)
    assert correction.status_code == 200, (correction.status_code, correction.url)
    corrected = ticket_facts(marker)
    assert corrected["category_id"] == 22 and corrected["suggestions"][0]["human_override"] == 1
    assert "PD57_IT_NETWORK" in corrected["groups"]
    logout(department)
    print("PORTAL_SMOKE=PASS")
    print(f"ticket_id={facts['id']}")
    print("employee_home=create_request,stats,recent_requests")
    print("department_queue=IT")


if __name__ == "__main__":
    run()
