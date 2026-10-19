(function () {
    var form = document.querySelector("#follow-form");
    var preview = document.querySelector("#sms-preview");
    var button = document.querySelector("#send-texts");
    var countLine = document.querySelector("#follow-count");
    var data = document.querySelector("#follow-messages");
    if (!form || !preview || !button || !countLine || !data) {
        return;
    }

    var messages = JSON.parse(data.textContent);

    function selected() {
        return Array.prototype.filter.call(form.querySelectorAll("input[type='checkbox']"), function (box) {
            return box.checked;
        });
    }

    function refresh() {
        var boxes = selected();
        var count = boxes.length;
        countLine.textContent = count === 1
            ? "1 patient has not confirmed."
            : count + " patients have not confirmed.";
        button.textContent = count === 1 ? "Send text to 1 patient" : "Send text to " + count + " patients";
        button.disabled = count === 0;
        var first = boxes[0];
        var match = messages.find(function (message) {
            return first && message.id === first.value;
        });
        preview.textContent = match ? match.text : "Choose who should get a text.";
    }

    form.addEventListener("change", refresh);
    refresh();
}());
