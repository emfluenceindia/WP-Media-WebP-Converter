<?php
/**
 * Plugin Name: WP Media WebP Converter
 * Description: Convert all media images to WebP format with progress bar, single image conversion, automatic conversion on upload, and lossless compression.
 * Version: 1.0.0
 * Author: Subrata Sarkar
 * Author URI: https://github.com/emfluenceindia
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wp-media-webp-converter
 * Method Prefix: wpmwc_
 * 
 * ********************** TEST ***********************************************
 * Sample call: https://techblog.lndo.site/wp-admin/admin-ajax.php?action=wpmwc_convert_individual_image&nonce=123456&id=10&overwrite=false&image_quality=50&conversion_mode=new
 * Change _GET to _POST
 * Uncomment chack_ajax_referrer
 */

defined('ABSPATH') || exit;

/**
 * We need to make sure that the user is on the admin dashboard
 * and has the Administrative privilege.
 */
if( is_admin() && current_user_can( 'manage_option' ) ) {
    require_once 'includes/wpmwc-converter.php';
} else {
    wp_die( 'Sorry! You are not authorized to perform this action!', 'Unauthorized access' );
}

/**
 * Enqueue admin scripts
 */
function wpmwc_enqueue_scripts( $hook ) {
    // Why I need this???
    //if( ! in_array( $hook, ['tools_page_webp-converter', 'upload.php'] ) ) return;

    // enqueue the script
    wp_enqueue_script( 
        'wpmwc-admin-script', // ID
        plugin_dir_url( __FILE__ ) . 'js/admin.js',  // script source
        ['jquery'], // dependency
        filemtime( plugin_dir_path( __FILE__ ) . 'js/admin.js' ), // version
        true //
    );

    //script localization
    wp_localize_script( 'wpmwc-admin-script', 'WPMWC', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'wpmwc_nonce' )
    ] );

    // enqueue CSS
    wp_enqueue_style( 
        'wpmwc-admin-style', 
        plugin_dir_url( __FILE__ ) . 'css/admin.css',
        [], 
        filemtime( plugin_dir_path( __FILE__ ) . 'css/admin.css' ) 
    );
}

add_action( 'admin_enqueue_scripts', 'wpmwc_enqueue_scripts' );

// add_action( 'admin_footer-upload.php', function() {
//     wp_enqueue_script( 
//         'upload-page-embed', // handle
//         plugin_dir_url( __FILE__ ) . 'js/upload-embed.js', // source
//         array( 'jquery' ),  // dependency
//         filemtime( plugin_dir_path( __FILE__ ) . 'js/upload-embed.js' )  // version
//     );
// } );

/**
 * Add a plugin management / settigns page in the admin
 */
function wpmwc_add_management_page() {
    add_options_page(
        __( 'Convert to WebP', 'wp-media-webp-converter' ),    // Page title
        __( 'Convert to WebP', 'wp-media-webp-converter' ),    // Menu title
        'manage_options',                                      // Capability
        'wpmwc-convert-to-webp',                               // Menu slug
        'wpmwc_render_settings_page'   // Callback function to render the page
    );
}

add_action( 'admin_menu', 'wpmwc_add_management_page' );

/**
 * Add a Settings link (plugin action link) under the plugin name on main plugin page
 * 
 * @param  array $links An array of plugin action links
 * @return array An updated array of plugin action links
 */
