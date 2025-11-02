jQuery(document).ready(function ($) {
    // =============================================
    // --- AJAX Save Price from Meta Box ---
    // =============================================
    
    // Use delegation from a static parent element
    $('body').on('click', '#qp-save-product-price-btn', function() {
        var $button = $(this);
        var $successMsg = $('#qp-price-save-success');
        
        var productId = $button.data('product-id');
        var nonce = $button.data('nonce');
        var regularPrice = $('#_qp_product_regular_price').val();
        var salePrice = $('#_qp_product_sale_price').val();
        
        // Show loading state
        $button.text('Saving...').prop('disabled', true);
        $successMsg.text('');

        $.ajax({
            url: ajaxurl, // 'ajaxurl' is globally available in admin
            type: 'POST',
            data: {
                action: 'qp_update_product_price',
                nonce: nonce,
                product_id: productId,
                regular_price: regularPrice,
                sale_price: salePrice
            },
            success: function(response) {
                if (response.success) {
                    // Update fields with sanitized values from server
                    $('#_qp_product_regular_price').val(response.data.regular_price);
                    $('#_qp_product_sale_price').val(response.data.sale_price);
                    
                    // Show success message
                    $successMsg.text('Saved!');
                    setTimeout(function() {
                        $successMsg.text('');
                    }, 2000); // Hide message after 2 seconds
                } else {
                    // Show error
                    $successMsg.css('color', 'red').text(response.data.message || 'Error!');
                    setTimeout(function() {
                        $successMsg.text('').css('color', '#2e7d32'); // Reset color
                    }, 3000);
                }
            },
            error: function() {
                // Show critical error
                $successMsg.css('color', 'red').text('Server error.');
                setTimeout(function() {
                    $successMsg.text('').css('color', '#2e7d32'); // Reset color
                }, 3000);
            },
            complete: function() {
                // Restore button
                $button.text('Save Price').prop('disabled', false);
            }
        });
    });

}); // End jQuery ready