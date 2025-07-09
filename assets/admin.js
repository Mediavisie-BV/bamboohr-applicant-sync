// assets/admin.js
jQuery(document).ready(function($) {

    // Application details modal
    $('.view-application').on('click', function(e) {
        e.preventDefault();

        var applicationId = $(this).data('id');

        $.post(bamboohr_admin_ajax.ajax_url, {
            action: 'get_application_details',
            id: applicationId,
            nonce: bamboohr_admin_ajax.nonce
        }, function(response) {
            if (response.success) {
                showModal('Application Details', response.data);
            } else {
                alert('Error: ' + response.data);
            }
        });
    });

    // Retry sync
    $('.retry-sync-application').on('click', function(e) {
        e.preventDefault();

        var button = $(this);
        var applicationId = button.data('id');

        button.prop('disabled', true).text('Bezig...');

        $.post(bamboohr_admin_ajax.ajax_url, {
            action: 'retry_bamboohr_sync',
            id: applicationId,
            nonce: bamboohr_admin_ajax.nonce
        }, function(response) {
            if (response.success) {
                alert('Sync succesvol: ' + response.data);
                location.reload();
            } else {
                alert('Sync fout: ' + response.data);
            }
        }).always(function() {
            button.prop('disabled', false).text('Retry Sync');
        });
    });

    // Bulk actions for applications
    $('#doaction, #doaction2').on('click', function(e) {
        var action = $(this).siblings('select').val();
        var selectedIds = [];

        $('input[name="application_ids[]"]:checked').each(function() {
            selectedIds.push($(this).val());
        });

        if (selectedIds.length === 0) {
            alert('Selecteer eerst één of meer sollicitaties.');
            e.preventDefault();
            return;
        }

        if (action === 'retry_sync') {
            if (!confirm('Weet je zeker dat je de sync opnieuw wilt proberen voor ' + selectedIds.length + ' sollicitatie(s)?')) {
                e.preventDefault();
                return;
            }

            e.preventDefault();
            bulkRetrySync(selectedIds);
        }
    });

    // Bulk retry sync function
    function bulkRetrySync(ids) {
        var totalIds = ids.length;
        var completedIds = 0;
        var results = [];

        var progressDiv = $('<div id="bulk-progress" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border: 1px solid #ccc; z-index: 9999;"><h3>Bulk Sync Progress</h3><div id="progress-bar" style="width: 300px; height: 20px; background: #f0f0f0; border: 1px solid #ccc;"><div id="progress-fill" style="height: 100%; background: #0073aa; width: 0%; transition: width 0.3s;"></div></div><p id="progress-text">0 / ' + totalIds + '</p></div>');
        $('body').append(progressDiv);

        function processNext() {
            if (ids.length === 0) {
                // Completed
                $('#bulk-progress').remove();

                var successCount = results.filter(r => r.success).length;
                var errorCount = results.filter(r => !r.success).length;

                alert('Bulk sync voltooid!\nSuccesvol: ' + successCount + '\nFouten: ' + errorCount);
                location.reload();
                return;
            }

            var currentId = ids.shift();
            completedIds++;

            $.post(bamboohr_admin_ajax.ajax_url, {
                action: 'retry_bamboohr_sync',
                id: currentId,
                nonce: bamboohr_admin_ajax.nonce
            }, function(response) {
                results.push({
                    id: currentId,
                    success: response.success,
                    message: response.data
                });
            }).always(function() {
                // Update progress
                var percentage = (completedIds / totalIds) * 100;
                $('#progress-fill').css('width', percentage + '%');
                $('#progress-text').text(completedIds + ' / ' + totalIds);

                // Process next
                setTimeout(processNext, 500); // Small delay to prevent overwhelming the server
            });
        }

        processNext();
    }

    // Select all checkbox
    $('#select-all-applications').on('change', function() {
        $('input[name="application_ids[]"]').prop('checked', $(this).prop('checked'));
    });

    // Update select all when individual checkboxes change
    $('input[name="application_ids[]"]').on('change', function() {
        var totalCheckboxes = $('input[name="application_ids[]"]').length;
        var checkedCheckboxes = $('input[name="application_ids[]"]:checked').length;

        $('#select-all-applications').prop('checked', totalCheckboxes === checkedCheckboxes);
        $('#select-all-applications').prop('indeterminate', checkedCheckboxes > 0 && checkedCheckboxes < totalCheckboxes);
    });

    // Vacancy sync functions
    if (typeof window.syncVacancies === 'undefined') {
        window.syncVacancies = function() {
            var button = $('#sync-vacancies');
            button.prop('disabled', true).text('Bezig met synchroniseren...');

            $.post(bamboohr_admin_ajax.ajax_url, {
                action: 'sync_vacancies',
                nonce: bamboohr_admin_ajax.nonce
            }, function(response) {
                if (response.success) {
                    showModal('Sync Succesvol', '<p>' + response.data + '</p><p><strong>Pagina wordt herladen...</strong></p>');
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    showModal('Sync Fout', '<p style="color: red;">' + response.data + '</p>');
                }
            }).fail(function() {
                showModal('Sync Fout', '<p style="color: red;">Er is een onbekende fout opgetreden.</p>');
            }).always(function() {
                button.prop('disabled', false).text('Sync Vacatures Nu');
            });
        };
    }

    // Generic modal function
    function showModal(title, content) {
        // Remove existing modal
        $('#bamboohr-modal').remove();

        var modal = $('<div id="bamboohr-modal" style="position: fixed; z-index: 999999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);"><div id="bamboohr-modal-content" style="background-color: #fff; margin: 5% auto; padding: 20px; border: 1px solid #ccc; width: 80%; max-width: 600px; border-radius: 5px; position: relative;"><span id="bamboohr-modal-close" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; position: absolute; right: 15px; top: 10px;">&times;</span><h3>' + title + '</h3><div id="bamboohr-modal-body">' + content + '</div></div></div>');

        $('body').append(modal);

        // Close modal events
        $('#bamboohr-modal-close, #bamboohr-modal').on('click', function(e) {
            if (e.target === this) {
                $('#bamboohr-modal').remove();
            }
        });

        // ESC key to close
        $(document).on('keyup.bamboohr-modal', function(e) {
            if (e.keyCode === 27) { // ESC
                $('#bamboohr-modal').remove();
                $(document).off('keyup.bamboohr-modal');
            }
        });
    }

    // Filter applications by sync status
    $('#filter-sync-status').on('change', function() {
        var selectedStatus = $(this).val();
        var url = new URL(window.location);

        if (selectedStatus) {
            url.searchParams.set('sync_status', selectedStatus);
        } else {
            url.searchParams.delete('sync_status');
        }

        window.location = url.toString();
    });

    // Export applications to CSV
    $('#export-applications').on('click', function(e) {
        e.preventDefault();

        var selectedIds = [];
        $('input[name="application_ids[]"]:checked').each(function() {
            selectedIds.push($(this).val());
        });

        var url = ajaxurl + '?action=export_applications&nonce=' + bamboohr_admin_ajax.nonce;
        if (selectedIds.length > 0) {
            url += '&ids=' + selectedIds.join(',');
        }

        window.open(url, '_blank');
    });

    // Auto-refresh status for pending syncs
    if ($('.sync-status-pending').length > 0) {
        setTimeout(function() {
            location.reload();
        }, 30000); // Refresh every 30 seconds if there are pending syncs
    }

    // Tooltip for error messages
    $('.error-tooltip').hover(function() {
        var tooltip = $('<div class="bamboohr-tooltip" style="position: absolute; background: #333; color: white; padding: 5px 10px; border-radius: 3px; font-size: 12px; max-width: 300px; z-index: 9999;">' + $(this).data('error') + '</div>');

        $('body').append(tooltip);

        var offset = $(this).offset();
        tooltip.css({
            top: offset.top - tooltip.outerHeight() - 5,
            left: offset.left + ($(this).outerWidth() / 2) - (tooltip.outerWidth() / 2)
        });
    }, function() {
        $('.bamboohr-tooltip').remove();
    });

    // Confirmation for dangerous actions
    $('.dangerous-action').on('click', function(e) {
        if (!confirm('Weet je zeker dat je deze actie wilt uitvoeren?')) {
            e.preventDefault();
        }
    });

});

// Global utility functions
window.BambooHR = {
    showModal: function(title, content) {
        // Same as the local showModal function above
        $('#bamboohr-modal').remove();

        var modal = $('<div id="bamboohr-modal" style="position: fixed; z-index: 999999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);"><div id="bamboohr-modal-content" style="background-color: #fff; margin: 5% auto; padding: 20px; border: 1px solid #ccc; width: 80%; max-width: 600px; border-radius: 5px; position: relative;"><span id="bamboohr-modal-close" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; position: absolute; right: 15px; top: 10px;">&times;</span><h3>' + title + '</h3><div id="bamboohr-modal-body">' + content + '</div></div></div>');

        jQuery('body').append(modal);

        jQuery('#bamboohr-modal-close, #bamboohr-modal').on('click', function(e) {
            if (e.target === this) {
                jQuery('#bamboohr-modal').remove();
            }
        });
    },

    hideModal: function() {
        jQuery('#bamboohr-modal').remove();
    }
};