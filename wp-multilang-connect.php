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


// Forhindre direkte adgang til filen
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

function head_render_hreflang_links() {
    global $post;

    // Retrieve stored hreflang URLs from post meta
    $hreflang_urls = get_post_meta( $post->ID, 'hrefLangUrls', true );

    // Ensure we have an array of hreflang URLs
    if ( empty( $hreflang_urls ) || ! is_array( $hreflang_urls ) ) {
        return; // Exit if no hreflang URLs are available
    }

    // Get the default site language code and format it for hreflang
    $default_locale = get_locale();
    $default_lang_code = substr( $default_locale, 0, 2 ); // Extract the first two letters for the language code

    // Loop through the hreflang URLs and output each alternate link tag
    foreach ( $hreflang_urls as $lang_code => $url ) {
        // Skip the default language if it matches the current
        if ( strtolower( $lang_code ) === strtolower( $default_lang_code ) ) {
            continue;
        }

        // Ensure Danish language uses the correct hreflang code 'da' or 'da-DK'
        if ( strtolower( $lang_code ) === 'dk' ) {
            $lang_code = 'da'; // Correcting 'dk' to 'da'
        }

        // Output each alternate language link tag with lowercase hreflang attribute
        echo '<link rel="alternate" hreflang="' . esc_attr( strtolower( $lang_code ) ) . '" href="' . esc_url( $url ) . '" />' . PHP_EOL;
    }
}

// Add the hreflang links to the head
add_action( 'wp_head', 'head_render_hreflang_links' );



// Tilføj menu-side
function gastroimport_create_menu() {
    add_menu_page(
        'GastroImport Plugin',
        'GastroImport',
        'manage_options',
        'gastroimport',
        'gastroimport_settings_page',
        'dashicons-admin-generic',
        20
    );
}
add_action( 'admin_menu', 'gastroimport_create_menu' );

