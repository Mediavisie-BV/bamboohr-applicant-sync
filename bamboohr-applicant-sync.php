<?php
/**
 * Plugin Name: BambooHR Applicant Sync
 * Plugin URI: https://github.com/Mediavisie-BV/bamboohr-applicant-sync
 * Description: Add an applicant form and sync with BambooHR
 * Version: 1.1.1
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
define('BAMBOOHR_PLUGIN_VERSION', '1.1.1');

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
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Shortcode voor het formulier
        add_shortcode('bamboohr_application_form', array($this, 'render_application_form'));
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

        // Voeg ook een optie toe voor API instellingen en versie tracking
        add_option('bamboohr_api_key', '');
        add_option('bamboohr_company_domain', '');
        add_option('bamboohr_plugin_version', BAMBOOHR_PLUGIN_VERSION);
    }

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
            'position' => ''
        ), $atts);

        ob_start();
        ?>
        <div id="bamboohr-application-form">
            <form id="bamboohr-form" enctype="multipart/form-data">
                <?php wp_nonce_field('bamboohr_form_nonce', 'bamboohr_nonce'); ?>

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
                    <input type="text" id="desiredSalary" name="desiredSalary" placeholder="e.g., $50,000 or Negotiable">
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

                <div id="form-messages"></div>
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
            'linkedin_url' => esc_url_raw($_POST['linkedinUrl'])
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
            wp_send_json_error('Error saving application');
            return;
        }

        // Try to sync with BambooHR
        $sync_result = $this->sync_with_bamboohr($local_id, $form_data);

        if ($sync_result['success']) {
            wp_send_json_success('Application submitted and synchronized successfully!');
        } else {
            // Even with sync error, give success message (data is saved locally)
            wp_send_json_success('Application received successfully! (Synchronization will be attempted later)');
        }
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
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
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
        $url = "https://api.bamboohr.com/api/gateway.php/{$company_domain}/v1/applicants/";

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
            'dateAvailable' => $data['date_available'],
            'desiredSalary' => $data['desired_salary'],
            'websiteUrl' => $data['website_url'],
            'linkedinUrl' => $data['linkedin_url'],
            'source' => 'Website'
        );

        // API call
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_key . ':x'),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($api_data),
            'timeout' => 30
        ));

        $this->increment_sync_attempts($local_id);

        if (is_wp_error($response)) {
            $this->update_sync_status($local_id, 'failed', $response->get_error_message());
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 201) {
            // Success - extract applicant ID from response
            $response_data = json_decode($response_body, true);
            $bamboohr_id = isset($response_data['id']) ? $response_data['id'] : 'unknown';

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

    public function settings_page() {
        if (isset($_POST['submit'])) {
            update_option('bamboohr_api_key', sanitize_text_field($_POST['api_key']));
            update_option('bamboohr_company_domain', sanitize_text_field($_POST['company_domain']));
            echo '<div class="notice notice-success"><p>Instellingen opgeslagen!</p></div>';
        }

        include BAMBOOHR_PLUGIN_PATH . 'admin/settings.php';
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
            'linkedin_url' => $application->linkedin_url
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