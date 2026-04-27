document.addEventListener('DOMContentLoaded', function () {
  if (typeof QRCode === 'undefined') {
    return;
  }

  var nodes = document.querySelectorAll('.corexa-js-qr');

  nodes.forEach(function (node) {
    var payload = node.getAttribute('data-qr') || '';
    var size = parseInt(node.getAttribute('data-size') || '220', 10);

    if (!payload) {
      return;
    }

    if (!size || size < 120) size = 220;
    if (size > 600) size = 600;

    node.innerHTML = '';

    new QRCode(node, {
      text: payload,
      width: size,
      height: size,
      correctLevel: QRCode.CorrectLevel.M
    });
  });
});