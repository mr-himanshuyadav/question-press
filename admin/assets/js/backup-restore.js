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
                        showConfirmButton: false
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
                                showConfirmButton: false
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
            title: 'ARE YOU SURE?',
            html: `This will <strong>permanently delete</strong> all current Question Press data and replace it with the data from:<br><strong>${filename}</strong>.<br><br>This action cannot be undone. It is highly recommended to create a new backup before restoring.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, Overwrite and Restore!',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                return $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'qp_restore_backup',
                        nonce: qp_backup_restore_data.nonce,
                        filename: filename
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
                    statsHtml += `<p><strong>Questions:</strong> ${stats.questions}</p>`;
                    statsHtml += `<p><strong>Options:</strong> ${stats.options}</p>`;
                    statsHtml += `<p><strong>Sessions:</strong> ${stats.sessions}</p>`;
                    statsHtml += `<p><strong>Attempts:</strong> ${stats.attempts}</p>`;
                    statsHtml += `<p><strong>Reports:</strong> ${stats.reports}</p>`;
                    if (stats.duplicates_handled > 0) {
                        statsHtml += `<p><strong>Duplicate Attempts Handled:</strong> ${stats.duplicates_handled}</p>`;
                    }
                    statsHtml += '</div>';

                    Swal.fire({
                        title: 'Restore Complete!',
                        html: 'Your data has been successfully restored.' + statsHtml,
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