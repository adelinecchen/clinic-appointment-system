(function () {
    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function parseTime(value) {
        var parts = String(value || '').split(':');
        if (parts.length < 2) {
            return null;
        }
        var hour = Number(parts[0]);
        var minute = Number(parts[1]);
        if (!Number.isFinite(hour) || !Number.isFinite(minute)) {
            return null;
        }
        return hour * 60 + minute;
    }

    function formatTime(minutes) {
        var hour = Math.floor(minutes / 60);
        var minute = minutes % 60;
        var suffix = hour >= 12 ? 'PM' : 'AM';
        var clock = hour % 12;
        if (clock === 0) {
            clock = 12;
        }
        return clock + ':' + pad(minute) + ' ' + suffix;
    }

    function buildSlots(start, end, block) {
        var cursor = parseTime(start);
        var close = parseTime(end);
        var size = Number(block);
        if (cursor === null || close === null || !Number.isFinite(size) || size < 1 || cursor >= close) {
            return [];
        }

        var slots = [];
        while (cursor + size <= close) {
            slots.push(formatTime(cursor));
            cursor += size;
        }
        return slots;
    }

    function updatePreview(form) {
        var start = form.querySelector('[name="start"]');
        var end = form.querySelector('[name="end"]');
        var block = form.querySelector('[name="block"]');
        var preview = form.querySelector('[data-hours-preview]');
        if (!start || !end || !block || !preview) {
            return;
        }

        var slots = buildSlots(start.value, end.value, block.value);
        if (slots.length === 0) {
            preview.textContent = 'Those hours do not make any visit blocks yet.';
            return;
        }

        var last = slots[slots.length - 1];
        var visitWord = slots.length === 1 ? 'visit' : 'visits';
        preview.textContent = 'This makes ' + slots.length + ' ' + visitWord + ': ' + slots.join(', ') + '. Last visit starts at ' + last + '.';
    }

    document.querySelectorAll('[data-hours-form]').forEach(function (form) {
        ['input', 'change'].forEach(function (eventName) {
            form.addEventListener(eventName, function () {
                updatePreview(form);
            });
        });
        updatePreview(form);
    });
})();
