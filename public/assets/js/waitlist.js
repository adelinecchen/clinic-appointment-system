(function () {
    var node = document.querySelector("#waitlist-slots");
    if (!node) {
        return;
    }

    var slotMap = JSON.parse(node.textContent);

    function fillPlacements(select, slots) {
        select.replaceChildren();
        slots.forEach(function (slot) {
            var option = document.createElement("option");
            option.value = slot.value;
            option.textContent = slot.label;
            select.appendChild(option);
        });
        if (slots[0]) {
            select.value = slots[0].value;
        }
    }

    document.querySelectorAll("[data-waitlist-place]").forEach(function (form) {
        var date = form.querySelector("[name='date']");
        var placement = form.querySelector("[name='placement']");
        if (!date || !placement) {
            return;
        }

        date.addEventListener("change", function () {
            fillPlacements(placement, slotMap[date.value] || []);
        });
    });
})();
