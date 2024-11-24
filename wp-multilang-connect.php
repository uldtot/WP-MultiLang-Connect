<?php
/*
Plugin Name: WP Multilang Connect
Plugin URI: https://github.com/uldtot/
Description: A plugin for connecting multiple sites by importing a file with URLs for multilingual
Version: 1.6
Author: Kim Vinberg
Author URI: https://github.com/uldtot/
License: GPL2
*/

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WP_Multilang_Connect {

    public function __construct() {
        add_action('wp_head', [$this, 'render_hreflang_links']);
        add_action('admin_menu', [$this, 'create_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp', [$this, 'schedule_cron']);
        add_action('multilangconnect_hourly_event', [$this, 'process_cron_job']);
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
            'Multilang connect Plugin',
            'Multilang connect',
            'manage_options',
            'multilangconnect',
            [$this, 'settings_page'],
            'dashicons-admin-generic',
            20
        );
    }

    public function settings_page() {
        $import_file_url = get_option('multilangconnect_import_file', '');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
            check_admin_referer('multilangconnect_save_settings');
            $import_file_url = isset($_POST['import_file']) ? sanitize_text_field($_POST['import_file']) : '';
            update_option('multilangconnect_import_file', $import_file_url);
        }

        ?>

        <div class="wrap">
            <h1>GastroImport Indstillinger</h1>
            <form method="post" action="">
                <?php wp_nonce_field( 'gastroimport_save_settings' ); ?>
    
                <h2>Import File URL</h2>
                <p>CSV file only. Firt coloumn is the site you are importing the content on. and the next rows are the other sites. First row must beheader with the lang code DK,EN,SE etc.</p>
                <p>Every hour a cronjob runs and processes the file, when done it will remove the file from the URL below.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="import_file">Importfil-URL:</label></th>
                        <td><input type="url" id="import_file" name="import_file" value="<?php echo esc_attr( $import_file_url ); ?>" placeholder="https://yourdomain.com/importfile.csv" /></td>
                    </tr>
                </table>
    
                <p><?php submit_button(); ?></p>
            </form>
        </div>
    
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#add-row').on('click', function() {
                    var newRow = '<tr><td><input type="text" name="country_code[]" value="" /></td><td><button type="button" class="button remove-row">Fjern</button></td></tr>';
                    $('#country-code-table tbody').append(newRow);
                });
    
                $(document).on('click', '.remove-row', function() {
                    $(this).closest('tr').remove();
                });
            });
        </script>
    
        <?php
    }

    public function register_settings() {
        register_setting('multilangconnect_settings_group', 'multilangconnect_import_file');
    }

    public function schedule_cron() {
        if (!wp_next_scheduled('multilangconnect_hourly_event')) {
            wp_schedule_event(time(), 'hourly', 'multilangconnect_hourly_event');
        }
    }

    public function deactivate_cron() {
        $timestamp = wp_next_scheduled('multilangconnect_hourly_event');
        wp_unschedule_event($timestamp, 'multilangconnect_hourly_event');
    }

    public function process_cron_job() {
        $import_file_url = get_option('multilangconnect_import_file');
        if (!empty($import_file_url)) {
            $this->process_import($import_file_url);
            update_option('multilangconnect_import_file', '');
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


$WP_Multilang_Connect = new WP_Multilang_Connect();