// Vis indstillingsside
function gastroimport_settings_page() {
    $country_codes = get_option( 'gastroimport_country_codes', array() );
    $import_file_url = get_option( 'gastroimport_import_file', '' );

    if ( isset( $_POST['submit'] ) ) {
        check_admin_referer( 'gastroimport_save_settings' );

        $import_file_url = isset( $_POST['import_file'] ) ? sanitize_text_field( $_POST['import_file'] ) : '';
        update_option( 'gastroimport_import_file', $import_file_url );
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

// Registrer indstillinger
function gastroimport_register_settings() {
    register_setting( 'gastroimport_settings_group', 'gastroimport_country_codes' );
    register_setting( 'gastroimport_settings_group', 'gastroimport_import_file' );
}
add_action( 'admin_init', 'gastroimport_register_settings' );

// Planlæg cronjob hver time
function gastroimport_schedule_cron() {
    if ( ! wp_next_scheduled( 'gastroimport_hourly_event' ) ) {
        wp_schedule_event( time(), 'hourly', 'gastroimport_hourly_event' );
    }
}
add_action( 'wp', 'gastroimport_schedule_cron' );

// Fjern cronjob, når plugin deaktiveres
function gastroimport_deactivate() {
    $timestamp = wp_next_scheduled( 'gastroimport_hourly_event' );
    wp_unschedule_event( $timestamp, 'gastroimport_hourly_event' );
}
register_deactivation_hook( __FILE__, 'gastroimport_deactivate' );

// Cronjob callback
add_action( 'gastroimport_hourly_event', 'gastroimport_check_and_process_import' );

// Tjek om import file er udfyldt og kør processimport
function gastroimport_check_and_process_import() {
    // Hent import file URL fra databasen
    $import_file_url = get_option( 'gastroimport_import_file' );

    // Hvis feltet ikke er tomt, kald processimport funktionen
    if ( ! empty( $import_file_url ) ) {
        processimport();
        
        // Nulstil import file URL-feltet
        update_option( 'gastroimport_import_file', '' );
    }
}

// Eksempel på funktionen processimport
function processimport() {
    // Hent import file URL fra databasen
    $import_file_url = get_option( 'gastroimport_import_file' );

    // Tjek om URL'en er gyldig
    if ( empty( $import_file_url ) || ! filter_var( $import_file_url, FILTER_VALIDATE_URL ) ) {
        error_log( "Ugyldig import URL." );
        return;
    }

    // Download CSV-filen fra den angivne URL
    $response = wp_remote_get( $import_file_url );

    // Tjek for fejl under download
    if ( is_wp_error( $response ) ) {
        error_log( "Fejl ved download af filen: " . $response->get_error_message() );
        return;
    }

    // Hent CSV-indholdet
    $csv_body = wp_remote_retrieve_body( $response );

    // Tjek om CSV-indholdet er hentet korrekt
    if ( empty( $csv_body ) ) {
        error_log( "Filen indeholder ingen data." );
        return;
    }

    // Split indholdet i linjer
    $csv_lines = explode( "\n", $csv_body );

    // Tjek om der er noget indhold
    if ( count( $csv_lines ) <= 1 ) {
        error_log( "CSV-filen er tom eller har kun en linje." );
        return;
    }

    // Første linje indeholder sprogene (f.eks. "DK", "EN", "SE")
    $languages = str_getcsv( array_shift( $csv_lines ) );

    // Start behandlingen af URL'er og opdatering af post-meta
    foreach ( $csv_lines as $line ) {
        // Ignorer tomme linjer
        if ( empty( $line ) ) {
            continue;
        }

        // Del linjen op i kolonner (URL'er)
        $columns = str_getcsv( $line );

        // Første kolonne er URL'en vi skal finde post_id for
        $main_url = isset( $columns[0] ) ? $columns[0] : '';

        // Tjek om URL'en er gyldig
        if ( empty( $main_url ) || ! filter_var( $main_url, FILTER_VALIDATE_URL ) ) {
            error_log( "Ugyldig URL: $main_url" );
            continue;
        }

        // Find post_id for den første URL (første kolonne)
        $post_id = url_to_postid( $main_url );

        // Tjek om vi fandt et post_id
        if ( ! $post_id ) {
            error_log( "Ingen post fundet for URL: $main_url" );
            continue;
        }

        // Opret et array til hrefLangUrls, hvor nøgler er sprogkoderne og værdier er URL'erne
        $hrefLangUrls = array();

        // Loop igennem sprogene og URL'er (starter fra anden kolonne)
        for ( $i = 0; $i < count( $languages ); $i++ ) {
            if ( isset( $columns[ $i ] ) && ! empty( $columns[ $i ] ) ) {
                $hrefLangUrls[ $languages[ $i ] ] = esc_url_raw( $columns[ $i ] );
            }
        }

        // Opdater eller tilføj meta feltet 'hrefLangUrls' for denne post
        update_post_meta( $post_id, 'hrefLangUrls', $hrefLangUrls );

        // Log succes for denne post
        error_log( "Meta-feltet hrefLangUrls opdateret for post ID: $post_id" );
    }

    // Når importen er færdig, nulstil feltet for import file URL
    update_option( 'gastroimport_import_file', '' );
}

function get_post_id_from_url( $url ) {
    // Brug WordPress' indbyggede funktion til at finde post_id fra en URL
    $post_id = url_to_postid( $url );

    // Tjek om et post_id blev fundet
    if ( $post_id ) {
        return $post_id;
    } else {
        // Hvis URL'en ikke matcher en post, returner en fejlbesked
        return "Ingen post fundet for denne URL.";
    }
}


function render_hreflang_links() {
    // Få det aktuelle post ID
    global $post;

    // Hent meta-værdien for 'hrefLangUrls'
    $hreflang_urls = get_post_meta( $post->ID, 'hrefLangUrls', true );

    // Tjek om der er nogle hreflang URLs
    if ( empty( $hreflang_urls ) || ! is_array( $hreflang_urls ) ) {
        return ''; // Hvis der ikke er nogen, returner ingenting
    }

    // Start output-buffering for at opbygge HTML
    ob_start();

    echo '<div class="hreflang-links-wrapper">';

    // Loop igennem hrefLangUrls for at generere links
    foreach ( $hreflang_urls as $lang_code => $url ) {
        // Lav et klikbart link for hver sprogkode med klasse
        echo '<a class="hreflang-link lang-' . esc_attr( $lang_code ) . '" href="' . esc_url( $url ) . '" hreflang="' . esc_attr( $lang_code ) . '">' . esc_html( strtoupper( $lang_code ) ) . '</a> ';
    }

    echo '</div>';

    // Returner den opsamlede output
    return ob_get_clean();
}

// Registrer shortcode [hreflang_links]
add_shortcode( 'hreflang_links', 'render_hreflang_links' );

// Function to add shortcode after the primary menu
function add_hreflang_links_after_menu($items, $args) {
    // Check if this is the primary menu
    if ($args->theme_location == 'primary') {
        // Add the shortcode after the menu
        $items .= do_shortcode('[hreflang_links]');
    }
    return $items;
}

// Hook the function to the primary menu
add_filter('wp_nav_menu_items', 'add_hreflang_links_after_menu', 10, 2);
