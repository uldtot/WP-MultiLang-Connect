<?php
/*
Plugin Name: WP Multilang Connect
Plugin URI: https://github.com/uldtot/
Description: A plugin for connecting multiple sites by importing a file with URLs for multilingual
Version: 1.5
Author: Kim Vinberg
Author URI: https://github.com/uldtot/
License: GPL2
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WP_Multilang_Connect {
    private static $instance = null;

    private function __construct() {
        add_action('wp_head', [$this, 'render_hreflang_links']);
        add_action('admin_menu', [$this, 'create_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp', [$this, 'schedule_cron']);
        add_action('gastroimport_hourly_event', [$this, 'process_cron_job']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate_cron']);
        add_shortcode('hreflang_links', [$this, 'render_hreflang_links_shortcode']);
        add_filter('wp_nav_menu_items', [$this, 'add_hreflang_links_after_menu'], 10, 2);

        require dirname(__FILE__) . "/plugin-update-checker/plugin-update-checker.php";

        $myUpdateChecker = PucFactory::buildUpdateChecker(
            'https://github.com/uldtot/wp-multiLang-connect/',
            __FILE__,
            'wp-multiLang-connect'
        );

        //Set the branch that contains the stable release.
        $myUpdateChecker->setBranch('main');

    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function render_hreflang_links() {
        global $post;

        if (!$post) return;

        $hreflang_urls = get_post_meta($post->ID, 'hrefLangUrls', true);

        if (empty($hreflang_urls) || !is_array($hreflang_urls)) return;

        $default_locale = get_locale();
        $default_lang_code = substr($default_locale, 0, 2);

        foreach ($hreflang_urls as $lang_code => $url) {
            if (strtolower($lang_code) === strtolower($default_lang_code)) continue;
            if (strtolower($lang_code) === 'dk') $lang_code = 'da';

            echo '<link rel="alternate" hreflang="' . esc_attr(strtolower($lang_code)) . '" href="' . esc_url($url) . '" />' . PHP_EOL;
        }
    }

    public function create_menu() {
        add_menu_page(
            'GastroImport Plugin',
            'GastroImport',
            'manage_options',
            'gastroimport',
            [$this, 'settings_page'],
            'dashicons-admin-generic',
            20
        );
    }

    public function settings_page() {
        $import_file_url = get_option('gastroimport_import_file', '');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
            check_admin_referer('gastroimport_save_settings');
            $import_file_url = isset($_POST['import_file']) ? sanitize_text_field($_POST['import_file']) : '';
            update_option('gastroimport_import_file', $import_file_url);
        }

        include 'templates/settings-page.php'; // Store the HTML for settings page in a separate template file
    }

    public function register_settings() {
        register_setting('gastroimport_settings_group', 'gastroimport_import_file');
    }

    public function schedule_cron() {
        if (!wp_next_scheduled('gastroimport_hourly_event')) {
            wp_schedule_event(time(), 'hourly', 'gastroimport_hourly_event');
        }
    }

    public function deactivate_cron() {
        $timestamp = wp_next_scheduled('gastroimport_hourly_event');
        wp_unschedule_event($timestamp, 'gastroimport_hourly_event');
    }

    public function process_cron_job() {
        $import_file_url = get_option('gastroimport_import_file');
        if (!empty($import_file_url)) {
            $this->process_import($import_file_url);
            update_option('gastroimport_import_file', '');
        }
    }

    private function process_import($import_file_url) {
        if (!filter_var($import_file_url, FILTER_VALIDATE_URL)) {
            error_log("Invalid import URL: $import_file_url");
            return;
        }

        $response = wp_remote_get($import_file_url);

        if (is_wp_error($response)) {
            error_log("Error fetching file: " . $response->get_error_message());
            return;
        }

        $csv_body = wp_remote_retrieve_body($response);
        if (empty($csv_body)) {
            error_log("Empty CSV file: $import_file_url");
            return;
        }

        $lines = explode("\n", $csv_body);
        $languages = str_getcsv(array_shift($lines));

        foreach ($lines as $line) {
            if (empty($line)) continue;

            $columns = str_getcsv($line);
            $main_url = $columns[0] ?? '';

            if (!filter_var($main_url, FILTER_VALIDATE_URL)) {
                error_log("Invalid URL: $main_url");
                continue;
            }

            $post_id = url_to_postid($main_url);
            if (!$post_id) {
                error_log("No post found for URL: $main_url");
                continue;
            }

            $hrefLangUrls = [];
            foreach ($languages as $i => $lang_code) {
                if (isset($columns[$i]) && !empty($columns[$i])) {
                    $hrefLangUrls[$lang_code] = esc_url_raw($columns[$i]);
                }
            }

            update_post_meta($post_id, 'hrefLangUrls', $hrefLangUrls);
        }
    }

    public function render_hreflang_links_shortcode() {
        ob_start();
        echo '<div class="hreflang-links-wrapper">';
        $this->render_hreflang_links();
        echo '</div>';
        return ob_get_clean();
    }

    public function add_hreflang_links_after_menu($items, $args) {
        if ($args->theme_location == 'primary') {
            $items .= do_shortcode('[hreflang_links]');
        }
        return $items;
    }
}

WP_Multilang_Connect::get_instance();
