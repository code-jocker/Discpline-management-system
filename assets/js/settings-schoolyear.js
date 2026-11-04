document.addEventListener('DOMContentLoaded', function () {
  const card = document.getElementById('academic-years-card');
  if (!card) return;

  card.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    const form = ev.target.closest('form');
    if (!form) return;

    const opInput = form.querySelector('input[name="op"]');
    const nameInput = form.querySelector('input[name="name"]');
    const idInput = form.querySelector('input[name="id"]');
    const op = opInput ? opInput.value : '';
    const label = nameInput ? nameInput.value : (idInput ? idInput.value : '');

    // Confirm for destructive or important actions
    const needConfirm = ['archive', 'lock', 'activate', 'reopen', 'unlock'].includes(op);
    if (needConfirm) {
      const ok = confirm(`Are you sure you want to ${op} "${label}"?`);
      if (!ok) return;
    }

    // Disable submit buttons in the form
    const buttons = form.querySelectorAll('button');
    buttons.forEach(b => b.disabled = true);

    try {
      const formData = new FormData(form);
      // ensure action is posted
      if (!formData.has('action')) formData.append('action', 'manage_school_year');

      const resp = await fetch(window.location.href, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });

      if (resp.ok) {
        // simple approach: reload to reflect changes
        window.location.reload();
      } else {
        alert('Operation failed. Server returned an error.');
      }
    } catch (err) {
      console.error(err);
      alert('Operation failed. See console for details.');
    } finally {
      buttons.forEach(b => b.disabled = false);
    }
  }, true);
});
