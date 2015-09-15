jQuery(document).ready(function ($) {

    var previous = $('#access_permission_select').val();

    $('.edit-post-protection, .save-post-protection, .cancel-post-protection').click(function (e) {

        e.preventDefault();

        if ($(this).hasClass('cancel-post-protection')) {
            $('#access_permission_select').val(previous);

        } else if ($(this).hasClass('save-post-protection')) {
            previous = $('#access_permission_select').val();
            $('#post-protection-label').text($('#access_permission_select option[value=' + $('#access_permission_select').val() + ']').text());
            if ($('#access_permission_select').val() == 'all') {
                $('#access-icon').removeClass('access-icon').addClass('access-all-icon');
            } else {
                $('#access-icon').removeClass('access-all-icon').addClass('access-icon');
            }
        }

        $('#post-protection-field').slideToggle('fast');

    });

});