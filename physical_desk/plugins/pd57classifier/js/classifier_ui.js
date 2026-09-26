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
