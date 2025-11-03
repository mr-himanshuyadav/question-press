jQuery(document).ready(function($) {
    // Target all select[multiple] that we've tagged with this class
    var $multiSelects = $('select[multiple].qp-custom-multi-select');

    $multiSelects.each(function() {
        var $multiSelect = $(this);
        $multiSelect.hide(); // Hide the original select box

        // Get the placeholder text from the first option (if it's empty)
        var placeholder = $multiSelect.find('option[value=""]').text() || 'Select Options';
        
        var $dropdown = $('<div class="qp-multi-select-dropdown"></div>');
        var $button = $('<button type="button" class="qp-multi-select-button">' + placeholder + '</button>');
        var $list = $('<div class="qp-multi-select-list"></div>');

        var updateButtonText = function() {
            var selectedTexts = [];
            $list.find('input:checked').each(function() {
                selectedTexts.push($(this).parent().text().trim());
            });

            if (selectedTexts.length === 0) {
                $button.text(placeholder);
            } else if (selectedTexts.length > 2) {
                var label = 'items'; // Generic
                if ($multiSelect.attr('id') === 'qp_reg_subject') label = 'subjects';
                if ($multiSelect.attr('id') && $multiSelect.attr('id').includes('label')) label = 'labels';
                $button.text(selectedTexts.length + ' ' + label + ' selected');
            } else {
                $button.text(selectedTexts.join(', '));
            }
        };

        // Create checkbox items from options
        $multiSelect.find('option').each(function() {
            var $option = $(this);
            if (!$option.val()) return; // Skip the placeholder option

            var $item = $('<label><input type="checkbox" value="' + $option.val() + '"> ' + $option.text() + '</label>');
            
            if ($option.is(':selected')) {
                $item.find('input').prop('checked', true);
            }
            $list.append($item);
        });

        $dropdown.append($button).append($list);
        $multiSelect.after($dropdown);

        // Event handlers
        $button.on('click', function(e) {
            e.stopPropagation();
            // Close other open lists
            $('.qp-multi-select-list').not($list).hide();
            // Toggle this list
            $list.toggle();
        });

        $list.on('click', function(e) { e.stopPropagation(); });
        
        $(document).on('click', function() {
            $list.hide();
        });

        $list.on('change', 'input[type="checkbox"]', function() {
            var value = $(this).val();
            // Sync the original hidden select box
            $multiSelect.find('option[value="' + value + '"]').prop('selected', $(this).is(':checked'));
            // Manually trigger 'change' on the original select for other scripts (like auth.js) to detect
            $multiSelect.trigger('change'); 
            updateButtonText();
        });

        updateButtonText(); // Set initial text
    });
});