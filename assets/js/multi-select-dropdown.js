jQuery(document).ready(function($) {
    // Target all select[multiple] that we've tagged with this class
    var $multiSelects = $('select[multiple].qp-custom-multi-select');

    $multiSelects.each(function() {
        var $multiSelect = $(this);
        $multiSelect.hide(); // Hide the original select box

        var placeholder = $multiSelect.find('option[value=""]').text() || 'Select Options';
        
        var $dropdown = $('<div class="qp-multi-select-dropdown"></div>');
        var $button = $('<button type="button" class="qp-multi-select-button">' + placeholder + '</button>');
        
        // --- Create Search Bar ---
        var $searchWrapper = $('<div class="qp-multi-select-search-wrapper"></div>');
        var $searchInput = $('<input type="text" class="qp-multi-select-search" placeholder="Search...">');
        $searchWrapper.append($searchInput);
        
        var $list = $('<div class="qp-multi-select-list" style="display: none;"></div>'); // Start hidden

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
        
        // --- NEW: Function to update checkbox states from the original select ---
        var updateUIFromSelect = function() {
            var selectedValues = $multiSelect.val() || [];
            var isDisabled = $multiSelect.is(':disabled');

            // Disable/Enable the custom button
            $button.prop('disabled', isDisabled);
            if (isDisabled) {
                $button.addClass('disabled');
            } else {
                $button.removeClass('disabled');
            }

            // Sync checkbox checked states
            $list.find('input[type="checkbox"]').each(function() {
                var $cb = $(this);
                $cb.prop('checked', selectedValues.includes($cb.val()));
            });
            
            // Update button text
            updateButtonText();
            
            // Update the 5-limit disabled state (specific to subjects)
            if ($multiSelect.attr('id') === 'qp_reg_subject') {
                var checkedCount = $list.find('input:checked').length;
                if (checkedCount >= 5) {
                    $list.find('input:not(:checked)').prop('disabled', true).parent().addClass('disabled');
                } else {
                    // Only re-enable if the whole control isn't disabled
                    if (!isDisabled) {
                        $list.find('input:disabled').prop('disabled', false).parent().removeClass('disabled');
                    }
                }
            }
            
            // If the control is globally disabled, ensure all checkboxes are also disabled
            if (isDisabled) {
                 $list.find('input[type="checkbox"]').prop('disabled', true).parent().addClass('disabled');
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
        
        // --- MODIFIED: Append in new order ---
        $list.prepend($searchWrapper); // <-- Add search bar *inside* the list
        $dropdown.append($button).append($list); // <-- Append list (which now contains search)
        $multiSelect.after($dropdown);

        // Event handlers
        $button.on('click', function(e) {
            if ($button.is(':disabled')) return; // <-- NEW: Don't open if disabled
            e.stopPropagation();
            // Close other open lists
            $('.qp-multi-select-list').not($list).hide();
            // Toggle this list
            $list.toggle();
             // Focus the search input when opening
            if ($list.is(':visible')) {
                $searchInput.val('').trigger('keyup').focus(); // Clear search on open and focus
            }
        });
        
        $list.on('click', function(e) { e.stopPropagation(); });
        
        $(document).on('click', function() {
            $list.hide();
        });

        // --- Search input keyup handler ---
        $searchInput.on('keyup', function(e) {
            e.stopPropagation(); // Stop click from bubbling to list
            var searchTerm = $(this).val().toLowerCase();
            $list.find('label').each(function() {
                var $label = $(this);
                // Find label's text, excluding the search wrapper itself
                var labelText = $label.clone().find('.qp-multi-select-search-wrapper').remove().end().text();

                if (labelText.toLowerCase().includes(searchTerm)) {
                    $label.show();
                } else {
                    $label.hide();
                }
            });
        });

        // --- MODIFIED: Checkbox change handler ---
        $list.on('change', 'input[type="checkbox"]', function() {
            var value = $(this).val();
            // Sync the original hidden select box
            $multiSelect.find('option[value="' + value + '"]').prop('selected', $(this).is(':checked'));
            
            // --- 5-Subject Limit Logic (Specific to this dropdown) ---
            if ($multiSelect.attr('id') === 'qp_reg_subject') {
                var checkedCount = $list.find('input:checked').length;
                if (checkedCount >= 5) {
                    // Disable all unchecked inputs
                    $list.find('input:not(:checked)').prop('disabled', true).parent().addClass('disabled');
                } else {
                    // Re-enable all inputs
                    $list.find('input:disabled').prop('disabled', false).parent().removeClass('disabled');
                }
            }

            // Manually trigger 'change' on the original select for other scripts (like auth.js) to detect
            $multiSelect.trigger('change'); 
            updateButtonText();
        });
        
        // --- NEW: Listen for the custom event from auth.js ---
        $multiSelect.on('qp:update_ui', function() {
            updateUIFromSelect();
        });

        updateUIFromSelect(); // Set initial state
    });
});