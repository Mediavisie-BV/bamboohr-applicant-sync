jQuery(document).ready(function($) {
    $('#bamboohr-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $submitBtn = $('#submit-btn');
        var $messages = $('#form-messages');

        // Clear any existing messages
        $messages.empty();

        // Basic form validation
        if (!validateForm()) {
            return;
        }

        // Disable submit button
        $submitBtn.prop('disabled', true).text('Submitting...');

        // Create FormData object to handle file uploads
        var formData = new FormData(this);
        formData.append('action', 'submit_application');

        // AJAX request
        $.ajax({
            url: bamboohr_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $messages.html('<div class="success-message">' + response.data + '</div>');
                    $form[0].reset(); // Reset form

                    // Scroll to success message
                    $('html, body').animate({
                        scrollTop: $messages.offset().top - 100
                    }, 500);
                } else {
                    $messages.html('<div class="error-message">' + response.data + '</div>');
                }
            },
            error: function(xhr, status, error) {
                $messages.html('<div class="error-message">An error occurred. Please try again.</div>');
                console.error('AJAX Error:', error);
            },
            complete: function() {
                // Re-enable submit button
                $submitBtn.prop('disabled', false).text('Submit Application');
            }
        });
    });

    // Form validation function
    function validateForm() {
        let isValid = true;
        let $messages = $('#form-messages');

        // Required field validation
        let requiredFields = ['firstName', 'lastName', 'email'];

        requiredFields.forEach(function(fieldName) {
            var $field = $('#' + fieldName);
            if ($field.length !== 0 && !$field.first().val().trim()) {
                $messages.html('<div class="error-message">Please fill in all required fields.</div>');
                $field.focus();
                isValid = false;
                return false;
            }
        });

        // Email validation
        let email = $('#email').val();
        if (email && !isValidEmail(email)) {
            $messages.html('<div class="error-message">Please enter a valid email address.</div>');
            $('#email').focus();
            isValid = false;
        }

        // Resume file validation
        let resumeFile = $('#resume')[0].files[0];
        if (!resumeFile) {
            $messages.html('<div class="error-message">Please upload your resume.</div>');
            $('#resume').focus();
            isValid = false;
        }

        return isValid;
    }

    // Email validation helper
    function isValidEmail(email) {
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // File validation for resume
    $('#resume').on('change', function() {
        validateFile(this, 'resume');
    });

    // File validation for cover letter
    $('#coverLetter').on('change', function() {
        validateFile(this, 'cover letter');
    });

    // File validation function
    function validateFile(input, fileType) {
        var file = input.files[0];
        var $messages = $('#form-messages');

        if (file) {
            var fileSize = file.size / 1024 / 1024; // Convert to MB
            var allowedTypes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];

            // Check file size (max 5MB)
            if (fileSize > 5) {
                $messages.html('<div class="error-message">File is too large. Maximum 5MB allowed for ' + fileType + '.</div>');
                $(input).val('');
                return false;
            }

            // Check file type
            if (allowedTypes.indexOf(file.type) === -1) {
                $messages.html('<div class="error-message">Only PDF, DOC and DOCX files are allowed for ' + fileType + '.</div>');
                $(input).val('');
                return false;
            }

            // Clear any previous error messages for this file
            if ($messages.find('.error-message').text().indexOf(fileType) !== -1) {
                $messages.empty();
            }
        }

        return true;
    }

    // URL validation and formatting
    $('#websiteUrl, #linkedinUrl').on('blur', function() {
        var $input = $(this);
        var url = $input.val().trim();

        if (url) {
            // Auto-add https:// if missing
            if (!url.match(/^https?:\/\//)) {
                url = 'https://' + url;
                $input.val(url);
            }

            // Validate URL format
            if (!isValidUrl(url)) {
                $('#form-messages').html('<div class="error-message">Please enter a valid URL.</div>');
                $input.focus();
                return false;
            } else {
                // Clear error if URL is now valid
                var $errorMsg = $('#form-messages .error-message');
                if ($errorMsg.text().indexOf('valid URL') !== -1) {
                    $('#form-messages').empty();
                }
            }
        }
    });

    // URL validation helper
    function isValidUrl(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    }

    // LinkedIn URL specific formatting
    $('#linkedinUrl').on('blur', function() {
        var $input = $(this);
        var url = $input.val().trim();

        if (url && !url.match(/^https?:\/\//)) {
            // If it looks like a LinkedIn URL, format it properly
            if (url.includes('linkedin.com') || url.startsWith('linkedin.com')) {
                if (!url.startsWith('linkedin.com')) {
                    url = 'linkedin.com' + (url.startsWith('/') ? '' : '/') + url;
                }
                url = 'https://' + url;
                $input.val(url);
            }
        }
    });

    // Date validation - prevent past dates for availability
    $('#dateAvailable').on('change', function() {
        var selectedDate = new Date($(this).val());
        var today = new Date();
        today.setHours(0, 0, 0, 0);

        if (selectedDate < today) {
            $('#form-messages').html('<div class="error-message">Please select a future date for availability.</div>');
            $(this).val('');
            return false;
        } else {
            // Clear error if date is now valid
            var $errorMsg = $('#form-messages .error-message');
            if ($errorMsg.text().indexOf('future date') !== -1) {
                $('#form-messages').empty();
            }
        }
    });

    // Real-time email validation
    $('#email').on('blur', function() {
        var email = $(this).val().trim();
        if (email && !isValidEmail(email)) {
            $('#form-messages').html('<div class="error-message">Please enter a valid email address.</div>');
            $(this).focus();
        } else if (email) {
            // Clear email error if now valid
            var $errorMsg = $('#form-messages .error-message');
            if ($errorMsg.text().indexOf('valid email') !== -1) {
                $('#form-messages').empty();
            }
        }
    });

    // Phone number formatting (optional)
    $('#phoneNumber').on('input', function() {
        var phone = $(this).val().replace(/\D/g, ''); // Remove non-digits

        // Basic US phone formatting (adjust as needed)
        if (phone.length >= 6) {
            if (phone.length <= 10) {
                phone = phone.replace(/(\d{3})(\d{3})(\d+)/, '($1) $2-$3');
            } else {
                phone = phone.replace(/(\d{1})(\d{3})(\d{3})(\d{4})/, '+$1 ($2) $3-$4');
            }
            $(this).val(phone);
        }
    });

    // Clear messages when user starts typing in any field
    $('#bamboohr-form input, #bamboohr-form select, #bamboohr-form textarea').on('input change', function() {
        var $messages = $('#form-messages');
        var $errorMsg = $messages.find('.error-message');

        // Only clear generic messages, keep specific validation messages
        if ($errorMsg.length &&
            $errorMsg.text().indexOf('required fields') !== -1) {
            $messages.empty();
        }
    });

    // Form field focus styling
    $('#bamboohr-form input, #bamboohr-form select, #bamboohr-form textarea').on('focus', function() {
        $(this).css('border-color', '#0073aa');
    }).on('blur', function() {
        $(this).css('border-color', '#ddd');
    });
});