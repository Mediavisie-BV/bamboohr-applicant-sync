<?php
// admin/vacancies-list.php
?>
<div class="wrap">
    <h1>BambooHR Vacatures Synchronisatie</h1>

    <div class="notice notice-info">
        <p><strong>Info:</strong> Deze pagina toont de synchronisatie status van vacatures tussen BambooHR careers pagina en je WordPress post type.</p>
    </div>

    <div class="tablenav top">
        <div class="alignleft actions">
            <button type="button" class="button button-primary" id="sync-vacancies">
                Sync Vacatures Nu
            </button>
            <button type="button" class="button" id="test-vacancy-sync">
                Test Verbinding
            </button>
        </div>
        <div class="tablenav-pages">
            <span class="displaying-num"><?php echo count($vacancies); ?> items</span>
        </div>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
        <tr>
            <th scope="col" class="manage-column">BambooHR ID</th>
            <th scope="col" class="manage-column">Titel</th>
            <th scope="col" class="manage-column">Department</th>
            <th scope="col" class="manage-column">WordPress Post</th>
            <th scope="col" class="manage-column">Sync Status</th>
            <th scope="col" class="manage-column">Laatste Sync</th>
            <th scope="col" class="manage-column">Acties</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($vacancies)): ?>
            <tr>
                <td colspan="7" style="text-align: center; padding: 20px;">
                    <em>Nog geen vacatures gesynchroniseerd. Klik op "Sync Vacatures Nu" om te beginnen.</em>
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($vacancies as $vacancy): ?>
                <tr>
                    <td><?php echo esc_html($vacancy->bamboohr_id); ?></td>
                    <td>
                        <strong><?php echo esc_html($vacancy->title ?: 'Geen titel'); ?></strong>
                    </td>
                    <td><?php echo esc_html($vacancy->department ?: '-'); ?></td>
                    <td>
                        <?php if ($vacancy->post_id): ?>
                            <a href="<?php echo get_edit_post_link($vacancy->post_id); ?>" target="_blank">
                                Post ID: <?php echo $vacancy->post_id; ?>
                            </a>
                            <br>
                            <a href="<?php echo get_permalink($vacancy->post_id); ?>" target="_blank">
                                <small>Bekijk post ?</small>
                            </a>
                        <?php else: ?>
                            <em>Geen post gekoppeld</em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $status_class = '';
                        switch ($vacancy->sync_status) {
                            case 'success':
                                $status_class = 'notice-success';
                                $status_text = 'Succesvol';
                                break;
                            case 'error':
                                $status_class = 'notice-error';
                                $status_text = 'Fout';
                                break;
                            default:
                                $status_class = 'notice-warning';
                                $status_text = 'Pending';
                        }
                        ?>
                        <span class="notice inline <?php echo $status_class; ?>" style="margin: 0; padding: 2px 8px;">
                                <?php echo $status_text; ?>
                            </span>

                        <?php if ($vacancy->error_message): ?>
                            <br><small style="color: #d63638;"><?php echo esc_html($vacancy->error_message); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        if ($vacancy->last_sync && $vacancy->last_sync !== '0000-00-00 00:00:00') {
                            echo date('d/m/Y H:i', strtotime($vacancy->last_sync));
                        } else {
                            echo '<em>Nooit</em>';
                        }
                        ?>
                    </td>
                    <td>
                        <button type="button" class="button button-small retry-sync"
                                data-id="<?php echo $vacancy->bamboohr_id; ?>">
                            Opnieuw Sync
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="tablenav bottom">
        <div class="alignleft actions">
            <em>Automatische sync: <?php echo get_option('bamboohr_vacancy_sync_enabled') === '1' ? 'Ingeschakeld (elk uur)' : 'Uitgeschakeld'; ?></em>
        </div>
    </div>
</div>

<!-- Modal for sync results -->
<div id="sync-modal" style="display: none;">
    <div id="sync-modal-content">
        <span id="sync-modal-close">&times;</span>
        <h3>Sync Resultaat</h3>
        <div id="sync-modal-body"></div>
    </div>
</div>

<style>
    #sync-modal {
        position: fixed;
        z-index: 999999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
    }

    #sync-modal-content {
        background-color: #fff;
        margin: 5% auto;
        padding: 20px;
        border: 1px solid #ccc;
        width: 80%;
        max-width: 600px;
        border-radius: 5px;
        position: relative;
    }

    #sync-modal-close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        position: absolute;
        right: 15px;
        top: 10px;
    }

    #sync-modal-close:hover {
        color: #000;
    }

    .notice.inline {
        display: inline-block;
        font-size: 12px;
        border-left-width: 4px;
        border-left-style: solid;
    }

    .notice-success.inline {
        border-left-color: #00a32a;
        background-color: #f0f8f0;
    }

    .notice-error.inline {
        border-left-color: #d63638;
        background-color: #fcf0f1;
    }

    .notice-warning.inline {
        border-left-color: #dba617;
        background-color: #fcf9e8;
    }
</style>

<script>
    jQuery(document).ready(function($) {
        // Sync vacancies
        $('#sync-vacancies').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).text('Bezig met synchroniseren...');

            $.post(ajaxurl, {
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
        });

        // Test vacancy sync
        $('#test-vacancy-sync').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).text('Bezig met testen...');

            $.post(ajaxurl, {
                action: 'test_vacancy_sync',
                nonce: bamboohr_admin_ajax.nonce
            }, function(response) {
                if (response.success) {
                    showModal('Test Succesvol', '<p>' + response.data + '</p>');
                } else {
                    showModal('Test Fout', '<p style="color: red;">' + response.data + '</p>');
                }
            }).fail(function() {
                showModal('Test Fout', '<p style="color: red;">Er is een onbekende fout opgetreden.</p>');
            }).always(function() {
                button.prop('disabled', false).text('Test Verbinding');
            });
        });

        // Retry sync for individual vacancy
        $('.retry-sync').on('click', function() {
            var button = $(this);
            var bamboohr_id = button.data('id');

            button.prop('disabled', true).text('Bezig...');

            // Note: Dit zou een nieuwe AJAX actie vereisen voor individuele vacancy sync
            // Voor nu tonen we een bericht dat dit nog niet geïmplementeerd is
            showModal('Info', '<p>Individuele sync nog niet geïmplementeerd. Gebruik "Sync Vacatures Nu" om alle vacatures opnieuw te synchroniseren.</p>');
            button.prop('disabled', false).text('Opnieuw Sync');
        });

        // Modal functions
        function showModal(title, content) {
            $('#sync-modal h3').text(title);
            $('#sync-modal-body').html(content);
            $('#sync-modal').show();
        }

        $('#sync-modal-close, #sync-modal').on('click', function(e) {
            if (e.target === this) {
                $('#sync-modal').hide();
            }
        });
    });
</script>