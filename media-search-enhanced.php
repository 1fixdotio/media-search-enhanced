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
 * Version:           0.8.0
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

require_once( plugin_dir_path( __FILE__ ) . 'public/class-media-search-enhanced.php' );
require_once( plugin_dir_path( __FILE__ ) . 'admin/class-media-search-enhanced-admin.php' );

/*
 * Register hooks that are fired when the plugin is activated or deactivated.
 * When the plugin is deleted, the uninstall.php file is loaded.
 *
 */

add_action( 'plugins_loaded', array( 'Media_Search_Enhanced', 'get_instance' ) );
add_action( 'plugins_loaded', array( 'Media_Search_Enhanced_Admin', 'get_instance' ) );