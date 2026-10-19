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
            label.className = "pill";
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

    document.querySelectorAll("form").forEach(function (form) {
        if (!form.querySelector(".time-pills")) {
            return;
        }
        form.addEventListener("change", function (event) {
            if (event.target.name === "appointment_date") {
                renderTimes(form);
                form.dispatchEvent(new Event("input"));
            }
        });
    });

    var form = document.querySelector(".book-card");
    var list = document.querySelector("#party-list");
    var addButton = document.querySelector("#add-person");
    var template = document.querySelector("#party-template");
    var note = document.querySelector("#party-note");
    var plan = document.querySelector("#party-plan");
    var nameField = document.querySelector("[name='patient_name']");

    if (!form || !list || !addButton || !template || !note || !plan) {
        return;
    }

    function selectedTime() {
        var chosen = form.querySelector("input[name='appointment_time']:checked");
        return chosen ? chosen.value : "";
    }

    function fitCount(start) {
        var slots = slotsFor(form);
        var index = slots.findIndex(function (slot) {
            return slot.value === start;
        });
        if (index < 0 || !slots[index].available) {
            return 0;
        }
        var count = 0;
        for (var position = index; position < slots.length; position += 1) {
            if (!slots[position].available) {
                break;
            }
            count += 1;
        }
        return count;
    }

    function blocksFrom(start, count) {
        var slots = slotsFor(form);
        var index = slots.findIndex(function (slot) {
            return slot.value === start;
        });
        var blocks = [];
        if (index < 0) {
            return blocks;
        }
        for (var step = 0; step < count && index + step < slots.length; step += 1) {
            if (!slots[index + step].available) {
                break;
            }
            blocks.push(slots[index + step]);
        }
        return blocks;
    }

    function partyFields() {
        return Array.prototype.slice.call(list.querySelectorAll("input"));
    }

    function refresh() {
        var fit = fitCount(selectedTime());
        var people = 1 + partyFields().length;
        addButton.disabled = people >= fit;
        note.textContent = people > fit
            ? (fit === 1 ? form.dataset.fitOne : form.dataset.fitMany.replace("2", String(fit)))
            : form.dataset.hint;

        var names = [nameField ? nameField.value.trim() : ""].concat(partyFields().map(function (field) {
            return field.value.trim();
        }));
        plan.replaceChildren();
        blocksFrom(selectedTime(), names.length).forEach(function (slot, index) {
            var item = document.createElement("li");
            var label = names[index] !== "" ? names[index] : form.dataset.another;
            item.textContent = slot.label + " · " + label;
            plan.appendChild(item);
        });
    }

    addButton.addEventListener("click", function () {
        if (addButton.disabled) {
            refresh();
            return;
        }
        var card = template.content.firstElementChild.cloneNode(true);
        list.appendChild(card);
        var field = card.querySelector("input");
        if (field) {
            field.focus();
        }
        refresh();
    });

    list.addEventListener("click", function (event) {
        var button = event.target.closest("[data-remove-person]");
        if (!button) {
            return;
        }
        var card = button.closest(".party-card");
        if (card) {
            card.remove();
        }
        refresh();
    });

    form.addEventListener("input", refresh);
    form.addEventListener("change", refresh);
    refresh();
}());
