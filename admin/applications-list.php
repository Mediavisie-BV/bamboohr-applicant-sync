<div class="wrap">
    <h1>BambooHR Applications</h1>

    <div class="tablenav top">
        <div class="alignleft actions">
            <p><strong>Total:</strong> <?php echo count($applications); ?> applications</p>
        </div>
    </div>

    <?php if (empty($applications)): ?>
        <p>No applications received yet.</p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped bamboohr-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Position</th>
                <th>Location</th>
                <th>Status</th>
                <th>BambooHR ID</th>
                <th>Attempts</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($applications as $app): ?>
                <tr>
                    <td><?php echo $app->id; ?></td>
                    <td><?php echo esc_html($app->first_name . ' ' . $app->last_name); ?></td>
                    <td><?php echo esc_html($app->email); ?></td>
                    <td><?php echo esc_html($app->phone_number); ?></td>
                    <td><?php echo esc_html($app->position_applied); ?></td>
                    <td><?php echo esc_html($app->city . ($app->city && $app->country ? ', ' : '') . $app->country); ?></td>
                    <td>
                            <span class="status-<?php echo $app->sync_status; ?>">
                                <?php
                                switch($app->sync_status) {
                                    case 'success': echo 'Success'; break;
                                    case 'failed': echo 'Failed'; break;
                                    case 'pending': echo 'Pending'; break;
                                }
                                ?>
                            </span>
                    </td>
                    <td><?php echo $app->bamboohr_id ? $app->bamboohr_id : '-'; ?></td>
                    <td><?php echo $app->sync_attempts; ?></td>
                    <td><?php echo date('m/d/Y H:i', strtotime($app->created_at)); ?></td>
                    <td>
                        <a href="#" onclick="showDetails(<?php echo $app->id; ?>)" class="button button-small">Details</a>
                        <?php if ($app->sync_status !== 'success'): ?>
                            <a href="#" onclick="retrySync(<?php echo $app->id; ?>)" class="button button-small">Retry</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Detail Modal -->
<div id="application-details-modal" style="display: none;">
    <div class="modal-content">
        <span class="close">&times;</span>
        <div id="application-details-content"></div>
    </div>
</div>

<script>
    function showDetails(id) {
        // AJAX call to get details
        jQuery.post(ajaxurl, {
            action: 'get_application_details',
            id: id,
            nonce: '<?php echo wp_create_nonce('bamboohr_admin_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                jQuery('#application-details-content').html(response.data);
                jQuery('#application-details-modal').show();
            }
        });
    }

    function retrySync(id) {
        if (confirm('Are you sure you want to retry the synchronization?')) {
            jQuery.post(ajaxurl, {
                action: 'retry_bamboohr_sync',
                id: id,
                nonce: '<?php echo wp_create_nonce('bamboohr_admin_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            });
        }
    }

    // Close modal
    jQuery(document).on('click', '.close', function() {
        jQuery('#application-details-modal').hide();
    });
</script>

<style>
    #application-details-modal {
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
    }

    .modal-content {
        background-color: #fefefe;
        margin: 7% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 80%;
        max-width: 800px;
        border-radius: 5px;
        max-height: 70vh;
        overflow-y: auto;
    }

    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }

    .close:hover {
        color: black;
    }
</style>