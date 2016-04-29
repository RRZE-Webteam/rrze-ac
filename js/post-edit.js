jQuery(document).ready(function ($) {

    var previous = $('#access-permission-select').val();

    $('.edit-post-protection, .save-post-protection, .cancel-post-protection').click(function (e) {

        e.preventDefault();

        if ($(this).hasClass('cancel-post-protection')) {
            $('#access-permission-select').val(previous);

        } else if ($(this).hasClass('save-post-protection')) {
            previous = $('#access-permission-select').val();
            $('#post-protection-label').text($('#access-permission-select option[value=' + $('#access-permission-select').val() + ']').text());
            if ($('#access-permission-select').val() == 'all') {
                $('#access-icon').removeClass('access-icon').addClass('access-all-icon');
            } else {
                $('#access-icon').removeClass('access-all-icon').addClass('access-icon');
            }
        }

        $('#post-protection-field').slideToggle('fast');

    });

});