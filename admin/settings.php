<?php
// admin/settings.php
?>
<div class="wrap">
    <h1>BambooHR Instellingen</h1>

    <form method="post" action="">
        <h2>API Configuratie</h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="api_key">BambooHR API Key</label>
                </th>
                <td>
                    <input type="password" id="api_key" name="api_key"
                           value="<?php echo esc_attr(get_option('bamboohr_api_key')); ?>"
                           class="regular-text" />
                    <p class="description">
                        Je BambooHR API key. Deze kun je verkrijgen via je BambooHR account instellingen.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="company_domain">Company Domain</label>
                </th>
                <td>
                    <input type="text" id="company_domain" name="company_domain"
                           value="<?php echo esc_attr(get_option('bamboohr_company_domain')); ?>"
                           class="regular-text" />
                    <p class="description">
                        Je BambooHR company domain (bijv. "jouwbedrijf" als je URL https://jouwbedrijf.bamboohr.com is).
                    </p>
                </td>
            </tr>
        </table>

        <h2>Vacature Synchronisatie</h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="vacancy_sync_enabled">Automatische Sync</label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="vacancy_sync_enabled" name="vacancy_sync_enabled"
                               value="1" <?php checked(get_option('bamboohr_vacancy_sync_enabled'), '1'); ?> />
                        Automatisch vacatures synchroniseren (elk uur)
                    </label>
                    <p class="description">
                        Wanneer ingeschakeld, worden vacatures automatisch elk uur gesynchroniseerd.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="careers_base_url">Careers Base URL</label>
                </th>
                <td>
                    <input type="url" id="careers_base_url" name="careers_base_url"
                           value="<?php echo esc_attr(get_option('bamboohr_careers_base_url', 'https://alloptions.bamboohr.com/careers')); ?>"
                           class="regular-text" />
                    <p class="description">
                        De basis URL van je BambooHR careers pagina. Standaard: https://alloptions.bamboohr.com/careers
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="vacancy_post_type">WordPress Post Type</label>
                </th>
                <td>
                    <input type="text" id="vacancy_post_type" name="vacancy_post_type"
                           value="<?php echo esc_attr(get_option('bamboohr_vacancy_post_type', 'vacancies')); ?>"
                           class="regular-text" />
                    <p class="description">
                        Het WordPress post type waarin vacatures opgeslagen worden. Zorg ervoor dat dit post type bestaat (bijv. via JetEngine).
                    </p>

                    <?php
                    $post_type = get_option('bamboohr_vacancy_post_type', 'vacancies');
                    if (post_type_exists($post_type)):
                        ?>
                        <span style="color: green;">? Post type '<?php echo esc_html($post_type); ?>' bestaat</span>
                    <?php else: ?>
                        <span style="color: red;">? Post type '<?php echo esc_html($post_type); ?>' bestaat niet!</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <h2>Meta Fields Mapping</h2>
        <p class="description">
            De volgende meta fields worden automatisch ingesteld bij vacature synchronisatie:
        </p>
        <table class="form-table">
            <tr>
                <th scope="row">BambooHR ID</th>
                <td><code>bamboohr_id</code> - Het unieke ID van de vacature in BambooHR</td>
            </tr>
            <tr>
                <th scope="row">Department</th>
                <td><code>department</code> - De afdeling waarvoor de vacature is</td>
            </tr>
            <tr>
                <th scope="row">Location</th>
                <td><code>location</code> - De locatie van de vacature</td>
            </tr>
            <tr>
                <th scope="row">Status</th>
                <td><code>status</code> - De status van de vacature (meestal 'active')</td>
            </tr>
        </table>

        <h2>Test Configuratie</h2>
        <table class="form-table">
            <tr>
                <th scope="row">API Verbinding</th>
                <td>
                    <button type="button" class="button" id="test-api-connection">
                        Test BambooHR API Verbinding
                    </button>
                    <div id="api-test-result" style="margin-top: 10px;"></div>
                </td>
            </tr>
            <tr>
                <th scope="row">Vacature Sync</th>
                <td>
                    <button type="button" class="button" id="test-vacancy-connection">
                        Test Vacature Pagina Verbinding
                    </button>
                    <div id="vacancy-test-result" style="margin-top: 10px;"></div>
                </td>
            </tr>
        </table>

        <?php submit_button('Instellingen Opslaan'); ?>
    </form>

    <hr>

    <h2>Shortcode Gebruik</h2>
    <p>Gebruik de volgende shortcodes om sollicitatieformulieren toe te voegen aan je pagina's:</p>

    <h3>Basis formulier</h3>
    <code>[bamboohr_application_form]</code>

    <h3>Formulier voor specifieke vacature</h3>
    <code>[bamboohr_application_form job_id="123"]</code>
    <p class="description">
        Vervang "123" met het BambooHR ID van de vacature. Dit ID kun je vinden in de meta field <code>bamboohr_id</code> van je vacature posts.
    </p>

    <h3>Voorbeeld integratie met JetEngine</h3>
    <p>Als je JetEngine gebruikt, kun je in je vacature template het formulier dynamisch koppelen:</p>
    <code>[bamboohr_application_form job_id="%bamboohr_id%"]</code>
    <p class="description">
        JetEngine zal automatisch de waarde van het <code>bamboohr_id</code> meta field invullen.
    </p>
</div>

<script>
    jQuery(document).ready(function($) {
        // Test API connection
        $('#test-api-connection').on('click', function() {
            var button = $(this);
            var resultDiv = $('#api-test-result');

            button.prop('disabled', true).text('Bezig met testen...');
            resultDiv.html('<em>Test wordt uitgevoerd...</em>');

            $.post(ajaxurl, {
                action: 'test_bamboohr_connection',
                nonce: '<?php echo wp_create_nonce('bamboohr_admin_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    resultDiv.html('<div style="color: green; font-weight: bold;">? ' + response.data + '</div>');
                } else {
                    resultDiv.html('<div style="color: red; font-weight: bold;">? ' + response.data + '</div>');
                }
            }).fail(function() {
                resultDiv.html('<div style="color: red; font-weight: bold;">? Er is een onbekende fout opgetreden.</div>');
            }).always(function() {
                button.prop('disabled', false).text('Test BambooHR API Verbinding');
            });
        });

        // Test vacancy connection
        $('#test-vacancy-connection').on('click', function() {
            var button = $(this);
            var resultDiv = $('#vacancy-test-result');

            button.prop('disabled', true).text('Bezig met testen...');
            resultDiv.html('<em>Test wordt uitgevoerd...</em>');

            $.post(ajaxurl, {
                action: 'test_vacancy_sync',
                nonce: '<?php echo wp_create_nonce('bamboohr_admin_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    resultDiv.html('<div style="color: green; font-weight: bold;">? ' + response.data + '</div>');
                } else {
                    resultDiv.html('<div style="color: red; font-weight: bold;">? ' + response.data + '</div>');
                }
            }).fail(function() {
                resultDiv.html('<div style="color: red; font-weight: bold;">? Er is een onbekende fout opgetreden.</div>');
            }).always(function() {
                button.prop('disabled', false).text('Test Vacature Pagina Verbinding');
            });
        });
    });
</script>