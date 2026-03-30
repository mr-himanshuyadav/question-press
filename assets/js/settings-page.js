jQuery(document).ready(function($) {
    
    /* --- 1. API KEY REGENERATION (Existing) --- */
    $('#qp-regenerate-api-key').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var nonce = button.data('nonce');

        Swal.fire({
            title: 'Regenerate Secret Key?',
            text: "This will invalidate tokens. This action cannot be undone.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, regenerate it!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'regenerate_api_key', nonce: nonce },
                    success: function(response) {
                        if (response.success) {
                            $('#qp-api-secret-key-field').val(response.data.new_key);
                            Swal.fire('Success!', 'New secret key generated.', 'success');
                        }
                    }
                });
            }
        });
    });

    /* --- 2. SMART RELEASE UPLOADER (New Logic) --- */
    $('#qp_release_zip_input').on('change', function(e) {
        var file = this.files[0];
        if (!file) return;

        // Basic Validation
        if (file.type !== "application/zip" && !file.name.endsWith('.zip')) {
            Swal.fire('Invalid File', 'Please upload a .zip release bundle.', 'error');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'qp_upload_release_zip');
        formData.append('security', qp_admin_object.nonce); // Provided via wp_localize_script
        formData.append('release_zip', file);

        var statusText = $('#qp_upload_status');
        
        Swal.fire({
            title: 'Uploading Release...',
            html: '<div id="qp_swal_progress">Preparing...</div>',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        $.ajax({
            url: qp_admin_object.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener("progress", function(evt) {
                    if (evt.lengthComputable) {
                        var percentComplete = Math.round((evt.loaded / evt.total) * 100);
                        $('#qp_swal_progress').text('Progress: ' + percentComplete + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: 'Release processed and variants mapped.',
                        icon: 'success'
                    }).then(() => {
                        window.location.reload(); // Refresh to show the new Release Card
                    });
                } else {
                    Swal.fire('Upload Failed', response.data || 'Unknown error', 'error');
                }
            },
            error: function() {
                Swal.fire('Server Error', 'The server could not handle the ZIP file.', 'error');
            }
        });
    });
});