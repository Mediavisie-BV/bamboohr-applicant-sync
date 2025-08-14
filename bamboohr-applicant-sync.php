<?php
/**
 * Plugin Name: BambooHR Applicant Sync
 * Plugin URI: https://github.com/Mediavisie-BV/bamboohr-applicant-sync
 * Description: Add an applicant form and sync with BambooHR, including job vacancy synchronization
 * Version: 1.2.4
 * Author: Jithran Sikken
 * Author URI: https://www.mediavisie.nl
 * GitHub Plugin URI: https://github.com/Mediavisie-BV/bamboohr-applicant-sync
 */

// Voorkom directe toegang
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constanten
define('BAMBOOHR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BAMBOOHR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BAMBOOHR_PLUGIN_VERSION', '1.2.4');

class BambooHRApplicantSync {

    public function __construct() {
        // Hook voor plugin activatie
        register_activation_hook(__FILE__, array($this, 'create_tables'));

        // Hook voor database updates
        add_action('plugins_loaded', array($this, 'check_database_version'));

        // WordPress hooks
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_submit_application', array($this, 'handle_form_submission'));
        add_action('wp_ajax_nopriv_submit_application', array($this, 'handle_form_submission'));
        add_action('wp_ajax_get_application_details', array($this, 'get_application_details'));
        add_action('wp_ajax_retry_bamboohr_sync', array($this, 'retry_bamboohr_sync'));
        add_action('wp_ajax_test_bamboohr_connection', array($this, 'test_bamboohr_connection'));

        // Nieuwe AJAX hooks voor vacature sync
        add_action('wp_ajax_sync_vacancies', array($this, 'sync_vacancies_ajax'));
        add_action('wp_ajax_test_vacancy_sync', array($this, 'test_vacancy_sync'));

        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Shortcode voor het formulier
        add_shortcode('bamboohr_application_form', array($this, 'render_application_form'));

        // Cron job voor automatische sync
        add_action('bamboohr_sync_vacancies_cron', array($this, 'sync_vacancies_cron'));

        // Schedule cron job if not scheduled
        if (!wp_next_scheduled('bamboohr_sync_vacancies_cron')) {
            wp_schedule_event(time(), 'hourly', 'bamboohr_sync_vacancies_cron');
        }
    }

    public function check_database_version() {
        $installed_version = get_option('bamboohr_plugin_version', '1.0.0');

        if (version_compare($installed_version, BAMBOOHR_PLUGIN_VERSION, '<')) {
            $this->update_database();
            update_option('bamboohr_plugin_version', BAMBOOHR_PLUGIN_VERSION);
        }
    }

    public function update_database() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bamboohr_applications';

        // Check welke kolommen ontbreken en voeg ze toe
        $columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        $new_columns = array(
            'phone_number' => "ADD COLUMN phone_number varchar(50) AFTER email",
            'address' => "ADD COLUMN address varchar(255) AFTER phone_number",
            'city' => "ADD COLUMN city varchar(100) AFTER address",
            'state' => "ADD COLUMN state varchar(100) AFTER city",
            'zip' => "ADD COLUMN zip varchar(20) AFTER state",
            'country' => "ADD COLUMN country varchar(5) AFTER zip",
            'cover_letter_file' => "ADD COLUMN cover_letter_file varchar(500) AFTER resume_file",
            'date_available' => "ADD COLUMN date_available date AFTER cover_letter_file",
            'desired_salary' => "ADD COLUMN desired_salary varchar(50) AFTER date_available",
            'website_url' => "ADD COLUMN website_url varchar(255) AFTER desired_salary",
            'linkedin_url' => "ADD COLUMN linkedin_url varchar(255) AFTER website_url",
            'job_id' => "ADD COLUMN job_id int(11) AFTER cover_letter",
        );

        foreach ($new_columns as $column => $sql) {
            if (!in_array($column, $columns)) {
                $wpdb->query("ALTER TABLE {$table_name} {$sql}");
            }
        }

        // Update oude 'phone' kolom naar 'phone_number' als die bestaat
        if (in_array('phone', $columns) && !in_array('phone_number', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} CHANGE phone phone_number varchar(50)");
        }

        // Update oude 'cover_letter' text kolom naar 'cover_letter_file' als die bestaat
        if (in_array('cover_letter', $columns) && !in_array('cover_letter_file', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} CHANGE cover_letter cover_letter_file varchar(500)");
        }

