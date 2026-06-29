(function () {
  function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      ok ? resolve() : reject(new Error('copy failed'));
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.sfx-copy-placeholder-btn');
    if (!btn) return;
    e.preventDefault();
    var value = btn.getAttribute('data-copy-value') || '';
    var labelCopy = btn.getAttribute('data-label-copy') || 'Copy';
    var labelCopied = btn.getAttribute('data-label-copied') || 'Copied';
    btn.disabled = true;
    copyToClipboard(value)
      .then(function () {
        btn.textContent = labelCopied;
        btn.classList.add('copied');
        setTimeout(function () {
          btn.textContent = labelCopy;
          btn.classList.remove('copied');
          btn.disabled = false;
        }, 2000);
      })
      .catch(function () {
        btn.disabled = false;
      });
  });
})();
