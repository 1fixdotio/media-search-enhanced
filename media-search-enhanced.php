<?php
/**
 * Media Search Enhanced.
 *
 * Search through all fields in Media Library.
 *
 * @package   Media_Search_Enhanced
 * @author    1fixdotio <1fixdotio@gmail.com>
 * @license   GPL-2.0+
 * @link      http://1fix.io/media-search-enhanced
 * @copyright 2014 1Fix.io
 *
 * @wordpress-plugin
 * Plugin Name:       Media Search Enhanced
 * Plugin URI:        http://1fix.io/media-search-enhanced
 * Description:       Search through all fields in Media Library.
 * Version:           0.0.1
 * Author:            1fixdotio
 * Author URI:        http://1fix.io
 * Text Domain:       media-search-enhanced
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/1fixdotio/media-search-enhanced
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/*----------------------------------------------------------------------------*
 * Public-Facing Functionality
 *----------------------------------------------------------------------------*/

/*
 * @TODO:
 *
 * - replace `class-media-search-enhanced.php` with the name of the plugin's class file
 *
 */
require_once( plugin_dir_path( __FILE__ ) . 'public/class-media-search-enhanced.php' );

/*
 * Register hooks that are fired when the plugin is activated or deactivated.
 * When the plugin is deleted, the uninstall.php file is loaded.
 *
 * @TODO:
 *
 * - replace Media_Search_Enhanced with the name of the class defined in
 *   `class-media-search-enhanced.php`
 */
register_activation_hook( __FILE__, array( 'Media_Search_Enhanced', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Media_Search_Enhanced', 'deactivate' ) );

/*
 * @TODO:
 *
 * - replace Media_Search_Enhanced with the name of the class defined in
 *   `class-media-search-enhanced.php`
 */
add_action( 'plugins_loaded', array( 'Media_Search_Enhanced', 'get_instance' ) );

/*----------------------------------------------------------------------------*
 * Dashboard and Administrative Functionality
 *----------------------------------------------------------------------------*/

/*
 * @TODO:
 *
 * - replace `class-media-search-enhanced-admin.php` with the name of the plugin's admin file
 * - replace Media_Search_Enhanced_Admin with the name of the class defined in
 *   `class-media-search-enhanced-admin.php`
 *
 * If you want to include Ajax within the dashboard, change the following
 * conditional to:
 *
 * if ( is_admin() ) {
 *   ...
 * }
 *
 * The code below is intended to to give the lightest footprint possible.
 */
if ( is_admin() && ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) ) {

	require_once( plugin_dir_path( __FILE__ ) . 'admin/class-media-search-enhanced-admin.php' );
	add_action( 'plugins_loaded', array( 'Media_Search_Enhanced_Admin', 'get_instance' ) );

}
