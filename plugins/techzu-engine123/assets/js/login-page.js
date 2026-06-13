/**
 * Techzu Engine - Login Page Settings JavaScript
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        // Initialize color pickers
        $('.tz-color-picker').wpColorPicker();

        // Media upload buttons
        $(document).on('click', '.tz-upload-media-btn', function (e) {
            e.preventDefault();

            var button = $(this);
            var targetField = button.data('target');
            var $field = $('#' + targetField);

            // Create media frame
            var frame = wp.media({
                title: button.text(),
                button: {
                    text: 'Use this image',
                },
                multiple: false,
                library: {
                    type: 'image',
                },
            });

            // Handle image selection
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $field.val(attachment.url).trigger('change');

                // Update preview if it's a logo
                if (targetField === 'tz_login_logo_url') {
                    updateLogoPreview(attachment.url);
                }
            });

            frame.open();
        });

        // Update logo preview
        function updateLogoPreview(url) {
            var preview = $('.tz-logo-preview');
            var width = $('#tz_login_logo_width').val() || 84;

            preview.html(
                '<img src="' + url + '" style="max-width: ' + width + 'px; height: auto;" />'
            );
        }

        // Update logo preview when width changes
        $(document).on('change', '#tz_login_logo_width', function () {
            var url = $('#tz_login_logo_url').val();
            if (url) {
                updateLogoPreview(url);
            }
        });

        // Enable/disable customization toggle
        $(document).on('change', '#tz_login_enable', function () {
            var $form = $(this).closest('form');
            var isChecked = $(this).is(':checked');

            if (!isChecked) {
                $form.find('input, select').not('#tz_login_enable').prop('disabled', false);
            }
        });
    });
})(jQuery);
