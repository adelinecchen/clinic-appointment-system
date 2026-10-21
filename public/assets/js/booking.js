(function () {
    var slotNode = document.querySelector("#slot-data");
    if (!slotNode) {
        return;
    }

    var slotMap = JSON.parse(slotNode.textContent);

    function slotsFor(form) {
        var chosen = form.querySelector("input[name='appointment_date']:checked");
        var date = chosen ? chosen.value : "";
        if (slotMap[date]) {
            return slotMap[date];
        }
        var keys = Object.keys(slotMap);
        return keys.length ? slotMap[keys[0]] : [];
    }

    function renderTimes(form) {
        var container = form.querySelector(".time-pills");
        if (!container) {
            return;
        }
        var previous = form.querySelector("input[name='appointment_time']:checked");
        var previousValue = previous ? previous.value : "";
        var open = slotsFor(form).filter(function (slot) {
            return slot.available;
        });
        container.replaceChildren();
        open.forEach(function (slot) {
            var label = document.createElement("label");
            label.className = "time-chip";
            var input = document.createElement("input");
            input.type = "radio";
            input.name = "appointment_time";
            input.value = slot.value;
            if (slot.value === previousValue) {
                input.checked = true;
            }
            var text = document.createElement("span");
            text.textContent = slot.label;
            label.appendChild(input);
            label.appendChild(text);
            container.appendChild(label);
        });
        if (!container.querySelector("input:checked")) {
            var first = container.querySelector("input");
            if (first) {
                first.checked = true;
            }
        }
    }

    function showMonth(cal, value) {
        var months = Array.prototype.slice.call(cal.querySelectorAll("[data-month]"));
        var label = cal.querySelector("[data-cal-label]");
        var prev = cal.querySelector("[data-cal-prev]");
        var next = cal.querySelector("[data-cal-next]");
        var index = months.findIndex(function (month) {
            return month.dataset.month === value;
        });
        if (index < 0) {
            index = 0;
            value = months[0] ? months[0].dataset.month : value;
        }
        months.forEach(function (month, position) {
            month.hidden = position !== index;
        });
        cal.dataset.visible = value;
        if (label && months[index]) {
            label.textContent = months[index].dataset.label;
        }
        if (prev) {
            prev.disabled = index <= 0;
        }
        if (next) {
            next.disabled = index >= months.length - 1;
        }
    }

    document.querySelectorAll(".book-cal").forEach(function (cal) {
        showMonth(cal, cal.dataset.visible);
        var prev = cal.querySelector("[data-cal-prev]");
        var next = cal.querySelector("[data-cal-next]");
        if (prev) {
            prev.addEventListener("click", function () {
                var months = Array.prototype.slice.call(cal.querySelectorAll("[data-month]"));
                var index = months.findIndex(function (month) {
                    return month.dataset.month === cal.dataset.visible;
                });
                if (index > 0) {
                    showMonth(cal, months[index - 1].dataset.month);
                }
            });
        }
        if (next) {
            next.addEventListener("click", function () {
                var months = Array.prototype.slice.call(cal.querySelectorAll("[data-month]"));
                var index = months.findIndex(function (month) {
                    return month.dataset.month === cal.dataset.visible;
                });
                if (index < months.length - 1) {
                    showMonth(cal, months[index + 1].dataset.month);
                }
            });
        }
    });

    document.querySelectorAll("form").forEach(function (form) {
        if (!form.querySelector(".time-pills")) {
            return;
        }
        form.addEventListener("change", function (event) {
            if (event.target.name === "appointment_date") {
                var cal = form.querySelector(".book-cal");
                if (cal) {
                    showMonth(cal, event.target.value.slice(0, 7));
                }
                renderTimes(form);
            }
        });
    });
}());
