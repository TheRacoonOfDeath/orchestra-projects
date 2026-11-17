/*
 * The project form: the end date cannot be before the start date, and while it is empty (or
 * earlier) it takes the start date, so that its date picker opens in the month of the project
 * instead of today's. Everything also works without this script; the server checks the dates.
 */
(function () {
    'use strict';

    function follow(start, end) {
        end.min = start.value;
        if (start.value !== '' && (end.value === '' || end.value < start.value)) {
            end.value = start.value;
            // Counts as a change for orchestra-core's question about unsaved changes.
            end.dispatchEvent(new Event('orchestra:change', { bubbles: true }));
        }
    }

    document.querySelectorAll('[data-orchestra-projects-date="start"]').forEach(function (start) {
        var end = start.form && start.form.querySelector('[data-orchestra-projects-date="end"]');
        if (end) {
            start.addEventListener('change', function () {
                follow(start, end);
            });
        }
    });
})();
