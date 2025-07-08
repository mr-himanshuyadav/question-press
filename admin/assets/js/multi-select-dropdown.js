jQuery(document).ready(function($) {
    // Target all multi-selects within the top navigation of the list table
    var $multiSelects = $('.tablenav.top .actions select[multiple]');

    // Apply the custom dropdown transformation to each one found
    $multiSelects.each(function() {
        var $multiSelect = $(this);
        $multiSelect.hide();

        var placeholder = $multiSelect.attr('id') === 'qp_label_filter_select' ? 'Select Labels to Filter' : 'Select Labels to Apply';
        
        var $dropdown = $('<div class="qp-multi-select-dropdown"></div>');
        var $button = $('<button type="button" class="button">' + placeholder + '</button>');
        var $list = $('<div class="qp-multi-select-list" style="display: none;"></div>');

        var updateButtonText = function() {
            var selectedTexts = [];
            $list.find('input:checked').each(function() {
                selectedTexts.push($(this).parent().text().trim());
            });
            if (selectedTexts.length === 0) {
                $button.text(placeholder);
            } else if (selectedTexts.length > 2) {
                $button.text(selectedTexts.length + ' labels selected');
            } else {
                $button.text(selectedTexts.join(', '));
            }
        };

        $multiSelect.find('option').each(function() {
            var $option = $(this);
            if (!$option.val()) return; // Skip placeholder options
            var $item = $('<label><input type="checkbox" value="' + $option.val() + '"> ' + $option.text() + '</label>');
            
            // Check the original select box to set the initial state
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
            // Important: Sync the original hidden select box
            $multiSelect.find('option[value="' + value + '"]').prop('selected', $(this).is(':checked'));
            updateButtonText();
        });

        updateButtonText();
    });
});