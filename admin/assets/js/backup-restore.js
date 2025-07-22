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
                        icon: 'success'
                    });
                    // In the next step, we will use the response to update the table
                    // console.log(result.value.data.backups_html);
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
});