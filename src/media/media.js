jQuery(document).ready(function ($) {
    "use strict";
    var input = $('input[name="access_protected"]'),
        ctrl = document.getElementById("access_protected"),
        ui = $("#plupload-upload-ui");

    function state(check) {
        return "access-" + (check == "on" ? "" : "un") + "checked";
    }

    input.on("change", function () {
        var check = ctrl.checked ? "on" : "off";
        ui.removeClass(state(check == "on" ? "off" : "on")).addClass(
            state(check)
        );

        wpUploaderInit.multipart_params.access_protected = check;
    });

    setTimeout(function () {
        input.change();
    }, 200);
});
