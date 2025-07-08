jQuery(document).ready(function($) {
    var $multiSelect = $('#qp_label_filter_select');

    if ($multiSelect.length) {
        $multiSelect.hide();

        var $dropdown = $('<div class="qp-multi-select-dropdown"></div>');
        var $button = $('<button type="button" class="button">Select Labels</button>');
        var $list = $('<div class="qp-multi-select-list" style="display: none;"></div>');

        var updateButtonText = function() {
            var selectedTexts = [];
            $list.find('input:checked').each(function() {
                selectedTexts.push($(this).parent().text().trim());
            });
            if (selectedTexts.length === 0) {
                $button.text('Select Labels');
            } else if (selectedTexts.length > 2) {
                $button.text(selectedTexts.length + ' labels selected');
            } else {
                $button.text(selectedTexts.join(', '));
            }
        };

        $multiSelect.find('option').each(function() {
            var $option = $(this);
            if (!$option.val()) return; // Skip "All Labels" placeholder
            var $item = $('<label><input type="checkbox" value="' + $option.val() + '"> ' + $option.text() + '</label>');
            if ($option.is(':selected')) {
                $item.find('input').prop('checked', true);
            }
            $list.append($item);
        });

        $dropdown.append($button).append($list);
        $multiSelect.after($dropdown);

        $button.on('click', function(e) { e.stopPropagation(); $list.toggle(); });
        $list.on('click', function(e) { e.stopPropagation(); });
        $(document).on('click', function() { $list.hide(); });

        $list.on('change', 'input[type="checkbox"]', function() {
            var value = $(this).val();
            $multiSelect.find('option[value="' + value + '"]').prop('selected', $(this).is(':checked'));
            updateButtonText();
        });

        updateButtonText(); // Set initial button text
    }
});