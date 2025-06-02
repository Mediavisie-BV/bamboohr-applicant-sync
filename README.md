=== BambooHR Applicant Sync ===
Contributors: Jithran Sikken
Tags: bamboohr, recruitment, applications, hr, forms, job-application, api-integration
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPL v2 or later

Automatically synchronize job application forms with BambooHR via API integration.

== Description ==

This plugin enables seamless integration between WordPress job application forms and BambooHR. All submitted applications are stored locally and automatically synchronized with your BambooHR account through the API.

**Key Features:**

* Fully customizable job application form
* Automatic synchronization with BambooHR
* Local backup of all applications
* Resume/CV upload functionality
* Admin dashboard for application management
* Retry mechanism for failed synchronizations
* Comprehensive status tracking
* Secure file handling and data validation

**Shortcodes:**
`[bamboohr_application_form]`
`[bamboohr_application_form position="Job Title"]`

The plugin ensures data integrity by storing all applications locally first, then attempting to sync with BambooHR. If synchronization fails, you can retry later through the admin interface.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/bamboohr-applicant-sync/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to BambooHR > Settings to configure your API credentials
4. Use the shortcode `[bamboohr_application_form]` on any page where you want the application form

**Setup Requirements:**
* Active BambooHR account with API access
* WordPress installation with file upload capabilities
* SSL certificate recommended for secure data transmission

== Frequently Asked Questions ==

= How do I get a BambooHR API key? =

Log into your BambooHR account, navigate to Settings > API Keys, and create a new key. Give it a descriptive name like "WordPress Plugin" for easy identification.

= Are applications still saved if BambooHR sync fails? =

Yes, all applications are stored locally first. You can retry failed synchronizations later through the admin dashboard under BambooHR > Applications.

= What file types are allowed for resume uploads? =

PDF, DOC, and DOCX files up to 5MB in size are supported. Files are validated for type and size before upload.

= Can I customize the application form fields? =

The current version includes standard fields (name, email, phone, position, resume, cover letter). Custom field support may be added in future versions.

= Is the plugin secure? =

Yes, the plugin includes multiple security measures: nonce verification, data sanitization, file type validation, and SQL injection protection.

= Can I see sync status for each application? =

Yes, the admin dashboard shows detailed status information including sync attempts, success/failure status, and error messages.

== Screenshots ==

1. Job application form on frontend
2. Admin applications overview page
3. Plugin settings page with API configuration
4. Application details modal with full information
5. Sync status indicators and retry options

== Changelog ==

= 1.0.0 =
* Initial release
* BambooHR API integration
* Job application form with file upload
* Admin dashboard for application management
* Status tracking and retry functionality
* Comprehensive error handling and logging
* Security features and data validation

== Upgrade Notice ==

= 1.0.0 =
Initial release of BambooHR Applicant Sync plugin.