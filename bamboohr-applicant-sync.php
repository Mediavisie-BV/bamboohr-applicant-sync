<?php
/**
 * Plugin Name: BambooHR Applicant Sync
 * Plugin URI: https://github.com/Mediavisie-BV/bamboohr-applicant-sync
 * Description: Add an applicant form and sync with BambooHR
 * Version: 1.0.0
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

class BambooHRApplicantSync {
    
    public function __construct() {
        // Hook voor plugin activatie
        register_activation_hook(__FILE__, array($this, 'create_tables'));
        
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
            phone varchar(50),
            position_applied varchar(200),
            resume_file varchar(500),
            cover_letter text,
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
        
        // Voeg ook een optie toe voor API instellingen
        add_option('bamboohr_api_key', '');
        add_option('bamboohr_company_domain', '');
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
                
                <div class="form-group">
                    <label for="first_name">Voornaam *</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>
                
                <div class="form-group">
                    <label for="last_name">Achternaam *</label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
                
                <div class="form-group">
                    <label for="email">E-mail *</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="phone">Telefoon</label>
                    <input type="tel" id="phone" name="phone">
                </div>
                
                <div class="form-group">
                    <label for="position_applied">Functie *</label>
                    <input type="text" id="position_applied" name="position_applied" value="<?php echo esc_attr($atts['position']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="resume">CV (PDF, DOC, DOCX) *</label>
                    <input type="file" id="resume" name="resume" accept=".pdf,.doc,.docx" required>
                </div>
                
                <div class="form-group">
                    <label for="cover_letter">Motivatiebrief</label>
                    <textarea id="cover_letter" name="cover_letter" rows="5"></textarea>
                </div>
                
                <div class="form-group">
                    <button type="submit" id="submit-btn">Sollicitatie Versturen</button>
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
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name' => sanitize_text_field($_POST['last_name']),
            'email' => sanitize_email($_POST['email']),
            'phone' => sanitize_text_field($_POST['phone']),
            'position_applied' => sanitize_text_field($_POST['position_applied']),
            'cover_letter' => sanitize_textarea_field($_POST['cover_letter'])
        );
        
        // Handle file upload
        $resume_file = '';
        if (!empty($_FILES['resume']['name'])) {
            $resume_file = $this->handle_file_upload($_FILES['resume']);
            if (is_wp_error($resume_file)) {
                wp_send_json_error('Fout bij uploaden CV: ' . $resume_file->get_error_message());
                return;
            }
        }
        
        $form_data['resume_file'] = $resume_file;
        
        // Sla lokaal op
        $local_id = $this->save_application_locally($form_data);
        
        if (!$local_id) {
            wp_send_json_error('Fout bij opslaan sollicitatie');
            return;
        }
        
        // Probeer te syncen met BambooHR
        $sync_result = $this->sync_with_bamboohr($local_id, $form_data);
        
        if ($sync_result['success']) {
            wp_send_json_success('Sollicitatie succesvol verstuurd en gesynchroniseerd!');
        } else {
            // Ook bij sync fout, geef succesbericht (data is wel lokaal opgeslagen)
            wp_send_json_success('Sollicitatie succesvol ontvangen! (Synchronisatie wordt later uitgevoerd)');
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
                '%s', '%s', '%s', '%s', '%s', '%s', '%s'
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
        $url = "https://api.bamboohr.com/api/gateway.php/{$company_domain}/v1/applicant_tracking/application";
        
        // Prepare API data
        $api_data = array(
            'firstName' => $data['first_name'],
            'lastName' => $data['last_name'],
            'email' => $data['email'],
            'phoneNumber' => $data['phone'],
            'source' => 'Website',
            'coverLetter' => $data['cover_letter']
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
        <h2>Sollicitatie Details - ID: <?php echo $application->id; ?></h2>
        <table class="form-table">
            <tr><th>Naam:</th><td><?php echo esc_html($application->first_name . ' ' . $application->last_name); ?></td></tr>
            <tr><th>Email:</th><td><?php echo esc_html($application->email); ?></td></tr>
            <tr><th>Telefoon:</th><td><?php echo esc_html($application->phone); ?></td></tr>
            <tr><th>Functie:</th><td><?php echo esc_html($application->position_applied); ?></td></tr>
            <tr><th>CV:</th><td><?php echo $application->resume_file ? '<a href="' . esc_url($application->resume_file) . '" target="_blank">Download CV</a>' : 'Geen CV'; ?></td></tr>
            <tr><th>Motivatiebrief:</th><td><?php echo nl2br(esc_html($application->cover_letter)); ?></td></tr>
            <tr><th>Status:</th><td><?php echo esc_html($application->sync_status); ?></td></tr>
            <tr><th>BambooHR ID:</th><td><?php echo esc_html($application->bamboohr_id); ?></td></tr>
            <tr><th>Sync pogingen:</th><td><?php echo $application->sync_attempts; ?></td></tr>
            <tr><th>Laatste poging:</th><td><?php echo $application->last_sync_attempt; ?></td></tr>
            <tr><th>Foutmelding:</th><td><?php echo esc_html($application->error_message); ?></td></tr>
            <tr><th>Aangemaakt:</th><td><?php echo $application->created_at; ?></td></tr>
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
            'phone' => $application->phone,
            'position_applied' => $application->position_applied,
            'cover_letter' => $application->cover_letter,
            'resume_file' => $application->resume_file
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