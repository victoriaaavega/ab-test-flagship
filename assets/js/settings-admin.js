(function () {
    'use strict';

    function toggleJsPath(provider) {
        var row = document.getElementById('abtf-js-path-row');
        if (row) {
            row.style.display = provider === 'custom' ? '' : 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var radios = document.querySelectorAll('input[name="visitor_id_provider"]');
        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                toggleJsPath(this.value);
            });
        });
    });
})();