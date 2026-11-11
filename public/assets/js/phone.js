(function () {
    function formatPhoneDigits(digits) {
        if (digits.length === 0) {
            return "";
        }
        if (digits.length < 4) {
            return "(" + digits;
        }
        if (digits.length < 7) {
            return "(" + digits.slice(0, 3) + ") " + digits.slice(3);
        }
        return "(" + digits.slice(0, 3) + ") " + digits.slice(3, 6) + "-" + digits.slice(6);
    }

    function bindPhoneMask(input) {
        function applyMask() {
            var digits = input.value.replace(/\D+/g, "").slice(0, 10);
            input.value = formatPhoneDigits(digits);
        }

        input.setAttribute("maxlength", "14");
        input.setAttribute("inputmode", "tel");
        input.addEventListener("input", applyMask);
        input.addEventListener("blur", applyMask);
        if (input.value) {
            applyMask();
        }
    }

    document.querySelectorAll(
        "input[name='phone'], input[name='patient_phone'], input[name='lookup_phone']"
    ).forEach(bindPhoneMask);
}());
