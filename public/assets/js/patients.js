(function () {
    var dataNode = document.querySelector("#patients-move-data");
    var moveModal = document.querySelector("#move-modal");
    var cancelModal = document.querySelector("#cancel-modal");
    var holdModal = document.querySelector("#hold-modal");
    if (!dataNode || !moveModal || !cancelModal) {
        return;
    }

    var data = JSON.parse(dataNode.textContent);
    var slotMap = data.slots || {};
    var openDays = data.days || [];
    var openSet = {};
    openDays.forEach(function (day) {
        openSet[day.value] = day;
    });

    var monthLabel = document.querySelector("#move-month-label");
    var calGrid = document.querySelector("#move-cal-grid");
    var timesBox = document.querySelector("#move-times");
    var moveDate = document.querySelector("#move-date");
    var moveTime = document.querySelector("#move-time");
    var moveId = document.querySelector("#move-id");
    var moveNote = document.querySelector("#move-modal-note");
    var moveSubmit = document.querySelector("#move-submit");
    var cancelId = document.querySelector("#cancel-id");
    var cancelCopy = document.querySelector("#cancel-modal-copy");
    var holdId = document.querySelector("#hold-id");
    var holdNote = document.querySelector("#hold-modal-note");
    var visibleMonth = null;
    var currentTime = "";

    function parseYmd(value) {
        var parts = String(value || "").split("-");
        if (parts.length !== 3) {
            return null;
        }
        return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    }

    function ymd(date) {
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, "0");
        var d = String(date.getDate()).padStart(2, "0");
        return y + "-" + m + "-" + d;
    }

    function monthKey(date) {
        return date.getFullYear() + "-" + String(date.getMonth() + 1).padStart(2, "0");
    }

    function availableMonths() {
        var keys = [];
        var seen = {};
        openDays.forEach(function (day) {
            var key = String(day.value).slice(0, 7);
            if (!seen[key]) {
                seen[key] = true;
                keys.push(key);
            }
        });
        keys.sort();
        return keys;
    }

    function setSubmitState() {
        moveSubmit.disabled = !(moveDate.value && moveTime.value);
    }

    function renderTimes(dateValue) {
        timesBox.replaceChildren();
        var slots = slotMap[dateValue] || [];
        if (slots.length === 0) {
            var empty = document.createElement("p");
            empty.className = "muted";
            empty.textContent = "No open times on this day.";
            timesBox.appendChild(empty);
            moveTime.value = "";
            setSubmitState();
            return;
        }

        slots.forEach(function (slot) {
            var label = document.createElement("label");
            label.className = "move-time-chip";
            var input = document.createElement("input");
            input.type = "radio";
            input.name = "move_time_choice";
            input.value = slot.value;
            if (slot.value === currentTime || slot.value === moveTime.value) {
                input.checked = true;
                moveTime.value = slot.value;
            }
            input.addEventListener("change", function () {
                moveTime.value = slot.value;
                setSubmitState();
            });
            var text = document.createElement("span");
            text.textContent = slot.label;
            label.appendChild(input);
            label.appendChild(text);
            timesBox.appendChild(label);
        });

        if (!moveTime.value && slots[0]) {
            var first = timesBox.querySelector("input");
            if (first) {
                first.checked = true;
                moveTime.value = slots[0].value;
            }
        }
        setSubmitState();
    }

    function renderCalendar() {
        if (!visibleMonth) {
            return;
        }
        var year = visibleMonth.getFullYear();
        var month = visibleMonth.getMonth();
        monthLabel.textContent = visibleMonth.toLocaleString("en-US", {
            month: "long",
            year: "numeric"
        });

        calGrid.replaceChildren();
        ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"].forEach(function (name) {
            var head = document.createElement("div");
            head.className = "move-cal-head";
            head.textContent = name;
            calGrid.appendChild(head);
        });

        var first = new Date(year, month, 1);
        var startPad = first.getDay();
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        var i;
        for (i = 0; i < startPad; i += 1) {
            calGrid.appendChild(document.createElement("div"));
        }
        for (i = 1; i <= daysInMonth; i += 1) {
            var date = new Date(year, month, i);
            var value = ymd(date);
            var button = document.createElement("button");
            button.type = "button";
            button.className = "move-cal-day";
            button.textContent = String(i);
            if (openSet[value]) {
                button.classList.add("is-open");
                if (value === moveDate.value) {
                    button.classList.add("is-selected");
                    button.setAttribute("aria-current", "date");
                }
                button.addEventListener("click", function (chosen) {
                    return function () {
                        moveDate.value = chosen;
                        currentTime = "";
                        moveTime.value = "";
                        renderCalendar();
                        renderTimes(chosen);
                    };
                }(value));
            } else {
                button.disabled = true;
                button.classList.add("is-muted");
            }
            calGrid.appendChild(button);
        }

        var months = availableMonths();
        var current = monthKey(visibleMonth);
        var index = months.indexOf(current);
        document.querySelector("#move-prev-month").disabled = index <= 0;
        document.querySelector("#move-next-month").disabled = index < 0 || index >= months.length - 1;
    }

    function openMove(button) {
        moveId.value = button.getAttribute("data-id") || "";
        moveDate.value = button.getAttribute("data-date") || "";
        currentTime = button.getAttribute("data-time") || "";
        var timeLabel = button.getAttribute("data-time-label") || currentTime;
        moveTime.value = "";
        moveNote.textContent = "Change time for " + (button.getAttribute("data-patient") || "this visit") + ".";

        // Keep this visit's current slot choosable while rescheduling.
        if (moveDate.value && currentTime) {
            var daySlots = (slotMap[moveDate.value] || []).slice();
            var hasCurrent = daySlots.some(function (slot) {
                return slot.value === currentTime;
            });
            if (!hasCurrent) {
                daySlots.push({ value: currentTime, label: timeLabel });
                daySlots.sort(function (a, b) {
                    return String(a.value).localeCompare(String(b.value));
                });
                slotMap[moveDate.value] = daySlots;
            }
            moveTime.value = currentTime;
        }

        var start = parseYmd(moveDate.value) || parseYmd((openDays[0] || {}).value);
        visibleMonth = start ? new Date(start.getFullYear(), start.getMonth(), 1) : new Date();
        if (!openSet[moveDate.value] && openDays[0]) {
            moveDate.value = openDays[0].value;
            currentTime = "";
            moveTime.value = "";
        }
        renderCalendar();
        renderTimes(moveDate.value);
        moveModal.showModal();
    }

    function openCancel(button) {
        cancelId.value = button.getAttribute("data-id") || "";
        var patient = button.getAttribute("data-patient") || "this patient";
        var when = button.getAttribute("data-when") || "";
        cancelCopy.textContent = "Are you sure you want to cancel this appointment for " + patient + (when ? " (" + when + ")" : "") + "?";
        cancelModal.showModal();
    }

    function openHold(button) {
        if (!holdModal || !holdId) {
            return;
        }
        holdId.value = button.getAttribute("data-id") || "";
        var patient = button.getAttribute("data-patient") || "this patient";
        var when = button.getAttribute("data-when") || "";
        if (holdNote) {
            holdNote.textContent = "Original visit: " + patient + (when ? " · " + when : "") + " (not confirmed yet).";
        }
        var checked = holdModal.querySelector("input[name='waitlist_id']:checked");
        if (!checked) {
            var first = holdModal.querySelector("input[name='waitlist_id']");
            if (first) {
                first.checked = true;
            }
        }
        holdModal.showModal();
    }

    function closeModal(dialog) {
        if (dialog && dialog.open) {
            dialog.close();
        }
    }

    document.querySelectorAll("[data-open-move]").forEach(function (button) {
        button.addEventListener("click", function () {
            openMove(button);
        });
    });
    document.querySelectorAll("[data-open-cancel]").forEach(function (button) {
        button.addEventListener("click", function () {
            openCancel(button);
        });
    });
    document.querySelectorAll("[data-open-hold]").forEach(function (button) {
        button.addEventListener("click", function () {
            openHold(button);
        });
    });
    document.querySelectorAll("[data-close-modal]").forEach(function (button) {
        button.addEventListener("click", function () {
            closeModal(button.closest("dialog"));
        });
    });
    [moveModal, cancelModal, holdModal].forEach(function (dialog) {
        if (!dialog) {
            return;
        }
        dialog.addEventListener("click", function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });

    document.querySelector("#move-prev-month").addEventListener("click", function () {
        var months = availableMonths();
        var index = months.indexOf(monthKey(visibleMonth));
        if (index > 0) {
            var parts = months[index - 1].split("-");
            visibleMonth = new Date(Number(parts[0]), Number(parts[1]) - 1, 1);
            renderCalendar();
        }
    });
    document.querySelector("#move-next-month").addEventListener("click", function () {
        var months = availableMonths();
        var index = months.indexOf(monthKey(visibleMonth));
        if (index >= 0 && index < months.length - 1) {
            var parts = months[index + 1].split("-");
            visibleMonth = new Date(Number(parts[0]), Number(parts[1]) - 1, 1);
            renderCalendar();
        }
    });
})();
