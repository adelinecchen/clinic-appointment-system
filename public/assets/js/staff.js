(function () {
    var deleteModal = document.querySelector("#delete-modal");
    var deleteId = document.querySelector("#delete-id");
    var deleteCopy = document.querySelector("#delete-modal-copy");
    if (!deleteModal || !deleteId) {
        return;
    }

    function openDelete(button) {
        deleteId.value = button.getAttribute("data-id") || "";
        var name = button.getAttribute("data-name") || "this account";
        if (deleteCopy) {
            deleteCopy.textContent = "Are you sure you want to delete " + name + "'s staff account?";
        }
        deleteModal.showModal();
    }

    function closeModal(dialog) {
        if (dialog && dialog.open) {
            dialog.close();
        }
    }

    document.querySelectorAll("[data-open-delete]").forEach(function (button) {
        button.addEventListener("click", function () {
            openDelete(button);
        });
    });
    document.querySelectorAll("[data-close-modal]").forEach(function (button) {
        button.addEventListener("click", function () {
            closeModal(button.closest("dialog"));
        });
    });
    deleteModal.addEventListener("click", function (event) {
        if (event.target === deleteModal) {
            deleteModal.close();
        }
    });
})();
