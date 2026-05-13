// Reader JS — keyboard nav and auto-hide chrome on scroll
document.addEventListener('keydown', (e) => {
  const next = document.querySelector('.reader-nav-inner a:last-child');
  const prev = document.querySelector('.reader-nav-inner a:first-child');
  if (e.key === 'ArrowRight' && next?.href) location.href = next.href;
  if (e.key === 'ArrowLeft'  && prev?.href) location.href = prev.href;
});

// Lazy load fallback for older browsers
if (!('loading' in HTMLImageElement.prototype)) {
  const io = new IntersectionObserver((entries) => {
    entries.forEach(en => {
      if (en.isIntersecting) {
        const img = en.target;
        if (img.dataset.src) img.src = img.dataset.src;
        io.unobserve(img);
      }
    });
  });
  document.querySelectorAll('.reader-page').forEach(img => {
    img.dataset.src = img.src;
    img.removeAttribute('src');
    io.observe(img);
  });
}
