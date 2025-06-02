<div class="wrap">
    <h1>BambooHR Instellingen</h1>
    
    <form method="post" action="">
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="api_key">BambooHR API Key</label>
                </th>
                <td>
                    <input type="password" id="api_key" name="api_key" value="<?php echo esc_attr(get_option('bamboohr_api_key')); ?>" class="regular-text" />
                    <p class="description">
                        Je BambooHR API key. Te vinden in je BambooHR account onder Settings > API Keys.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="company_domain">Company Domain</label>
                </th>
                <td>
                    <input type="text" id="company_domain" name="company_domain" value="<?php echo esc_attr(get_option('bamboohr_company_domain')); ?>" class="regular-text" />
                    <p class="description">
                        Je BambooHR company domain (bijvoorbeeld: jouwbedrijf). Dit is het deel voor .bamboohr.com in je BambooHR URL.
                    </p>
                </td>
            </tr>
        </table>
        
        <?php submit_button('Instellingen Opslaan'); ?>
    </form>
    
    <hr>
    
    <h2>API Verbinding Testen</h2>
    <p>Klik op de knop hieronder om te testen of de API verbinding werkt:</p>
    <button type="button" id="test-api-connection" class="button button-secondary">Test API Verbinding</button>
    <div id="api-test-result"></div>
    
    <hr>
    
    <h2>Instructies</h2>
    <div class="notice notice-info">
        <h3>Hoe gebruik je deze plugin:</h3>
        <ol>
            <li><strong>API Configuratie:</strong> Vul hierboven je BambooHR API key en company domain in.</li>
            <li><strong>Formulier toevoegen:</strong> Gebruik de shortcode <code>[bamboohr_application_form]</code> op elke pagina waar je het sollicitatieformulier wilt tonen.</li>
            <li><strong>Met specifieke functie:</strong> Gebruik <code>[bamboohr_application_form position="Ontwikkelaar"]</code> om een vooringevulde functie te hebben.</li>
            <li><strong>Sollicitaties bekijken:</strong> Ga naar "BambooHR" in het admin menu om alle sollicitaties te bekijken.</li>
        </ol>
        
        <h3>Hoe krijg je een BambooHR API Key:</h3>
        <ol>
            <li>Log in op je BambooHR account</li>
            <li>Ga naar Settings > API Keys</li>
            <li>Klik op "Add New Key"</li>
            <li>Geef de key een naam (bijv. "WordPress Plugin")</li>
            <li>Kopieer de gegenereerde API key en plak deze hierboven</li>
        </ol>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#test-api-connection').on('click', function() {
        var $button = $(this);
        var $result = $('#api-test-result');
        
        $button.prop('disabled', true).text('Testen...');
        $result.empty();
        
        $.post(ajaxurl, {
            action: 'test_bamboohr_connection',
            nonce: '<?php echo wp_create_nonce('bamboohr_admin_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                $result.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
            } else {
                $result.html('<div class="notice notice-error"><p>Fout: ' + response.data + '</p></div>');
            }
        }).always(function() {
            $button.prop('disabled', false).text('Test API Verbinding');
        });
    });
});