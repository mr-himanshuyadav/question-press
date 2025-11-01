// Helper function to render KaTeX
function renderKaTeX(element) {
    if (typeof renderMathInElement === 'function' && element) {
        renderMathInElement(element, {
            delimiters: [
                {left: '$$', right: '$$', display: true},
                {left: '$', right: '$', display: false},
                {left: '\\[', right: '\\]', display: true},
                {left: '\\(', right: '\\)', display: false}
            ],
            throwOnError: false
        });
    }
}

/**
 * Checks if the user has attempts before executing a callback.
 * Shows an error alert if access is denied.
 * @param {function} callback - The function to execute if access is granted.
 * @param {jQuery} [button] - Optional: The button triggering the action.
 * @param {string} [originalText] - Optional: The button's original text.
 * @param {string} [loadingText] - Optional: Text to show while checking.
 */
function checkAttemptsBeforeAction(callback, button, originalText, loadingText = 'Checking access...') {
    // Use global qp_ajax_object defined elsewhere
    if (typeof qp_ajax_object === 'undefined') {
         console.error('qp_ajax_object is not defined.');
         Swal.fire('Error!', 'Configuration missing. Cannot check access.', 'error');
         return;
    }

    if (button) {
        // Determine if it's an input button or a regular button
        if (button.is('input[type="submit"]') || button.is('input[type="button"]')) {
            button.prop('disabled', true).val(loadingText);
        } else {
            button.prop('disabled', true).text(loadingText);
        }
    }

    jQuery.ajax({ // Use jQuery.ajax since this is outside ready block
        url: qp_ajax_object.ajax_url,
        type: 'POST',
        data: {
            action: 'qp_check_remaining_attempts',
            // No nonce needed here as per PHP function
        },
        success: function(response) {
            // --- NEW SIMPLIFIED LOGIC ---
            if (response.success && response.data.has_access) {
                // Access granted by PHP, execute the original action
                if (typeof callback === 'function') {
                    callback();
                }
                // Note: Don't re-enable the button here if the callback redirects or leads to another action
            } else {
                 // Access denied by PHP (or AJAX failed partially), show alert
                 var practiceInProgress = false; // Allow redirect (safe to declare here)
                 var reasonCode = (response.data && response.data.reason_code) ? response.data.reason_code : 'unknown';
                 var alertTitle = 'Access Denied!';
                 var alertMessage = '';

                 // Customize message based on reason code
                 switch (reasonCode) {
                    case 'expired_or_inactive':
                        alertTitle = 'Plan Expired or Inactive';
                        alertMessage = 'Your access plan has expired or is currently inactive.';
                        break;
                    case 'out_of_attempts':
                        alertTitle = 'Out of Attempts!';
                        alertMessage = 'You have run out of attempts for your current plan(s).';
                        break;
                    case 'no_entitlements':
                         alertTitle = 'No Active Plan';
                         alertMessage = 'You do not have an active plan granting access.';
                         break;
                    case 'not_logged_in':
                        alertTitle = 'Not Logged In';
                        alertMessage = 'You must be logged in to access this feature.';
                        // Hide purchase link if not logged in
                        purchaseUrl = ''; // Clear purchase URL
                        break;
                    default: // Includes 'unknown' or unexpected codes
                        alertMessage = 'Access denied. Please check your plan details or contact support.';
                 }

                 // Construct purchase link URL using localized object
                 var purchaseUrl = (typeof qp_ajax_object !== 'undefined' && qp_ajax_object.shop_page_url) ? qp_ajax_object.shop_page_url : '';
                 var purchaseLinkHtml = purchaseUrl ? ' <a href="' + purchaseUrl + '">Purchase Access</a>' : '';

                 Swal.fire({
                    title: alertTitle,
                    html: alertMessage + purchaseLinkHtml, // Append purchase link if available
                    icon: 'error',
                    confirmButtonText: 'OK',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                });

                // Reset button if provided (remains the same)
                if (button && originalText) {
                   if (button.is('input[type="submit"]') || button.is('input[type="button"]')) {
                       button.prop('disabled', false).val(originalText);
                   } else {
                       button.prop('disabled', false).text(originalText);
                   }
                }
            }
            // --- END NEW LOGIC ---
        },
        error: function() {
            // Handle AJAX error during check
            Swal.fire('Error!', 'Could not verify your access. Please try again.', 'error');
             if (button && originalText) {
                 if (button.is('input[type="submit"]') || button.is('input[type="button"]')) {
                     button.prop('disabled', false).val(originalText);
                 } else {
                     button.prop('disabled', false).text(originalText);
                 }
             }
        }
    });
}