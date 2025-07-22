jQuery(document).ready(function($) {
    var wrapper = $('#col-container');

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
});