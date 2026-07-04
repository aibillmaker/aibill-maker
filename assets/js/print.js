(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var printButton = document.querySelector('[data-aibill-print-page]');
    var closeButton = document.querySelector('[data-aibill-close-window]');

    if (printButton) {
      printButton.addEventListener('click', function () {
        window.print();
      });
    }

    if (closeButton) {
      closeButton.addEventListener('click', function () {
        window.close();
      });
    }
  });
})();
