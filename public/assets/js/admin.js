// BacaKomik admin JS
(function () {
  // Auto generate slug from title
  const title = document.querySelector('input[name="title"]');
  const slug  = document.querySelector('input[name="slug"]');
  if (title && slug) {
    title.addEventListener('blur', () => {
      if (!slug.value) slug.value = title.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    });
  }

  // Active card highlight on appearance picker
  document.querySelectorAll('.option-card input[type="radio"]').forEach(r => {
    r.addEventListener('change', () => {
      const name = r.name;
      document.querySelectorAll(`.option-card input[name="${name}"]`).forEach(x => {
        x.closest('.option-card').classList.toggle('active', x.checked);
      });
    });
  });
})();
