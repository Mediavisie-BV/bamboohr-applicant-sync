jQuery(document).ready(function ($) {
    $('#bamboohr-form').on('submit', function (e) {
        e.preventDefault();

        var $form = $(this);
        var $submitBtn = $('#submit-btn');
        var $messages = $('#form-messages');

        // Disable submit button
        $submitBtn.prop('disabled', true).text('Versturen...');
        $messages.empty();

        // Create FormData object to handle file upload
        var formData = new FormData(this);
        formData.append('action', 'submit_application');

        // AJAX request
        $.ajax({
            url: bamboohr_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response.success) {
                    $messages.html('<div class="success-message">' + response.data + '</div>');
                    $form[0].reset(); // Reset form
                } else {
                    $messages.html('<div class="error-message">' + response.data + '</div>');
                }
            },
            error: function (xhr, status, error) {
                $messages.html('<div class="error-message">Er is een fout opgetreden. Probeer het opnieuw.</div>');
                console.error('AJAX Error:', error);
            },
            complete: function () {
                // Re-enable submit button
                $submitBtn.prop('disabled', false).text('Sollicitatie Versturen');
            }
        });
    });

    // File validation
    $('#resume').on('change', function () {
        var file = this.files[0];
        var $messages = $('#form-messages');

        if (file) {
            var fileSize = file.size / 1024 / 1024; // Convert to MB
            var allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

            // Check file size (max 5MB)
            if (fileSize > 5) {
                $messages.html('<div class="error-message">Bestand is te groot. Maximum 5MB toegestaan.</div>');
                $(this).val('');
                return;
            }

            // Check file type
            if (allowedTypes.indexOf(file.type) === -1) {
                $messages.html('<div class="error-message">Alleen PDF, DOC en DOCX bestanden zijn toegestaan.</div>');
                $(this).val('');
                return;
            }

            $messages.empty();
        }
    });
});