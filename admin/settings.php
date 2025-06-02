<div class="wrap">
    <h1>BambooHR Settings</h1>

    <form method="post" action="">
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="api_key">BambooHR API Key</label>
                </th>
                <td>
                    <input type="password" id="api_key" name="api_key" value="<?php echo esc_attr(get_option('bamboohr_api_key')); ?>" class="regular-text" />
                    <p class="description">
                        Your BambooHR API key. Found in your BambooHR account under Settings > API Keys.
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
                        Your BambooHR company domain (e.g., yourcompany). This is the part before .bamboohr.com in your BambooHR URL.
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button('Save Settings'); ?>
    </form>

    <hr>

    <h2>Test API Connection</h2>
    <p>Click the button below to test if the API connection is working:</p>
    <button type="button" id="test-api-connection" class="button button-secondary">Test API Connection</button>
    <div id="api-test-result"></div>

    <hr>

    <h2>Instructions</h2>
    <div class="notice notice-info">
        <h3>How to use this plugin:</h3>
        <ol>
            <li><strong>API Configuration:</strong> Fill in your BambooHR API key and company domain above.</li>
            <li><strong>Add Application Form:</strong> Use the shortcode <code>[bamboohr_application_form]</code> on any page where you want to display the job application form.</li>
            <li><strong>With Specific Position:</strong> Use <code>[bamboohr_application_form position="Developer"]</code> to pre-fill a specific position.</li>
            <li><strong>View Applications:</strong> Go to "BambooHR" in the admin menu to view all applications.</li>
        </ol>

        <h3>How to get a BambooHR API Key:</h3>
        <ol>
            <li>Log into your BambooHR account</li>
            <li>Navigate to Settings > API Keys</li>
            <li>Click "Add New Key"</li>
            <li>Give the key a name (e.g., "WordPress Plugin")</li>
            <li>Copy the generated API key and paste it above</li>
        </ol>

        <h3>Form Fields:</h3>
        <p>The application form includes the following fields:</p>
        <ul>
            <li><strong>Required:</strong> First Name, Last Name, Email, Position, Resume</li>
            <li><strong>Optional:</strong> Phone, Address, City, State/Province, ZIP, Country, Cover Letter, Date Available, Desired Salary, Website URL, LinkedIn URL</li>
        </ul>

        <h3>File Requirements:</h3>
        <ul>
            <li><strong>Resume:</strong> Required - PDF, DOC, or DOCX format, max 5MB</li>
            <li><strong>Cover Letter:</strong> Optional - PDF, DOC, or DOCX format, max 5MB</li>
        </ul>
    </div>
</div>

<script>
    jQuery(document).ready(function($) {
        $('#test-api-connection').on('click', function() {
            var $button = $(this);
            var $result = $('#api-test-result');

            $button.prop('disabled', true).text('Testing...');
            $result.empty();

            $.post(ajaxurl, {
                action: 'test_bamboohr_connection',
                nonce: '<?php echo wp_create_nonce('bamboohr_admin_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                } else {
                    $result.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                }
            }).always(function() {
                $button.prop('disabled', false).text('Test API Connection');
            });
        });
    }); API Key:</h3>
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