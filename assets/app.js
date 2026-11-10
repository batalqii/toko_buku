document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.querySelector('.menu-toggle');
  const nav = document.querySelector('.main-nav');
  if (toggle && nav) toggle.addEventListener('click', () => nav.classList.toggle('open'));

  document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
      if (!window.confirm(form.dataset.confirm || 'Lanjutkan tindakan ini?')) event.preventDefault();
    });
  });

  document.querySelectorAll('input[type="number"]').forEach(input => {
    input.addEventListener('change', () => {
      const min = input.min === '' ? null : Number(input.min);
      const max = input.max === '' ? null : Number(input.max);
      let value = Number(input.value);
      if (!Number.isFinite(value)) value = min ?? 0;
      if (min !== null) value = Math.max(min, value);
      if (max !== null) value = Math.min(max, value);
      input.value = String(value);
    });
  });
});
