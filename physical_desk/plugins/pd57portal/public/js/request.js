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
