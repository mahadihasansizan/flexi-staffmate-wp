(function ($) {
    'use strict';

    function slugify(value) {
        return String(value || '')
            .toLowerCase()
            .trim()
            .replace(/[\s\-]+/g, '_')
            .replace(/[^a-z0-9_]/g, '')
            .replace(/^_+|_+$/g, '')
            .substring(0, 45);
    }

    function updatePreview($row) {
        var key = slugify($row.find('.membership-level-key').val());
        $row.find('.membership-role-preview').text(key ? 'membership_' + key : 'membership_');
    }

    $(document).on('click', '[data-membership-add-level]', function (event) {
        event.preventDefault();

        var template = $('#membership-level-row-template').html();
        if (!template) {
            return;
        }

        var index = 'new_' + Date.now();
        template = template.replace(/__index__/g, index);

        $('[data-membership-level-rows]').find('.membership-empty-row').remove();
        $('[data-membership-level-rows]').append(template);
    });

    $(document).on('click', '[data-membership-remove-row]', function (event) {
        event.preventDefault();

        var message = (window.MembershipAdmin && MembershipAdmin.confirmDelete) ? MembershipAdmin.confirmDelete : 'Remove this membership level?';
        if (!window.confirm(message)) {
            return;
        }

        $(this).closest('tr').remove();

        var $tbody = $('[data-membership-level-rows]');
        if (!$tbody.children('tr').length) {
            $tbody.append('<tr class="membership-empty-row"><td colspan="8">No levels yet. Click Add level to create one.</td></tr>');
        }
    });

    $(document).on('input change', '.membership-level-key', function () {
        updatePreview($(this).closest('tr'));
    });

    $(function () {
        $('.membership-level-row').each(function () {
            updatePreview($(this));
        });
    });
})(jQuery);
