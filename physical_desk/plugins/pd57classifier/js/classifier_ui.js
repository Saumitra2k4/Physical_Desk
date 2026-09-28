/**
 * PD57 Classifier — UI JavaScript
 *
 * Handles the confirm/override button interactions on the AI Suggestions tab.
 * Uses GLPI's built-in jQuery and CSRF token handling.
 */
(function() {
    'use strict';

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.pd57-confirm-btn');
        if (!btn) return;

        e.preventDefault();

        var suggestionId = btn.getAttribute('data-suggestion-id');
        var ticketId     = btn.getAttribute('data-ticket-id');
        var categoryId   = btn.getAttribute('data-category-id');

        if (!confirm('Confirm this AI category suggestion?\n\nThe ticket category will be updated accordingly.')) {
            return;
        }

        btn.disabled = true;
        btn.textContent = '⏳ Applying...';

        var formData = new FormData();
        formData.append('action', 'confirm');
        formData.append('suggestion_id', suggestionId);
        formData.append('ticket_id', ticketId);
        formData.append('category_id', categoryId);

        // Add CSRF token if available
        var csrfInput = document.querySelector('input[name="_glpi_csrf_token"]');
        var csrfMeta = document.querySelector('meta[property="glpi:csrf_token"]');
        var csrfToken = csrfInput ? csrfInput.value : (csrfMeta ? csrfMeta.content : '');
        if (csrfToken) {
            formData.append('_glpi_csrf_token', csrfToken);
        }

        fetch(CFG_GLPI.root_doc + '/plugins/pd57classifier/ajax/suggestion_action.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                btn.textContent = '✅ Confirmed';
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-success');

                // Disable all other confirm buttons (only one can be confirmed)
                document.querySelectorAll('.pd57-confirm-btn').forEach(function(otherBtn) {
                    otherBtn.disabled = true;
                });

                // Refresh the page after a short delay to show updated category
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                btn.disabled = false;
                btn.textContent = '✅ Confirm';
                alert('Error: ' + (data.error || data.message || 'Unknown error'));
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = '✅ Confirm';
            alert('Network error: ' + err.message);
        });
    });
})();

/* ==========================================================
   PD57_AI_PRODUCTIZATION_V1
   ========================================================== */

(() => {
    "use strict";

    const polishPd57Ai = () => {

        document
            .querySelectorAll("h3")
            .forEach((heading) => {

                const text =
                    (heading.textContent || "").trim();

                if (
                    !text.includes(
                        "AI Category Suggestions"
                    )
                ) {
                    return;
                }

                heading.textContent =
                    "AI Category Suggestions";

                const root =
                    heading.closest(
                        ".card, .tab-pane, .tab_cadre_fixe"
                    )
                    || heading.parentElement;

                if (root) {
                    root.classList.add(
                        "pd57-ai-polished"
                    );
                }
            });

        /*
         * Preserve the useful Answer workflow.
         *
         * Do not simply rename Cancel ticket to Resolved,
         * because its behavior would still mean cancellation.
         * It stays hidden until the actual employee-confirmed
         * resolution/escalation loop is implemented.
         */
        document
            .querySelectorAll(
                "a, button, [role='button']"
            )
            .forEach((element) => {

                const text =
                    (element.textContent || "")
                        .trim()
                        .toLowerCase();

                if (text === "cancel ticket") {

                    element.classList.add(
                        "pd57-native-cancel-hidden"
                    );

                    element.setAttribute(
                        "aria-hidden",
                        "true"
                    );
                }
            });
    };

    document.addEventListener(
        "DOMContentLoaded",
        polishPd57Ai
    );

    const observer =
        new MutationObserver(
            polishPd57Ai
        );

    observer.observe(
        document.documentElement,
        {
            childList: true,
            subtree: true
        }
    );

    polishPd57Ai();
})();
/* ==========================================================
   PD57_NATIVE_RESOLUTION_AND_RERANK_V1
   ========================================================== */

