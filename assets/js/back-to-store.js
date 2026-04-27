(function () {
  var title = document.querySelector('.title-not-breadcrumbs .container .page-title');
  if (!title) return;

  if (document.querySelector('.spg-back-to-store')) return;

  var container = title.closest('.container');
  if (container) container.classList.add('spg-ty-title-wrap');

  var url = (window.COREXA_BACK_TO_STORE && COREXA_BACK_TO_STORE.shop_url) ? COREXA_BACK_TO_STORE.shop_url : '';
  if (!url) return;

  var btn = document.createElement('a');
  btn.className = 'spg-back-to-store';
  btn.href = url;
  btn.textContent = 'back to store';

  if (container) container.appendChild(btn);
})();