        // Cre�er vacancy sync table
        $this->create_vacancy_sync_table();
    }

    public function init() {
        // Plugin initialisatie
    }

    public function create_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bamboohr_applications';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            phone_number varchar(50),
            address varchar(255),
            city varchar(100),
            state varchar(100),
            zip varchar(20),
            country varchar(5),
            resume_file varchar(500),
            cover_letter_file varchar(500),
            date_available date,
            desired_salary varchar(50),
            website_url varchar(255),
            linkedin_url varchar(255),
            job_id int(11),
            bamboohr_id varchar(50),
            sync_status varchar(20) DEFAULT 'pending',
            sync_attempts int(3) DEFAULT 0,
            last_sync_attempt datetime,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Cre�er vacancy sync table
        $this->create_vacancy_sync_table();

        // Voeg opties toe
        add_option('bamboohr_api_key', '');
        add_option('bamboohr_company_domain', '');
        add_option('bamboohr_plugin_version', BAMBOOHR_PLUGIN_VERSION);
        add_option('bamboohr_vacancy_post_type', 'vacancies');
        add_option('bamboohr_vacancy_sync_enabled', '0');
        add_option('bamboohr_careers_base_url', 'https://alloptions.bamboohr.com/careers');
    }

    private function create_vacancy_sync_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bamboohr_vacancy_sync';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            bamboohr_id varchar(50) NOT NULL,
            post_id bigint(20) UNSIGNED,
            title varchar(255),
            status varchar(50),
            department varchar(255),
            last_sync datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            sync_status varchar(20) DEFAULT 'pending',
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY bamboohr_id (bamboohr_id),
            KEY post_id (post_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // ... (alle bestaande functies blijven hetzelfde tot add_admin_menu) ...

    public function enqueue_scripts() {
        wp_enqueue_script('bamboohr-form', BAMBOOHR_PLUGIN_URL . 'assets/form-handler.js', array('jquery'), '1.0.0', true);
        wp_enqueue_style('bamboohr-form', BAMBOOHR_PLUGIN_URL . 'assets/form-styles.css', array(), '1.0.0');

        // Localize script voor AJAX
        wp_localize_script('bamboohr-form', 'bamboohr_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bamboohr_form_nonce')
        ));
    }

    public function render_application_form($atts) {
        $atts = shortcode_atts(array(
            'position' => '',
            'job_id' => ''
        ), $atts);

        // Als geen job_id is opgegeven, probeer het op te halen van de huidige post
        if (empty($atts['job_id'])) {
            global $post;
            if ($post) {
                $bamboohr_id = get_post_meta($post->ID, 'bamboohr_id', true);
                if (!empty($bamboohr_id)) {
                    $atts['job_id'] = $bamboohr_id;
                }
            }
        }

        ob_start();
        ?>
        <div id="bamboohr-application-form">
            <div id="form-messages" style="margin-bottom:var(--wp--preset--spacing--40)"></div>

            <form id="bamboohr-form" enctype="multipart/form-data">
                <?php wp_nonce_field('bamboohr_form_nonce', 'bamboohr_nonce'); ?>

                <?php if (!empty($atts['job_id'])): ?>
                    <input type="hidden" name="job_id" value="<?php echo esc_attr($atts['job_id']); ?>">
                <?php endif; ?>

                <!-- Name Fields -->
                <div class="form-row">
                    <div class="form-group half">
                        <label for="firstName">First Name *</label>
                        <input type="text" id="firstName" name="firstName" required>
                    </div>
                    <div class="form-group half">
                        <label for="lastName">Last Name *</label>
                        <input type="text" id="lastName" name="lastName" required>
                    </div>
                </div>

                <!-- Contact Fields -->
                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" id="email" name="email" required>
                </div>

                <div class="form-group">
                    <label for="phoneNumber">Phone Number</label>
                    <input type="tel" id="phoneNumber" name="phoneNumber">
                </div>

                <!-- Address Fields -->
                <div class="form-group">
                    <label for="address">Street Address</label>
                    <input type="text" id="address" name="address">
                </div>

                <div class="form-row">
                    <div class="form-group third">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city">
                    </div>
                    <div class="form-group third">
                        <label for="state">State/Province</label>
                        <input type="text" id="state" name="state">
                    </div>
                    <div class="form-group third">
                        <label for="zip">ZIP/Postal Code</label>
                        <input type="text" id="zip" name="zip">
                    </div>
                </div>

                <div class="form-group">
                    <label for="country">Country</label>
                    <select id="country" name="country">
                        <option value="">Select Country</option>
                        <option value="US">United States</option>
                        <option value="CA">Canada</option>
                        <option value="GB">United Kingdom</option>
                        <option value="DE">Germany</option>
                        <option value="FR">France</option>
                        <option value="NL">Netherlands</option>
                        <option value="ES">Spain</option>
                        <option value="IT">Italy</option>
                        <option value="AU">Australia</option>
                        <option value="JP">Japan</option>
                        <option value="CN">China</option>
                        <option value="IN">India</option>
                        <option value="BR">Brazil</option>
                        <option value="MX">Mexico</option>
                        <option value="RU">Russia</option>
                        <option value="ZA">South Africa</option>
                        <option value="SG">Singapore</option>
                        <option value="CH">Switzerland</option>
                        <option value="SE">Sweden</option>
                        <option value="NO">Norway</option>
                        <option value="DK">Denmark</option>
                        <option value="FI">Finland</option>
                        <option value="BE">Belgium</option>
                        <option value="AT">Austria</option>
                        <option value="IE">Ireland</option>
                        <option value="PL">Poland</option>
                        <option value="CZ">Czech Republic</option>
                        <option value="HU">Hungary</option>
                        <option value="GR">Greece</option>
                        <option value="PT">Portugal</option>
                        <option value="SK">Slovakia</option>
                        <option value="SI">Slovenia</option>
                        <option value="HR">Croatia</option>
                        <option value="BG">Bulgaria</option>
                        <option value="RO">Romania</option>
                        <option value="LT">Lithuania</option>
                        <option value="LV">Latvia</option>
                        <option value="EE">Estonia</option>
                        <option value="LU">Luxembourg</option>
                        <option value="MT">Malta</option>
                        <option value="CY">Cyprus</option>
                    </select>
                </div>

                <!-- File Uploads -->
                <div class="form-group">
                    <label for="resume">Resume/CV (PDF, DOC, DOCX) *</label>
                    <input type="file" id="resume" name="resume" accept=".pdf,.doc,.docx" required>
                </div>

                <div class="form-group">
                    <label for="coverLetter">Cover Letter (PDF, DOC, DOCX)</label>
                    <input type="file" id="coverLetter" name="coverLetter" accept=".pdf,.doc,.docx">
                </div>

                <!-- Additional Information -->
                <div class="form-group">
                    <label for="dateAvailable">Date Available</label>
                    <input type="date" id="dateAvailable" name="dateAvailable">
                </div>

                <div class="form-group">
                    <label for="desiredSalary">Desired Salary</label>
                    <input type="text" id="desiredSalary" name="desiredSalary" placeholder="">
                </div>

                <!-- URLs -->
                <div class="form-group">
                    <label for="websiteUrl">Website/Portfolio URL</label>
                    <input type="url" id="websiteUrl" name="websiteUrl" placeholder="https://">
                </div>

                <div class="form-group">
                    <label for="linkedinUrl">LinkedIn Profile URL</label>
                    <input type="url" id="linkedinUrl" name="linkedinUrl" placeholder="https://linkedin.com/in/">
                </div>

                <div class="form-group">
                    <button type="submit" id="submit-btn">Submit Application</button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_form_submission() {
        // Verificatie
        if (!wp_verify_nonce($_POST['bamboohr_nonce'], 'bamboohr_form_nonce')) {
            wp_die('Security check failed');
        }

        // Sanitize form data
        $form_data = array(
            'first_name' => sanitize_text_field($_POST['firstName']),
            'last_name' => sanitize_text_field($_POST['lastName']),
            'email' => sanitize_email($_POST['email']),
            'phone_number' => sanitize_text_field($_POST['phoneNumber']),
            'address' => sanitize_text_field($_POST['address']),
            'city' => sanitize_text_field($_POST['city']),
            'state' => sanitize_text_field($_POST['state']),
            'zip' => sanitize_text_field($_POST['zip']),
            'country' => sanitize_text_field($_POST['country']),
            'date_available' => sanitize_text_field($_POST['dateAvailable']),
            'desired_salary' => sanitize_text_field($_POST['desiredSalary']),
            'website_url' => esc_url_raw($_POST['websiteUrl']),
            'linkedin_url' => esc_url_raw($_POST['linkedinUrl']),
            'job_id' => isset($_POST['job_id']) ? intval($_POST['job_id']) : null
        );

        // Handle file uploads
        $resume_file = '';
        $cover_letter_file = '';

        if (!empty($_FILES['resume']['name'])) {
            $resume_file = $this->handle_file_upload($_FILES['resume']);
            if (is_wp_error($resume_file)) {
                wp_send_json_error('Error uploading resume: ' . $resume_file->get_error_message());
                return;
            }
        }

        if (!empty($_FILES['coverLetter']['name'])) {
            $cover_letter_file = $this->handle_file_upload($_FILES['coverLetter']);
            if (is_wp_error($cover_letter_file)) {
                wp_send_json_error('Error uploading cover letter: ' . $cover_letter_file->get_error_message());
                return;
            }
        }

        $form_data['resume_file'] = $resume_file;
        $form_data['cover_letter_file'] = $cover_letter_file;

        // Sla lokaal op
        $local_id = $this->save_application_locally($form_data);

        if (!$local_id) {
            wp_send_json_error('There was an error saving your application. Please try again later.');
            return;
        }

        // Try to sync with BambooHR
        //$sync_result = $this->sync_with_bamboohr($local_id, $form_data);

        //if ($sync_result['success']) {
            wp_send_json_success('Thank you for your application');
        /*} else {
            // Even with sync error, give success message (data is saved locally)
            wp_send_json_success('Thank you for your application');
        }*/
    }

    private function handle_file_upload($file) {
        $upload_overrides = array('test_form' => false);
        $uploaded_file = wp_handle_upload($file, $upload_overrides);

        if (isset($uploaded_file['error'])) {
            return new WP_Error('upload_error', $uploaded_file['error']);
        }

        return $uploaded_file['url'];
    }

    private function save_application_locally($data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bamboohr_applications';

        $result = $wpdb->insert(
            $table_name,
            $data,
            array(
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d'
            )
        );

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    private function sync_with_bamboohr($local_id, $data) {
        $api_key = get_option('bamboohr_api_key');
        $company_domain = get_option('bamboohr_company_domain');

        if (empty($api_key) || empty($company_domain)) {
            $this->update_sync_status($local_id, 'failed', 'API configuratie ontbreekt');
            return array('success' => false, 'error' => 'API niet geconfigureerd');
        }

        // BambooHR API endpoint
        $url = "https://{$company_domain}.bamboohr.com/api/v1/applicant_tracking/application";

        // Prepare API data
        $api_data = array(
            'firstName' => $data['first_name'],
            'lastName' => $data['last_name'],
            'email' => $data['email'],
            'phoneNumber' => $data['phone_number'],
            'address' => $data['address'],
            'city' => $data['city'],
            'state' => $data['state'],
            'zip' => $data['zip'],
            'country' => $data['country'],
            'desiredSalary' => $data['desired_salary'],
            'websiteUrl' => $data['website_url'],
            'source' => 'Website'
        );

        if(!empty($data['linkedin_url'])) {
            $api_data['linkedinUrl'] = $data['linkedin_url'];
        }

        if(!empty($data['date_available']) && $data['date_available'] !== '0000-00-00') {
            $api_data['dateAvailable'] = $data['date_available'];
        }

        // Voeg job_id toe als deze bestaat
        if (!empty($data['job_id'])) {
            $api_data['jobId'] = $data['job_id'];
        }

        $boundary = wp_generate_password( 24 );
        $payload = '';
        // First, add the standard POST fields:
        foreach ( $api_data as $name => $value ) {
            $payload .= '--' . $boundary;
            $payload .= "\r\n";
            $payload .= 'Content-Disposition: form-data; name="' . $name .
                '"' . "\r\n\r\n";
            $payload .= $value;
            $payload .= "\r\n";
        }

        $files = [
                'resume' => 'resume_file',
                'coverLetter' => 'cover_letter_file',
        ];
        // Upload the file
        foreach($files AS $apiName => $fieldName) {
            if ( !empty($data[$fieldName]) ) {
                $data[$fieldName] = str_replace('localhost','host.docker.internal',$data[$fieldName]);
                $wpremoteresponse = wp_remote_get($data[$fieldName]);
                if (is_wp_error($wpremoteresponse)) {
                    $this->update_sync_status($local_id, 'failed', $apiName . ' - ' . $wpremoteresponse->get_error_message());
                    return array('success' => false, 'error' => $apiName . ' - ' . $wpremoteresponse->get_error_message());
                }

                $ext = pathinfo($data[$fieldName], PATHINFO_EXTENSION);
                $mimetypes = wp_get_mime_types();
                $mimetype = isset($mimetypes[$ext]) ? $mimetypes[$ext] : 'application/octet-stream';

                $payload .= '--' . $boundary;
                $payload .= "\r\n";
                $payload .= 'Content-Disposition: form-data; name="' . $apiName .
                    '"; filename="' . basename( $data[$fieldName] ) . '"' . "\r\n";
                        $payload .= 'Content-Type: ' . $mimetype . "\r\n";
                $payload .= "\r\n";
                //$payload .= file_get_contents( $data[$fieldName] );
                // get content from extrernal URL
                $payload .= wp_remote_retrieve_body($wpremoteresponse);
                $payload .= "\r\n";
            }
        }

        $payload .= '--' . $boundary . '--';




        //return array('success' => false, 'error' => $payload);

        // API call
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_key . ':x'),
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                'Accept' => 'application/json',
            ),
            'body' => $payload,
            'timeout' => 30
        ));

        $this->increment_sync_attempts($local_id);

        if (is_wp_error($response)) {
            $this->update_sync_status($local_id, 'failed', $response->get_error_message());
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200 || $response_code === 201) {
            // Success - extract applicant ID from response
            $response_data = json_decode($response_body, true);
            $bamboohr_id = isset($response_data['candidateId']) ? $response_data['candidateId'] : 'unknown';

            $this->update_sync_status($local_id, 'success', '', $bamboohr_id);
            return array('success' => true, 'bamboohr_id' => $bamboohr_id);
        } else {
            $error_message = "HTTP {$response_code}: {$response_body}";
            $this->update_sync_status($local_id, 'failed', $error_message);
            return array('success' => false, 'error' => $error_message);
        }
    }

    private function update_sync_status($local_id, $status, $error_message = '', $bamboohr_id = '') {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bamboohr_applications';

        $update_data = array(
            'sync_status' => $status,
            'last_sync_attempt' => current_time('mysql'),
            'error_message' => $error_message
        );

        if (!empty($bamboohr_id)) {
            $update_data['bamboohr_id'] = $bamboohr_id;
        }

        $wpdb->update(
            $table_name,
            $update_data,
            array('id' => $local_id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );
    }

    private function increment_sync_attempts($local_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bamboohr_applications';

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} SET sync_attempts = sync_attempts + 1 WHERE id = %d",
            $local_id
        ));
    }

    public function add_admin_menu() {
        add_menu_page(
            'BambooHR Sollicitaties',
            'BambooHR',
            'manage_options',
            'bamboohr-applications',
            array($this, 'admin_page'),
            'dashicons-groups',
            30
        );

        add_submenu_page(
            'bamboohr-applications',
            'Sollicitaties',
            'Sollicitaties',
            'manage_options',
            'bamboohr-applications',
            array($this, 'admin_page')
        );

        add_submenu_page(
            'bamboohr-applications',
            'Vacatures Sync',
            'Vacatures Sync',
            'manage_options',
            'bamboohr-vacancies',
            array($this, 'vacancies_page')
        );

        add_submenu_page(
            'bamboohr-applications',
            'Instellingen',
            'Instellingen',
            'manage_options',
            'bamboohr-settings',
            array($this, 'settings_page')
        );
    }

    public function admin_page() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bamboohr_applications';
        $applications = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC");

        include BAMBOOHR_PLUGIN_PATH . 'admin/applications-list.php';
    }

    public function vacancies_page() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bamboohr_vacancy_sync';
        $vacancies = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY last_sync DESC");

        // Enqueue admin scripts
        wp_enqueue_script('bamboohr-admin', BAMBOOHR_PLUGIN_URL . 'assets/admin.js', array('jquery'), '1.0.0', true);
        wp_localize_script('bamboohr-admin', 'bamboohr_admin_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bamboohr_admin_nonce')
        ));

        include BAMBOOHR_PLUGIN_PATH . 'admin/vacancies-list.php';
    }

    public function settings_page() {
        if (isset($_POST['submit'])) {
            update_option('bamboohr_api_key', sanitize_text_field($_POST['api_key']));
            update_option('bamboohr_company_domain', sanitize_text_field($_POST['company_domain']));
            update_option('bamboohr_vacancy_post_type', sanitize_text_field($_POST['vacancy_post_type']));
            update_option('bamboohr_vacancy_sync_enabled', isset($_POST['vacancy_sync_enabled']) ? '1' : '0');
            update_option('bamboohr_careers_base_url', esc_url_raw($_POST['careers_base_url']));
            echo '<div class="notice notice-success"><p>Instellingen opgeslagen!</p></div>';
        }

        include BAMBOOHR_PLUGIN_PATH . 'admin/settings.php';
    }

    // NIEUWE VACATURE SYNC FUNCTIONALITEIT

    public function sync_vacancies_ajax() {
        if (!wp_verify_nonce($_POST['nonce'], 'bamboohr_admin_nonce')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Geen toestemming');
            return;
        }

        $result = $this->sync_vacancies();

        if ($result['success']) {
            wp_send_json_success($result['message'] . ' Details: ' . json_encode($result['stats']));
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function sync_vacancies_cron() {
        // Controleer of sync is ingeschakeld
        if (get_option('bamboohr_vacancy_sync_enabled') !== '1') {
            return;
        }

        $this->sync_vacancies();
    }

    private function sync_vacancies() {
        $careers_base_url = get_option('bamboohr_careers_base_url', 'https://alloptions.bamboohr.com/careers');
        $post_type = get_option('bamboohr_vacancy_post_type', 'vacancies');

        // Controleer of het post type bestaat
        if (!post_type_exists($post_type)) {
            return array(
                'success' => false,
                'message' => "Post type '{$post_type}' bestaat niet. Controleer je instellingen."
            );
        }

        // Stap 1: Haal de lijst met vacatures op
        $list_url = $careers_base_url . '/list';
        $vacancies_data = $this->fetch_vacancies_list($list_url);

        if (!$vacancies_data) {
            return array(
                'success' => false,
                'message' => 'Kon vacatures lijst niet ophalen van ' . $list_url
            );
        }

        $stats = array(
            'fetched' => count($vacancies_data),
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'errors' => 0
        );

        // Stap 2: Voor elke vacature, haal details op en sync
        foreach ($vacancies_data as $vacancy_basic) {
            $detail_url = $careers_base_url . '/' . $vacancy_basic['id'] . '/detail';
            $vacancy_detail = $this->fetch_vacancy_detail($detail_url);

            if (!$vacancy_detail) {
                $stats['errors']++;
                continue;
            }

            $sync_result = $this->sync_single_vacancy($vacancy_basic, $vacancy_detail, $post_type);

            if ($sync_result['created']) {
                $stats['created']++;
            } elseif ($sync_result['updated']) {
                $stats['updated']++;
            } elseif ($sync_result['error']) {
                $stats['errors']++;
            }
        }

        // Stap 3: Verwijder vacatures die niet meer bestaan
        $current_vacancy_ids = array_column($vacancies_data, 'id');
        $deleted_count = $this->cleanup_old_vacancies($current_vacancy_ids, $post_type);
        $stats['deleted'] = $deleted_count;

        return array(
            'success' => true,
            'message' => 'Vacatures sync voltooid',
            'stats' => $stats
        );
    }

    private function fetch_vacancies_list($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress BambooHR Plugin',
                'Accept' => 'application/json, text/html, */*'
            )
        ));

        if (is_wp_error($response)) {
            error_log('BambooHR Vacancies List Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('BambooHR Vacancies List HTTP Error: ' . $response_code);
            return false;
        }

        $body = wp_remote_retrieve_body($response);

        // Probeer eerst JSON te parsen
        $vacancies = $this->parse_vacancies_list_json($body);

        // Als JSON parsing faalt, probeer HTML parsing als fallback
        if ($vacancies === false) {
            $vacancies = $this->parse_vacancies_list_html($body);
        }

        return $vacancies;
    }

    private function parse_vacancies_list_json($body) {
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('BambooHR JSON Parse Error: ' . json_last_error_msg());
            return false;
        }

        $vacancies = array();

        // Check if we have a result array
        if (isset($data['result']) && is_array($data['result'])) {
            foreach ($data['result'] as $vacancy_data) {
                if (isset($vacancy_data['id'])) {
                    $location_parts = array();
                    if (isset($vacancy_data['location'])) {
                        if (!empty($vacancy_data['location']['city'])) {
                            $location_parts[] = $vacancy_data['location']['city'];
                        }
                        if (!empty($vacancy_data['location']['state'])) {
                            $location_parts[] = $vacancy_data['location']['state'];
                        }
                    }

                    $vacancies[] = array(
                        'id' => $vacancy_data['id'],
                        'title' => $vacancy_data['jobOpeningName'] ?? '',
                        'department' => $vacancy_data['departmentLabel'] ?? '',
                        'location' => implode(', ', $location_parts),
                        'employment_status' => $vacancy_data['employmentStatusLabel'] ?? '',
                        'is_remote' => $vacancy_data['isRemote'] ?? null,
                        'location_type' => $vacancy_data['locationType'] ?? '0'
                    );
                }
            }
        }

        return $vacancies;
    }

    private function parse_vacancies_list_html($html) {
        $vacancies = array();

        // Gebruik DOMDocument om HTML te parsen (fallback voor als JSON niet werkt)
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Zoek naar links die naar /careers/{id}/detail verwijzen
        $links = $xpath->query('//a[contains(@href, "/careers/") and contains(@href, "/detail")]');

        foreach ($links as $link) {
            $href = $link->getAttribute('href');

            // Extract ID uit de URL
            if (preg_match('/\/careers\/(\d+)\/detail/', $href, $matches)) {
                $id = $matches[1];

                // Probeer titel te extracten uit de link tekst of parent elementen
                $title = trim($link->textContent);
                if (empty($title)) {
                    // Zoek in parent elementen
                    $parent = $link->parentNode;
                    while ($parent && empty($title)) {
                        $title = trim($parent->textContent);
                        $parent = $parent->parentNode;
                    }
                }

                $vacancies[] = array(
                    'id' => $id,
                    'title' => $title,
                    'department' => '',
                    'location' => '',
                    'employment_status' => '',
                    'is_remote' => null,
                    'location_type' => '0'
                );
            }
        }

        // Alternatieve methode: zoek naar data-* attributen of JSON data in script tags
        $scripts = $xpath->query('//script[contains(text(), "vacancy") or contains(text(), "job")]');
        foreach ($scripts as $script) {
            $content = $script->textContent;
            // Probeer JSON data te extracten
            if (preg_match('/\{.*"id".*\}/', $content, $matches)) {
                $json_data = json_decode($matches[0], true);
                if ($json_data && isset($json_data['id'])) {
                    $vacancies[] = array(
                        'id' => $json_data['id'],
                        'title' => $json_data['title'] ?? '',
                        'department' => '',
                        'location' => '',
                        'employment_status' => '',
                        'is_remote' => null,
                        'location_type' => '0'
                    );
                }
            }
        }

        // Verwijder duplicaten op basis van ID
        $unique_vacancies = array();
        $seen_ids = array();

        foreach ($vacancies as $vacancy) {
            if (!in_array($vacancy['id'], $seen_ids)) {
                $unique_vacancies[] = $vacancy;
                $seen_ids[] = $vacancy['id'];
            }
        }

        return $unique_vacancies;
    }

    private function fetch_vacancy_detail($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress BambooHR Plugin',
                'Accept' => 'application/json, text/html, */*'
            )
        ));

        if (is_wp_error($response)) {
            error_log('BambooHR Vacancy Detail Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('BambooHR Vacancy Detail HTTP Error: ' . $response_code);
            return false;
        }

        $body = wp_remote_retrieve_body($response);

        // Probeer eerst JSON te parsen
        $vacancy_detail = $this->parse_vacancy_detail_json($body);

        // Als JSON parsing faalt, probeer HTML parsing als fallback
        if ($vacancy_detail === false) {
            $vacancy_detail = $this->parse_vacancy_detail_html($body);
        }

        return $vacancy_detail;
    }

    private function parse_vacancy_detail_json($body) {
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('BambooHR Detail JSON Parse Error: ' . json_last_error_msg());
            return false;
        }

        $vacancy = array(
            'title' => '',
            'description' => '',
            'department' => '',
            'location' => '',
            'status' => 'active',
            'employment_status' => '',
            'date_posted' => '',
            'minimum_experience' => '',
            'compensation' => '',
            'share_url' => '',
            'is_remote' => null,
            'location_type' => '0'
        );

        // Check if we have a jobOpening result
        if (isset($data['result']['jobOpening'])) {
            $job = $data['result']['jobOpening'];

            $vacancy['title'] = $job['jobOpeningName'] ?? '';
            $vacancy['description'] = isset($job['description']) ? wp_kses_post($job['description']) : '';
            $vacancy['department'] = $job['departmentLabel'] ?? '';
            $vacancy['status'] = strtolower($job['jobOpeningStatus'] ?? 'active');
            $vacancy['employment_status'] = $job['employmentStatusLabel'] ?? '';
            $vacancy['date_posted'] = $job['datePosted'] ?? '';
            $vacancy['minimum_experience'] = $job['minimumExperience'] ?? '';
            $vacancy['compensation'] = $job['compensation'] ?? '';
            $vacancy['share_url'] = $job['jobOpeningShareUrl'] ?? '';
            $vacancy['location_type'] = $job['locationType'] ?? '0';
            $vacancy['location_city'] = $job['location']['city'] ?? '';
            $vacancy['location_country'] = $job['location']['addressCountry'] ?? '';

            // Parse location
            if (isset($job['location'])) {
                $location_parts = array();
                if (!empty($job['location']['city'])) {
                    $location_parts[] = $job['location']['city'];
                }
                if (!empty($job['location']['state'])) {
                    $location_parts[] = $job['location']['state'];
                }
                if (!empty($job['location']['postalCode'])) {
                    $location_parts[] = $job['location']['postalCode'];
                }
                if (!empty($job['location']['addressCountry'])) {
                    $location_parts[] = $job['location']['addressCountry'];
                }
                $vacancy['location'] = implode(', ', $location_parts);
            }
        }

        return $vacancy;
    }

    private function parse_vacancy_detail_html($html) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $vacancy = array(
            'title' => '',
            'description' => '',
            'department' => '',
            'location' => '',
            'status' => 'active'
        );

        // Zoek naar titel
        $title_selectors = array(
            '//h1',
            '//h2',
            '//*[contains(@class, "title")]',
            '//*[contains(@class, "job-title")]',
            '//*[contains(@class, "position")]'
        );

        foreach ($title_selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $vacancy['title'] = trim($nodes->item(0)->textContent);
                if (!empty($vacancy['title'])) break;
            }
        }

        // Zoek naar beschrijving
        $description_selectors = array(
            '//*[contains(@class, "description")]',
            '//*[contains(@class, "job-description")]',
            '//*[contains(@class, "content")]',
            '//div[contains(@class, "details")]'
        );

        foreach ($description_selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $vacancy['description'] = trim($nodes->item(0)->textContent);
                if (!empty($vacancy['description'])) break;
            }
        }

        // Zoek naar department
        $department_selectors = array(
            '//*[contains(@class, "department")]',
            '//*[contains(text(), "Department:")]/following-sibling::*',
            '//*[contains(text(), "Afdeling:")]/following-sibling::*'
        );

        foreach ($department_selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $vacancy['department'] = trim($nodes->item(0)->textContent);
                if (!empty($vacancy['department'])) break;
            }
        }

        // Zoek naar locatie
        $location_selectors = array(
            '//*[contains(@class, "location")]',
            '//*[contains(text(), "Location:")]/following-sibling::*',
            '//*[contains(text(), "Locatie:")]/following-sibling::*'
        );

        foreach ($location_selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $vacancy['location'] = trim($nodes->item(0)->textContent);
                if (!empty($vacancy['location'])) break;
            }
        }

        // Zoek naar JSON data in script tags
        $scripts = $xpath->query('//script[contains(text(), "job") or contains(text(), "vacancy")]');
        foreach ($scripts as $script) {
            $content = $script->textContent;

            // Probeer JSON LD te vinden
            if (strpos($content, '@type') !== false && strpos($content, 'JobPosting') !== false) {
                if (preg_match('/\{.*\}/', $content, $matches)) {
                    $json_data = json_decode($matches[0], true);
                    if ($json_data) {
                        if (isset($json_data['title'])) $vacancy['title'] = $json_data['title'];
                        if (isset($json_data['description'])) $vacancy['description'] = $json_data['description'];
                        if (isset($json_data['hiringOrganization']['department'])) {
                            $vacancy['department'] = $json_data['hiringOrganization']['department'];
                        }
                        if (isset($json_data['jobLocation']['address']['addressLocality'])) {
                            $vacancy['location'] = $json_data['jobLocation']['address']['addressLocality'];
                        }
                    }
                }
            }
        }

        return $vacancy;
    }

    private function sync_single_vacancy($vacancy_basic, $vacancy_detail, $post_type) {
        global $wpdb;

        $bamboohr_id = $vacancy_basic['id'];
        $title = !empty($vacancy_detail['title']) ? $vacancy_detail['title'] : $vacancy_basic['title'];

        // Controleer of vacature al bestaat in sync table
        $sync_table = $wpdb->prefix . 'bamboohr_vacancy_sync';
        $existing_sync = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sync_table} WHERE bamboohr_id = %s",
            $bamboohr_id
        ));

        $post_data = array(
            'post_title' => $title,
            'post_content' => $vacancy_detail['description'],
            'post_status' => 'publish',
            'post_type' => $post_type
        );

        $meta_data = array(
            'bamboohr_id' => $bamboohr_id,
            'department' => $vacancy_detail['department'],
            'location' => $vacancy_detail['location'],
            'location_city' => $vacancy_detail['location_city'],
            'location_country' => $vacancy_detail['location_country'],
            'status' => $vacancy_detail['status'],
            'employment_status' => $vacancy_detail['employment_status'],
            'date_posted' => $vacancy_detail['date_posted'],
            'minimum_experience' => $vacancy_detail['minimum_experience'],
            'compensation' => $vacancy_detail['compensation'],
            'share_url' => $vacancy_detail['share_url'],
            'location_type' => $vacancy_detail['location_type']
        );

        $result = array('created' => false, 'updated' => false, 'error' => false);

        try {
            if ($existing_sync && $existing_sync->post_id) {
                // Update bestaande post
                $post_data['ID'] = $existing_sync->post_id;
                $post_id = wp_update_post($post_data);

                if (is_wp_error($post_id)) {
                    throw new Exception('Post update failed: ' . $post_id->get_error_message());
                }

                $result['updated'] = true;
            } else {
                // Cre�er nieuwe post
                $post_id = wp_insert_post($post_data);

                if (is_wp_error($post_id)) {
                    throw new Exception('Post creation failed: ' . $post_id->get_error_message());
                }

                $result['created'] = true;
            }

            // Update/set meta data
            foreach ($meta_data as $key => $value) {
                update_post_meta($post_id, $key, $value);
            }

            // Update sync table
            if ($existing_sync) {
                $wpdb->update(
                    $sync_table,
                    array(
                        'post_id' => $post_id,
                        'title' => $title,
                        'status' => $vacancy_detail['status'],
                        'department' => $vacancy_detail['department'],
                        'sync_status' => 'success',
                        'error_message' => ''
                    ),
                    array('bamboohr_id' => $bamboohr_id)
                );
            } else {
                $wpdb->insert(
                    $sync_table,
                    array(
                        'bamboohr_id' => $bamboohr_id,
                        'post_id' => $post_id,
                        'title' => $title,
                        'status' => $vacancy_detail['status'],
                        'department' => $vacancy_detail['department'],
                        'sync_status' => 'success',
                        'error_message' => ''
                    )
                );
            }

        } catch (Exception $e) {
            // Log error in sync table
            if ($existing_sync) {
                $wpdb->update(
                    $sync_table,
                    array(
                        'sync_status' => 'error',
                        'error_message' => $e->getMessage()
                    ),
                    array('bamboohr_id' => $bamboohr_id)
                );
            } else {
                $wpdb->insert(
                    $sync_table,
                    array(
                        'bamboohr_id' => $bamboohr_id,
                        'title' => $title,
                        'status' => $vacancy_detail['status'],
                        'department' => $vacancy_detail['department'],
                        'sync_status' => 'error',
                        'error_message' => $e->getMessage()
                    )
                );
            }

            $result['error'] = true;
            error_log('BambooHR Vacancy Sync Error: ' . $e->getMessage());
        }

        return $result;
    }

    private function cleanup_old_vacancies($current_vacancy_ids, $post_type) {
        global $wpdb;

        $sync_table = $wpdb->prefix . 'bamboohr_vacancy_sync';
        $deleted_count = 0;

        // Haal alle sync records op die niet meer in de huidige lijst staan
        $placeholders = implode(',', array_fill(0, count($current_vacancy_ids), '%s'));
        $old_syncs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$sync_table} WHERE bamboohr_id NOT IN ({$placeholders})",
            $current_vacancy_ids
        ));

        foreach ($old_syncs as $old_sync) {
            if ($old_sync->post_id) {
                // Verwijder de post
                $deleted = wp_delete_post($old_sync->post_id, true);
                if ($deleted) {
                    $deleted_count++;
                }
            }

            // Verwijder uit sync table
            $wpdb->delete(
                $sync_table,
                array('id' => $old_sync->id)
            );
        }

        return $deleted_count;
    }

    public function test_vacancy_sync() {
        if (!wp_verify_nonce($_POST['nonce'], 'bamboohr_admin_nonce')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Geen toestemming');
            return;
        }

        $careers_base_url = get_option('bamboohr_careers_base_url', 'https://alloptions.bamboohr.com/careers');

        // Test de careers list URL
        $list_url = $careers_base_url . '/list';
        $response = wp_remote_get($list_url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress BambooHR Plugin'
            )
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Verbindingsfout met careers lijst: ' . $response->get_error_message());
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            wp_send_json_error("HTTP fout {$response_code} bij ophalen careers lijst");
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $vacancies = $this->parse_vacancies_list_json($body);

        if (empty($vacancies)) {
            wp_send_json_error('Geen vacatures gevonden in de lijst. Mogelijk is de HTML structuur gewijzigd.');
            return;
        }

        // Test ��n detail pagina
        if (!empty($vacancies)) {
            $test_vacancy = $vacancies[0];
            $detail_url = $careers_base_url . '/' . $test_vacancy['id'] . '/detail';

            $detail_response = wp_remote_get($detail_url, array(
                'timeout' => 30,
                'headers' => array(
                    'User-Agent' => 'WordPress BambooHR Plugin',
                    'Accept' => 'application/json, text/html, */*'
                )
            ));

            if (is_wp_error($detail_response)) {
                wp_send_json_error('Verbindingsfout met detail pagina: ' . $detail_response->get_error_message());
                return;
            }

            $detail_code = wp_remote_retrieve_response_code($detail_response);
            if ($detail_code !== 200) {
                wp_send_json_error("HTTP fout {$detail_code} bij ophalen detail pagina");
                return;
            }

            $detail_body = wp_remote_retrieve_body($detail_response);
            $detail_data = $this->parse_vacancy_detail_json($detail_body);

            // Als JSON parsing faalt, probeer HTML
            if ($detail_data === false) {
                $detail_data = $this->parse_vacancy_detail_html($detail_body);
            }

            $test_results = array(
                'careers_list_url' => $list_url,
                'vacancies_found' => count($vacancies),
                'sample_vacancy' => $test_vacancy,
                'detail_url' => $detail_url,
                'detail_data' => $detail_data,
                'post_type_exists' => post_type_exists(get_option('bamboohr_vacancy_post_type', 'vacancies')),
                'response_format' => 'JSON (BambooHR API)'
            );

            wp_send_json_success('Test succesvol! Gevonden: ' . count($vacancies) . ' vacatures via JSON API.');
        } else {
            wp_send_json_error('Geen vacatures gevonden. Response format kan niet worden bepaald.');
        }
    }

    public function get_application_details() {
        if (!wp_verify_nonce($_POST['nonce'], 'bamboohr_admin_nonce')) {
            wp_die('Security check failed');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'bamboohr_applications';
        $id = intval($_POST['id']);

        $application = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d", $id
        ));

        if (!$application) {
            wp_send_json_error('Sollicitatie niet gevonden');
            return;
        }

        ob_start();
        ?>
        <h2>Application Details - ID: <?php echo $application->id; ?></h2>
        <table class="form-table">
            <tr><th>Name:</th><td><?php echo esc_html($application->first_name . ' ' . $application->last_name); ?></td></tr>
            <tr><th>Email:</th><td><?php echo esc_html($application->email); ?></td></tr>
            <tr><th>Phone:</th><td><?php echo esc_html($application->phone_number); ?></td></tr>
            <tr><th>Address:</th><td><?php echo esc_html($application->address); ?></td></tr>
            <tr><th>City:</th><td><?php echo esc_html($application->city); ?></td></tr>
            <tr><th>State/Province:</th><td><?php echo esc_html($application->state); ?></td></tr>
            <tr><th>ZIP/Postal:</th><td><?php echo esc_html($application->zip); ?></td></tr>
            <tr><th>Country:</th><td><?php echo esc_html($application->country); ?></td></tr>
            <tr><th>Resume:</th><td><?php echo $application->resume_file ? '<a href="' . esc_url($application->resume_file) . '" target="_blank">Download Resume</a>' : 'No resume'; ?></td></tr>
            <tr><th>Cover Letter:</th><td><?php echo $application->cover_letter_file ? '<a href="' . esc_url($application->cover_letter_file) . '" target="_blank">Download Cover Letter</a>' : 'No cover letter'; ?></td></tr>
            <tr><th>Date Available:</th><td><?php echo $application->date_available ? date('m/d/Y', strtotime($application->date_available)) : 'Not specified'; ?></td></tr>
            <tr><th>Desired Salary:</th><td><?php echo esc_html($application->desired_salary ?: 'Not specified'); ?></td></tr>
            <tr><th>Website:</th><td><?php echo $application->website_url ? '<a href="' . esc_url($application->website_url) . '" target="_blank">' . esc_html($application->website_url) . '</a>' : 'Not provided'; ?></td></tr>
            <tr><th>LinkedIn:</th><td><?php echo $application->linkedin_url ? '<a href="' . esc_url($application->linkedin_url) . '" target="_blank">' . esc_html($application->linkedin_url) . '</a>' : 'Not provided'; ?></td></tr>
            <tr><th>Job ID:</th><td><?php echo esc_html($application->job_id ?: 'Not specified'); ?></td></tr>
            <tr><th>Sync Status:</th><td><?php echo esc_html($application->sync_status); ?></td></tr>
            <tr><th>BambooHR ID:</th><td><?php echo esc_html($application->bamboohr_id ?: 'Not synced'); ?></td></tr>
            <tr><th>Sync Attempts:</th><td><?php echo $application->sync_attempts; ?></td></tr>
            <tr><th>Last Attempt:</th><td><?php echo $application->last_sync_attempt ?: 'Never'; ?></td></tr>
            <tr><th>Error Message:</th><td><?php echo esc_html($application->error_message ?: 'None'); ?></td></tr>
            <tr><th>Submitted:</th><td><?php echo date('m/d/Y H:i:s', strtotime($application->created_at)); ?></td></tr>
        </table>
        <?php
        $details = ob_get_clean();

        wp_send_json_success($details);
    }

    public function retry_bamboohr_sync() {
        if (!wp_verify_nonce($_POST['nonce'], 'bamboohr_admin_nonce')) {
            wp_die('Security check failed');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'bamboohr_applications';
        $id = intval($_POST['id']);

        $application = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d", $id
        ));

        if (!$application) {
            wp_send_json_error('Sollicitatie niet gevonden');
            return;
        }

        // Convert object to array for sync function
        $data = array(
            'first_name' => $application->first_name,
            'last_name' => $application->last_name,
            'email' => $application->email,
            'phone_number' => $application->phone_number,
            'address' => $application->address,
            'city' => $application->city,
            'state' => $application->state,
            'zip' => $application->zip,
            'country' => $application->country,
            'resume_file' => $application->resume_file,
            'cover_letter_file' => $application->cover_letter_file,
            'date_available' => $application->date_available,
            'desired_salary' => $application->desired_salary,
            'website_url' => $application->website_url,
            'linkedin_url' => $application->linkedin_url,
            'job_id' => $application->job_id
        );

        $sync_result = $this->sync_with_bamboohr($id, $data);

        if ($sync_result['success']) {
            wp_send_json_success('Synchronisatie gelukt! BambooHR ID: ' . $sync_result['bamboohr_id']);
        } else {
            wp_send_json_error($sync_result['error']);
        }
    }

    public function test_bamboohr_connection() {
        if (!wp_verify_nonce($_POST['nonce'], 'bamboohr_admin_nonce')) {
            wp_die('Security check failed');
        }

        $api_key = get_option('bamboohr_api_key');
        $company_domain = get_option('bamboohr_company_domain');

        if (empty($api_key) || empty($company_domain)) {
            wp_send_json_error('API key en company domain zijn vereist');
            return;
        }

        // Test with a simple API call to get company info
        $url = "https://api.bamboohr.com/api/gateway.php/{$company_domain}/v1/meta/fields/";

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_key . ':x'),
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Verbindingsfout: ' . $response->get_error_message());
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code === 200) {
            wp_send_json_success('API verbinding succesvol! Configuratie is correct.');
        } else {
            $response_body = wp_remote_retrieve_body($response);
            wp_send_json_error("API fout (HTTP {$response_code}): " . $response_body);
        }
    }
}

// Start de plugin
new BambooHRApplicantSync();