(() => {
    "use strict";

    const addResolveAction = () => {

        if (
            !location.pathname.includes(
                "/front/ticket.form.php"
            )
        ) {
            return;
        }

        if (
            document.getElementById(
                "pd57-native-resolve"
            )
        ) {
            return;
        }

        const id =
            new URLSearchParams(
                location.search
            ).get("id");

        if (!id) {
            return;
        }

        const answer =
            Array.from(
                document.querySelectorAll(
                    "a, button"
                )
            ).find(
                (element) =>
                    (element.textContent || "")
                        .trim()
                        .toLowerCase()
                    === "answer"
            );

        if (!answer) {
            return;
        }

        const link =
            document.createElement(
                "a"
            );

        link.id =
            "pd57-native-resolve";

        link.href =
            `/plugins/pd57portal/front/ticket.php?id=${encodeURIComponent(id)}`;

        link.textContent =
            "Resolve request";

        link.className =
            `${answer.className || ""} ms-2`
                .trim();

        link.style.textDecoration =
            "none";

        answer.insertAdjacentElement(
            "afterend",
            link
        );
    };


    const rerankSalary = () => {

        const heading =
            Array.from(
                document.querySelectorAll(
                    "h3"
                )
            ).find(
                (element) =>
                    (element.textContent || "")
                        .includes(
                            "AI Category Suggestions"
                        )
            );

        if (!heading) {
            return;
        }

        const panel =
            heading.closest(
                ".pd57-ai-polished, .card, .tab-pane, .tab_cadre_fixe"
            )
            || heading.parentElement;

        if (!panel) {
            return;
        }

        /*
         * Build context without the AI panel itself, otherwise
         * the candidate row text could trigger the rule.
         */
        const bodyClone =
            document.body.cloneNode(
                true
            );

        bodyClone
            .querySelectorAll(
                ".pd57-ai-polished"
            )
            .forEach(
                (element) =>
                    element.remove()
            );

        const context =
            (bodyClone.textContent || "")
                .toLowerCase();

        if (
            !/\b(salary|salary not received|salary not credited|wages?|pay not received|pay not credited)\b/i.test(
                context
            )
        ) {
            return;
        }

        const tbody =
            panel.querySelector(
                "tbody"
            );

        if (!tbody) {
            return;
        }

        const rows =
            Array.from(
                tbody.querySelectorAll(
                    "tr"
                )
            );

        const payrollSalary =
            rows.find((row) => {

                const text =
                    (row.textContent || "")
                        .toLowerCase();

                return (
                    text.includes(
                        "payroll"
                    ) &&
                    text.includes(
                        "salary"
                    )
                );
            });

        if (!payrollSalary) {
            return;
        }

        if (
            tbody.firstElementChild !==
            payrollSalary
        ) {
            tbody.insertBefore(
                payrollSalary,
                tbody.firstElementChild
            );
        }

        payrollSalary.classList.add(
            "pd57-domain-priority"
        );

        const firstCell =
            payrollSalary.querySelector(
                "td"
            );

        if (
            firstCell &&
            !firstCell.querySelector(
                ".pd57-domain-match-label"
            )
        ) {

            const badge =
                document.createElement(
                    "span"
                );

            badge.className =
                "pd57-domain-match-label";

            badge.textContent =
                "Strong domain match";

            badge.style.display =
                "block";

            badge.style.marginTop =
                "5px";

            badge.style.fontSize =
                "10px";

            badge.style.fontWeight =
                "700";

            badge.style.letterSpacing =
                ".04em";

            badge.style.color =
                "#167c92";

            firstCell.appendChild(
                badge
            );
        }
    };


    const run = () => {

        addResolveAction();

        rerankSalary();
    };


    document.addEventListener(
        "DOMContentLoaded",
        () => {

            run();

            setTimeout(
                run,
                400
            );

            setTimeout(
                run,
                1200
            );
        }
    );
})();