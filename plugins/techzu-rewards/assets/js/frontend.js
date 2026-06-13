(function () {
    document.addEventListener('click', function (event) {
        var openButton = event.target.closest('[data-tz-rewards-open]');
        if (openButton) {
            var modal = document.getElementById(openButton.getAttribute('data-tz-rewards-open'));
            if (modal) {
                modal.removeAttribute('hidden');
            }
            return;
        }

        var closeButton = event.target.closest('[data-tz-rewards-close]');
        if (closeButton) {
            var openModal = closeButton.closest('.tz-rewards-modal');
            if (openModal) {
                openModal.setAttribute('hidden', 'hidden');
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('.tz-rewards-modal:not([hidden])').forEach(function (modal) {
            modal.setAttribute('hidden', 'hidden');
        });
    });
}());