function wpmwc_add_plugin_settings_links( $links ) {
    // Build the URL
    $settings_url = admin_url( 'options-general.php?page=wpmwc-convert-to-webp' );

    // Create the HTML for the link
    $settings_link = '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'wp-media-webp-converter' ) . '</a>';

    // Add the link to the beginning of the links array
    array_unshift( $links, $settings_link );

    // Return the modified $links array
    return $links;
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wpmwc_add_plugin_settings_links' );

/**
  * WordPress does not automatically remove non-standard files like .webp.
  * Hence we need to hook into the deletion process to manually remove these files
  * physically from the disk whenever an attachment media is deleted from the media library
 */

 function wpmwc_delete_file_from_disk( $attachment_id ) {
    $file_path = get_attached_file( $attachment_id );

    // Physical file absent
    if( ! $file_path || ! file_exists( $file_path ) ) {
        // return;
    }

    $info      = pathinfo( $file_path );
    $basedir   = $info[ 'dirname' ];
    $basename  = $info[ 'filename' ];
    $extension = $info[ 'extension' ];
    $webp_url  = wp_upload_dir()['url'] . '/' . $basename . '.webp'; // get the full virtual path

    if( "webp" === strtolower( $extension ) ) {
        /**
         * Check for the physical existence of the file
         * Get all attachment metadata and remove them from the disk
         * Remove the main webp file from the disk
         * Let's do it by calling a separate method initially
         */

        $webp_main = $basedir . '/' . $basename . '.' . $extension;
        wpwmc_jump_delete_webp_file( $attachment_id, $basedir, $webp_main );
    } else {
        // First we remove the main .webp version (same name having .webp extension)
        $webp_main = $basedir . '/' . $basename . '.webp'; // get the physical path on disk

        // Check if this .webp is a separate attachment already
        $webp_attachment_id = wpwmc_check_if_attachment_already_exists( $webp_url );

        if( $webp_attachment_id > 0 ) return; // This is an attachment. Do not delete

        wpwmc_jump_delete_webp_file( $attachment_id, $basedir, $webp_main ); 
    }
 }

 add_action( 'delete_attachment', 'wpmwc_delete_file_from_disk' );

 /**
  * Remove webp files from the disk on attachment removal
  */
  function wpwmc_jump_delete_webp_file( $attachment_id, $basedir, $webp_physical_path ) {
    $meta = wp_get_attachment_metadata( $attachment_id );

    if( ! empty( $meta[ 'sizes' ] ) ) {
        foreach( $meta['sizes'] as $size ) {
            $size_filename = $basedir . '/' . pathinfo( $size['file'], PATHINFO_FILENAME ) . '.webp';
            if( file_exists( $size_filename ) && is_writable( $size_filename ) ) {
                unlink( $size_filename );
            }
        }
    }

    if( file_exists( $webp_physical_path ) ) {
        @unlink( $webp_physical_path );
    }
  }

/**
 * Fetch and count image files by MIME types
 */
function wpmwc_count_images_by_mime_types() {
    global $wpdb;

    $mime_types = array(
        'image/jpeg'    => 'JPEG',
        'image/png'     => 'PNG',
        'image/gif'     => 'GIF',
        //'image/svg+xml' => 'SVG'
    );

    $counts = array();

    foreach( $mime_types as $mime => $label ) {
        $count = $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts
            WHERE post_type = 'attachment' AND post_mime_type = %s", $mime )
        );

        $counts[ $label ] = $count;
    }

    return $counts;
}

/**
 * Display image counts by MIME types
 */
function wpmwc_display_image_counts_by_mime_type() {
    $counts = wpmwc_count_images_by_mime_types(); ?>
    <div class="wrap">
        <?php 
            $image_sizes = get_intermediate_image_sizes();
        ?>
        <h2><?php echo __( 'Image count by File types', 'wp-media-webp-converter' ) ?></h2>
        <table class="wpmwc-table-default" style="width: 50%;margin: 0;"  cellspacing=0 cellpadding=0>
            <tr>
                <th class="left"><?php echo __( 'MIME Type', 'wp-media-webp-converter' ) ?></th>
                <th><?php echo __( 'Image count', 'wp-media-webp-converter' ) ?></th>
            </tr>
            <?php foreach( $counts as $type => $count ) { ?>
                <tr>
                    <td class="left">
                        <?php 
                            if( 'JPEG' === strtoupper( $type ) ) $type = 'JPG/JPEG';
                            if( 'GIF' === strtoupper( $type ) ) $type .= ' (Non-animated only)';
                            echo $type;
                        ?>
                    </td>
                    <td><?php echo number_format_i18n( $count ); ?> files</td>
                </tr>
            <?php } ?>
        </table>
    </div>
<?php }


/**
 * Management page callback funciton body
 */

