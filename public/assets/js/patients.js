(function () {
    var node = document.querySelector("#upcoming-slots");
    if (!node) {
        return;
    }

    var slotMap = JSON.parse(node.textContent);

    function fillTimes(select, slots, current) {
        select.replaceChildren();
        slots.forEach(function (slot) {
            var option = document.createElement("option");
            option.value = slot.value;
            option.textContent = slot.label;
            if (slot.value === current) {
                option.selected = true;
            }
            select.appendChild(option);
        });
        if (!select.value && slots[0]) {
            select.value = slots[0].value;
        }
    }

    document.querySelectorAll("[data-move-form]").forEach(function (form) {
        var date = form.querySelector("[name='date']");
        var time = form.querySelector("[name='time']");
        if (!date || !time) {
            return;
        }
        date.addEventListener("change", function () {
            fillTimes(time, slotMap[date.value] || [], time.dataset.current || "");
        });
    });
})();
