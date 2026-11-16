(function () {
    var node = document.querySelector("#waitlist-slots");
    var placeModal = document.querySelector("#place-modal");
    var removeModal = document.querySelector("#remove-modal");
    var placeId = document.querySelector("#place-id");
    var placeNote = document.querySelector("#place-modal-note");
    var placeDate = document.querySelector("#place-date");
    var placePlacement = document.querySelector("#place-placement");
    var removeId = document.querySelector("#remove-id");
    var removeCopy = document.querySelector("#remove-modal-copy");

    var slotMap = {};
    if (node) {
        try {
            slotMap = JSON.parse(node.textContent) || {};
        } catch (error) {
            slotMap = {};
        }
    }

    function fillPlacements(select, slots) {
        if (!select) {
            return;
        }
        select.replaceChildren();
        (slots || []).forEach(function (slot) {
            var option = document.createElement("option");
            option.value = slot.value;
            option.textContent = slot.label;
            select.appendChild(option);
        });
        if (slots && slots[0]) {
            select.value = slots[0].value;
        }
    }

    function openPlace(button) {
        if (!placeModal || !placeId) {
            return;
        }
        placeId.value = button.getAttribute("data-id") || "";
        var patient = button.getAttribute("data-patient") || "this person";
        var visit = button.getAttribute("data-visit") || "";
        if (placeNote) {
            placeNote.textContent = "Placing " + patient + (visit ? " · " + visit : "") + ".";
        }
        if (placeDate && placePlacement) {
            var first = placeDate.options[0];
            if (first) {
                placeDate.value = first.value;
                fillPlacements(placePlacement, slotMap[placeDate.value] || []);
            }
        }
        placeModal.showModal();
    }

    function openRemove(button) {
        if (!removeModal || !removeId) {
            return;
        }
        removeId.value = button.getAttribute("data-id") || "";
        var patient = button.getAttribute("data-patient") || "this person";
        if (removeCopy) {
            removeCopy.textContent = "Are you sure you want to remove " + patient + " from the waitlist?";
        }
        removeModal.showModal();
    }

    function closeModal(dialog) {
        if (dialog && dialog.open) {
            dialog.close();
        }
    }

    if (placeDate && placePlacement) {
        placeDate.addEventListener("change", function () {
            fillPlacements(placePlacement, slotMap[placeDate.value] || []);
        });
    }

    document.querySelectorAll("[data-open-place]").forEach(function (button) {
        button.addEventListener("click", function () {
            openPlace(button);
        });
    });
    document.querySelectorAll("[data-open-remove]").forEach(function (button) {
        button.addEventListener("click", function () {
            openRemove(button);
        });
    });
    document.querySelectorAll("[data-close-modal]").forEach(function (button) {
        button.addEventListener("click", function () {
            closeModal(button.closest("dialog"));
        });
    });
    [placeModal, removeModal].forEach(function (dialog) {
        if (!dialog) {
            return;
        }
        dialog.addEventListener("click", function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });
})();
