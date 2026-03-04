jQuery(document).ready(function($) {
    var wrapper = $('#col-container');
    // --- Auto Backup UI Logic ---
    var $autoBackupForm = $('#qp-auto-backup-form');
    var $saveButton = $('#qp-save-schedule-btn');
    var $disableButton = $('#qp-disable-auto-backup-btn');
    var $hiddenDisableForm = $('#qp-disable-backup-form');

    wrapper.on('click', '#qp-create-backup-btn', function(e) {
        e.preventDefault();
        var $button = $(this);

        Swal.fire({
            title: 'Create New Backup?',
            text: "This will generate a full backup of all your Question Press data. This may take a moment depending on the size of your database and number of images.",
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Yes, create it!',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                return $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'qp_create_backup',
                        nonce: qp_backup_restore_data.nonce
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    Swal.showValidationMessage(`Request failed: ${errorThrown}`);
                });
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then((result) => {
            if (result.isConfirmed) {
                if (result.value.success) {
                    Swal.fire({
                        title: 'Backup Created!',
                        text: 'Your new backup has been created and saved locally.',
                        icon: 'success',
                        timer: 1500,
                    });
                    // Refresh the table with the new list
                    $('#qp-local-backups-list').html(result.value.data.backups_html);
                } else {
                    Swal.fire({
                        title: 'Error!',
                        text: result.value.data.message || 'An unknown error occurred.',
                        icon: 'error'
                    });
                }
            }
        });
    });


    wrapper.on('click', '.qp-delete-backup-btn', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $row = $button.closest('tr');
        var filename = $row.data('filename');

        Swal.fire({
            title: 'Delete this backup?',
            html: `You are about to permanently delete the file:<br><strong>${filename}</strong><br>This action cannot be undone.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'qp_delete_backup',
                        nonce: qp_backup_restore_data.nonce,
                        filename: filename
                    },
                    beforeSend: function() {
                        // Visually indicate loading on the specific row
                        $row.css('opacity', '0.5');
                    },
                    success: function(response) {
                        if (response.success) {
                            // The AJAX call returns the full new table body, so we just replace it
                            $('#qp-local-backups-list').html(response.data.backups_html);
                            Swal.fire({
                                title: 'Deleted!',
                                text: response.data.message,
                                icon: 'success',
                                timer: 1500,
                                timerProgressBar: true,
                            });
                        } else {
                            Swal.fire('Error!', response.data.message, 'error');
                            $row.css('opacity', '1');
                        }
                    },
                    error: function() {
                        Swal.fire('Error!', 'A server error occurred.', 'error');
                        $row.css('opacity', '1');
                    }
                });
            }
        });
    });

    wrapper.on('click', '.qp-restore-btn', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $row = $button.closest('tr');
        var filename = $row.data('filename');

        Swal.fire({
            title: 'Restore Data',
            html: `
                <div style="text-align: left;">
                    <p style="margin-bottom: 15px;">Restoring from: <strong>${filename}</strong></p>
                    
                    <div style="background: #f8f9fa; padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px;">
                        <p style="margin-top: 0; margin-bottom: 10px; font-weight: bold;">Select Restore Mode:</p>
                        
                        <label style="display: flex; gap: 10px; align-items: flex-start; margin-bottom: 12px; cursor: pointer;">
                            <input type="radio" name="swal_restore_mode" value="merge" checked style="margin-top: 3px;">
                            <div>
                                <strong>Merge (Recommended)</strong><br>
                                <span style="font-size: 0.9em; color: #666;">Adds new data. Existing users, questions, and progress are preserved. Useful for combining backups.</span>
                            </div>
                        </label>
                        
                        <label style="display: flex; gap: 10px; align-items: flex-start; cursor: pointer;">
                            <input type="radio" name="swal_restore_mode" value="overwrite" style="margin-top: 3px;">
                            <div>
                                <strong style="color: #d63638;">Overwrite (Destructive)</strong><br>
                                <span style="font-size: 0.9em; color: #666;">Deletes ALL current Question Press data and replaces it with this backup. Use with caution.</span>
                            </div>
                        </label>
                    </div>

                    <p style="font-size: 0.9em; color: #d63638;"><strong>Note:</strong> It is highly recommended to create a new backup before restoring.</p>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33', // Keep red for caution
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Proceed with Restore',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                // Get the selected mode from the radio buttons inside the modal
                var selectedMode = Swal.getPopup().querySelector('input[name="swal_restore_mode"]:checked').value;

                return $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'qp_restore_backup',
                        nonce: qp_backup_restore_data.nonce,
                        filename: filename,
                        mode: selectedMode // Pass the selected mode
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    Swal.showValidationMessage(`Request failed: ${errorThrown}`);
                });
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then((result) => {
            if (result.isConfirmed) {
                if (result.value.success) {
                    const stats = result.value.data.stats;
                    let statsHtml = '<div style="text-align: left; display: inline-block; margin-top: 1rem;">';
                    // Show relevant stats
                    if (stats.tables_cleared > 0) statsHtml += `<p><strong>Tables Cleared:</strong> ${stats.tables_cleared}</p>`;
                    statsHtml += `<p><strong>Users Processed:</strong> ${stats.users_mapped + stats.users_created} (Created: ${stats.users_created})</p>`;
                    statsHtml += `<p><strong>Questions Restored:</strong> ${stats.questions}</p>`;
                    statsHtml += `<p><strong>Posts/Plans Restored:</strong> ${stats.cpts_restored}</p>`;
                    statsHtml += `<p><strong>Media Files Restored:</strong> ${stats.media_restored}</p>`;
                    statsHtml += '</div>';

                    Swal.fire({
                        title: 'Restore Complete!',
                        html: 'Your data has been successfully restored.<br>' + statsHtml,
                        icon: 'success'
                    }).then(() => {
                        // Reload the page to see changes reflected everywhere
                        location.reload();
                    });
                } else {
                    Swal.fire('Error!', result.value.data.message, 'error');
                }
            }
        });
    });

    // Monitor for changes in the form fields
    $autoBackupForm.on('change keyup', 'input, select', function() {
        if ($saveButton.is(':disabled')) {
            $saveButton.prop('disabled', false).val('Update Schedule');
        }
    });

    // Handle the disable button click
    $disableButton.on('click', function(e) {
        e.preventDefault();
        // Simply submit the hidden form
        $hiddenDisableForm.submit();
    });
});