jQuery(document).ready(function($) {
    // Hide all child rows initially.
    $('tr.child-row').hide();

    // Handle the click event for the toggle icon on parent rows.
    $('#col-right').on('click', '.toggle-children', function() {
        var $toggle = $(this);
        var $parentRow = $toggle.closest('tr');
        var termId = $parentRow.data('term-id');
        
        // Find direct children and toggle their visibility with an animation.
        $('tr.child-of-' + termId).slideToggle(200);

        // Toggle classes to handle the icon direction and state.
        $parentRow.toggleClass('is-open');
    });

    // Add some basic styling for the cursor and icon rotation.
    $('<style>').prop('type', 'text/css').html(`
        .toggle-children {
            cursor: pointer;
            transition: transform 0.2s ease-in-out;
            vertical-align: text-top;
            margin-right: 5px;
        }
        tr.is-open .toggle-children {
            transform: rotate(90deg);
        }
    `).appendTo('head');
});