function wpmwc_render_settings_page() { ?>
    <div class="wrap">
        <h1><?php echo __( 'Convert images in Media Library to WebP in Bulk', 'wp-media-webp-converter' ); ?></h1>
        <hr />
        <p><?php echo __( 'Convert JPEG, PNG and GIF images in WebP format in bulk. You can choose <strong>Image Quality</strong> and/or <strong>Create New Attachment</strong> while converting.', 'wp-media-webp-converter' ); ?></p>
        <?php wpmwc_display_image_counts_by_mime_type(); ?>
        <form method="post" class="wpmwc-form-default">
            <div style="margin-top: 10px;">
                <table class="wpwmc-action-table" cellspacing="0" cellpadding="0">
                    <tr>
                        <td class="left">
                            <?php wp_nonce_field( 'wpmwc_bulk_conversion_action', 'wpmwc_bulk_conversion_nonce' ); ?>
                        </td>
                        <td class="option">
                            <input type="checkbox" id="overwrite" name="overwrite" value="1" />
                            <label for="overwrite">
                                <?php echo __( 'Overwrite existing WebP files', 'wp-media-webp-converter' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td class="left">&nbsp;</td>
                        <td>
                            <span><?php echo __( 'When <strong>Overwrite</strong> and <strong>Convert & Create New Attachment</strong> options are selected at the same time, a New Attachment will only be created if it doesn\'t exist already.', 'wp-media-webp-converter' ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td class="left">
                            <?php echo __( 'Image quality ', 'wp-media-webp-converter' ); ?>
                        </td>
                        <td class="option">
                            <select id="image_quality" name="image_quality">
                                <option value=""><?php echo __( '-- Select --', 'wp-media-webp-converter' ); ?></option>    
                                <option value="100"> <?php echo __( 'Maximum', 'wp-media-webp-converter' ); ?> </option>
                                <option value="75"><?php echo __( 'High', 'wp-media-webp-converter' ) ?></option>
                                <option value="50"><?php echo __( 'Optimized (Recommended)', 'wp-media-webp-converter' ) ?></option>
                                <option value="30"><?php echo __( 'Low', 'wp-media-webp-converter' ) ?></option>
                            </select>
                            &nbsp; <span><?php echo __( '<strong>Maximum</strong>: WebP file becomes larger.', 'wp-media-webp-converter' ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td class="left">
                            <?php echo __( 'I want to', 'wp-media-webp-converter' ); ?>
                        </td>
                        <td class="option">
                            <select id="conversion_mode" name="conversion_mode">
                                <option value=""><?php echo __( '-- Select --', 'wp-media-webp-converter' ); ?></option>
                                <option value="none"><?php echo __( 'Convert Only', 'wp-media-webp-converter' ) ?></option>
                                <option value="new"> <?php echo __( 'Convert & Create New Attachment', 'wp-media-webp-converter' ); ?> </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td class="left">&nbsp;</td>
                        <td class="option">
                            <div>
                                <?php echo __( '<b>Convert Only</b> <span>converts the image to WebP and stores the newly created file in the same folder. They are not available on the WordPress Media Library; however, they reside inside the folder.</span>' , 'wp-media-webp-converter' ); ?>
                            </div>
                            <hr />
                            <div>
                                <?php echo __( '<b>Convert & Create New Attachment</b> <span>does the same thing, but additionally creates a new media attachment at the same time, only if the same attachment does not already exist. The attachment will become available in the Media Library along with the source file.</span>' , 'wp-media-webp-converter' ); ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td class="left">&nbsp;</td>
                        <td>
                            <div>
                                <button id="wpmwc-start" class="button button-primary">
                                    <?php echo __( 'Start Conversion', 'wp-media-webp-converter' ); ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td class="left">&nbsp;</td>
                        <td>
                            <div id="wpmwc-progress-wrapper" style="display: none;margin-top: 20px;">
                                <div id="wpmwc-progress-bar">
                                    <div id="wpmwc-progress-inner"></div>
                                </div>
                                <div id="wpmwc-progress-status"  style="margin-top: 15px;">0%</div>
                            </div>
                            <div id="wpmwc-progress-log"></div>
                        </td>
                    </tr>
                </table>
            </div>
        </form>
    </div>
<?php }

/**
 * AJAX: Get the image ID
 */
function wpmwc_get_images() {
    check_ajax_referer( 'wpmwc_nonce', 'nonce' );

    $attachments = get_posts(
        array(
            'post_type'      => 'attachment',
            'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
            'posts_per_page' => -1, // fetch all
            'fields'         => 'ids',
        )
    );

    wp_send_json_success( $attachments );
}

add_action( 'wp_ajax_wpmwc_get_images', 'wpmwc_get_images' );

/**
 * AJAX: Convert individual image to WebP
 */
function wpmwc_convert_individual_image() {
    check_ajax_referer( 'wpmwc_nonce', 'nonce' );

    $id             = intval($_POST['id']);
    $overwrite      = isset( $_POST[ 'overwrite' ] ) ? (bool)$_POST[ 'overwrite' ] : false;
    $quality        = isset( $_POST[ 'image_quality' ] ) ? intval( $_POST[ 'image_quality' ] ) : 100;
    $action_mode    = isset( $_POST[ 'conversion_mode' ] ) ? sanitize_text_field( $_POST[ 'conversion_mode' ] ) : 'none';
    $file           = get_attached_file( $id );
    $info           = pathinfo( $file );


    /***** For debug. Remove on production */

    // $id             = intval( $_REQUEST['id'] );
    // $overwrite      = isset( $_REQUEST[ 'overwrite' ] ) ? (bool)$_REQUEST[ 'overwrite' ] : false;
    // $quality        = isset( $_REQUEST[ 'image_quality' ] ) ? intval( $_REQUEST[ 'image_quality' ] ) : 100;
    // $action_mode    = isset( $_REQUEST[ 'conversion_mode' ] ) ? $_REQUEST[ 'conversion_mode' ] : 'none';
    // $file           = get_attached_file( $id );
    // $info           = pathinfo( $file );

    $metadata       = wp_get_attachment_metadata( $id );
    $thumb_sizes    = isset(  $metadata[ 'sizes' ] ) ? $metadata[ 'sizez' ] : [];
    
    $thumb_files    = array();
    foreach( $thumb_sizes as $thumb_size ) {
        $file_path = $info[ 'dirname' ] . '/' . $thumb_size[ 'file' ];
        array_push( $thumb_files, $file_path );
    }
    array_unshift( $thumb_files, $file );

    $file_info = getimagesize( $file );
    $mime_type =  trim( strtolower( $file_info[ 'mime' ] ) );

    if( $mime_type === 'image/jpeg' ) {
        wpmwc_convert_jpeg_to_webp( $file, $thumb_files, $overwrite, $quality, $action_mode );
    } else if( $mime_type === 'image/png') {
        wpmwc_convert_png_to_webp( $file, $thumb_files, $overwrite, $quality, $action_mode );
    } else if( $mime_type === 'image/gif' ) {
        wpmwc_convert_gif_to_webp( $file, $thumb_files, $overwrite, $quality, $action_mode );
    }

    // if( $result ) {
    //     wp_send_json_success( 'Image has been successfully converted to WebP' );
    // } else {
    //     wp_send_json_error( 'Operation failed.' );
    // }
}

add_action( 'wp_ajax_wpmwc_convert_individual_image', 'wpmwc_convert_individual_image' );

/**
 * Media Library: Convert indivdual image
 */
function wpmwc_create_local_convert_action_link( $actions, $post ) {
    $link_html = '<a href="javascript: void(0);" class="wpmwc-convert-single" data-id="' . $post->ID . '">' . __( 'Convert to WebP', 'wp-media-webp-converter' ) . '</a>';
    if( in_array( get_post_mime_type( $post ), [ 'image/jpeg', 'image/png' ] ) ) {
        $actions[ 'convert_webp' ] = $link_html;
    }

    return $actions;
}

add_filter( 'media_row_action', 'wpmwc_create_local_convert_action_link', 10, 2 );
 

function wpmwc_generate_attachment_metadata( $metadata, $attachment_id ) {
    $mime = get_post_mime_type( $attachment_id );
    if( ! in_array( $mime, [ 'image/jpeg', 'image/png' ] ) ) return $metadata;

    $file = get_attached_file( $attachment_id );
    $info = pathinfo( $file );
    $webp = $info[ 'dirname' ] . '/' . $info[ 'filename' ] . '.webp';

    if( file_exists( $webp ) ) return $metadata;

    switch( strtolower( $info[ 'extension' ] ) ) {
        case 'jpg':
        case 'jpeg':
            $img = imagecreatefromjpeg( $file );
            break;
        case 'png':
            $img = imagecreatefrompng( $file );
            imagepalettetotruecolor( $img );
            imagealphablending( $img, true );
            imagesavealpha( $img, true );
            break;
        default:
            return $metadata;    
    }

    if( function_exists( 'imagewebp' ) ) {
        imagewebp( $img, $webp, 100 ); // Lossless compression
        imagedestroy( $img );
    }

    return $metadata;
}

add_filter( 'wp_generate_attachment_metadata', 'wpmwc_generate_attachment_metadata', 10, 2 );