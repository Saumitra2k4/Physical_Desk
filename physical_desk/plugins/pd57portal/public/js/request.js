(() => {
  const button = document.getElementById('suggest-button');
  const box = document.getElementById('suggestions');
  const select = document.getElementById('category');
  if (!button || !box || !select) return;
  button.addEventListener('click', async () => {
    const text = `${document.getElementById('name').value} ${document.getElementById('content').value}`.trim();
    if (text.length < 10) { box.textContent = 'Add a title and description to see suggestions.'; return; }
    box.textContent = 'Finding the best categories…';
    button.disabled = true;
    try {
      const token = document.querySelector('input[name="_glpi_csrf_token"]').value;
      const body = new FormData(); body.append('text', text);
      const response = await fetch('/plugins/pd57portal/front/suggest.php', {
        method: 'POST', body, credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': token}
      });
      if (!response.ok) throw new Error('Suggestions unavailable');
      const data = await response.json();
      box.replaceChildren();
      for (const item of data.suggestions || []) {
        const choice = document.createElement('button'); choice.type = 'button';
        choice.className = 'suggestion-choice'; choice.textContent = `${item.path} →`;
        choice.addEventListener('click', () => { select.value = String(item.category_id); box.querySelectorAll('button').forEach(b => b.classList.remove('chosen')); choice.classList.add('chosen'); select.focus(); });
        box.appendChild(choice);
      }
      if (!box.children.length) box.textContent = 'No category suggestion. Choose a category yourself.';
    } catch { box.textContent = 'Suggestions are unavailable. Please choose a category yourself.'; }
    button.disabled = false;
  });
})();

/* ==========================================================
   PD57_FAST_DOMAIN_ROUTER_V1
   Fast deterministic shortlist for obvious requests.
   MiniLM remains the fallback for ambiguous text.
   ========================================================== */

(() => {
    "use strict";

    const normalise = (value) =>
        String(value || "")
            .replace(/\s*>\s*/g, " > ")
            .replace(/\s+/g, " ")
            .trim()
            .toLowerCase();

    const rules = [
        {
            pattern: /\b(salary|salary credit|salary not received|salary not credited|wages?|pay not received|pay not credited)\b/i,
            category: "Payroll > Salary",
            reason: "Salary and pay vocabulary strongly matches Payroll > Salary."
        },
        {
            pattern: /\b(reimbursement|expense reimbursement|expense claim)\b/i,
            category: "Payroll > Reimbursement",
            reason: "Reimbursement vocabulary strongly matches Payroll > Reimbursement."
        },
        {
            pattern: /\b(payslip|pay slip|salary slip)\b/i,
            category: "Payroll > Payslip",
            reason: "Payslip vocabulary strongly matches Payroll > Payslip."
        },
        {
            pattern: /\b(wi[\s-]?fi|wireless network)\b/i,
            category: "IT > Network > Wi-Fi",
            reason: "Wi-Fi vocabulary strongly matches IT > Network > Wi-Fi."
        },
        {
            pattern: /\b(account lockout|account locked|locked out of my account)\b/i,
            category: "IT > Identity & Access > Account Lockout",
            reason: "Account-lockout vocabulary strongly matches the access category."
        }
    ];

    document.addEventListener(
        "DOMContentLoaded",
        () => {

            const button =
                document.getElementById(
                    "suggest-button"
                );

            const name =
                document.getElementById(
                    "name"
                );

            const content =
                document.getElementById(
                    "content"
                );

            const category =
                document.getElementById(
                    "category"
                );

            const suggestions =
                document.getElementById(
                    "suggestions"
                );

            if (
                !button ||
                !name ||
                !content ||
                !category ||
                !suggestions
            ) {
                return;
            }

            button.addEventListener(
                "click",
                (event) => {

                    const text =
                        `${name.value} ${content.value}`
                            .trim();

                    const rule =
                        rules.find(
                            (candidate) =>
                                candidate.pattern.test(
                                    text
                                )
                        );

                    if (!rule) {
                        /*
                         * No high-signal deterministic match.
                         * Allow the existing MiniLM suggestion
                         * handler to run normally.
                         */
                        return;
                    }

                    const wanted =
                        normalise(
                            rule.category
                        );

                    const option =
                        Array.from(
                            category.options
                        ).find((candidate) => {

                            const label =
                                normalise(
                                    candidate.textContent
                                );

                            return (
                                label === wanted ||
                                label.endsWith(
                                    wanted
                                )
                            );
                        });

                    if (!option) {
                        /*
                         * Category not available in this
                         * environment. Fall through to MiniLM.
                         */
                        return;
                    }

                    event.preventDefault();
                    event.stopImmediatePropagation();

                    suggestions.innerHTML = "";

                    const card =
                        document.createElement(
                            "div"
                        );

                    card.className =
                        "pd57-fast-suggestion";

                    const label =
                        document.createElement(
                            "div"
                        );

                    label.className =
                        "pd57-fast-suggestion-label";

                    label.textContent =
                        "Strong domain match";

                    const title =
                        document.createElement(
                            "strong"
                        );

                    title.textContent =
                        rule.category;

                    const explanation =
                        document.createElement(
                            "p"
                        );

                    explanation.textContent =
                        `${rule.reason} You still confirm the category before routing.`;

                    const use =
                        document.createElement(
                            "button"
                        );

                    use.type = "button";

                    use.textContent =
                        "Use this category";

                    use.addEventListener(
                        "click",
                        () => {

                            category.value =
                                option.value;

                            category.dispatchEvent(
                                new Event(
                                    "change",
                                    {
                                        bubbles: true
                                    }
                                )
                            );

                            use.textContent =
                                "Category selected";
                        }
                    );

                    card.append(
                        label,
                        title,
                        explanation,
                        use
                    );

                    suggestions.appendChild(
                        card
                    );
                },
                true
            );
        }
    );
})();