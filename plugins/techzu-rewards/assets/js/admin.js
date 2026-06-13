document.addEventListener('click', function (event) {
    var addButton = event.target.closest('[data-repeater-add]');
    if (addButton) {
        var type = addButton.getAttribute('data-repeater-add');
        var wrapper = addButton.closest('[data-repeater]');
        if (!wrapper) {
            return;
        }

        var template = wrapper.querySelector('template[data-repeater-template="' + type + '"]');
        var body = wrapper.querySelector('[data-repeater-body]');
        var nextIndex = parseInt(wrapper.getAttribute('data-next-index'), 10) || 0;

        if (!template || !body) {
            return;
        }

        var html = template.innerHTML.replace(/__index__/g, nextIndex);
        body.insertAdjacentHTML('beforeend', html);
        wrapper.setAttribute('data-next-index', String(nextIndex + 1));
    }

    var removeButton = event.target.closest('[data-repeater-remove]');
    if (removeButton) {
        var row = removeButton.closest('[data-repeater-row]');
        if (row) {
            row.remove();
        }
    }
});
