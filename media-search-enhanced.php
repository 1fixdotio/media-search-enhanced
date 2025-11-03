<?php
/**
 * Media Search Enhanced.
 *
 * Search through all fields in Media Library.
 *
 * @package   Media_Search_Enhanced
 * @author    1fixdotio <1fixdotio@gmail.com>
 * @license   GPL-2.0+
 * @link      https://1fix.io/media-search-enhanced
 * @copyright 2014-24 1Fix.io
 *
 * @wordpress-plugin
 * Plugin Name:       Media Search Enhanced
 * Plugin URI:        https://1fix.io/media-search-enhanced
 * Description:       Search through all fields in Media Library.
 * Version:           0.9.1
 * Author:            1fixdotio
 * Author URI:        https://1fix.io
 * Text Domain:       media-search-enhanced
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/1fixdotio/media-search-enhanced
 * Requires at least: 3.5
 * Tested up to:      6.4.3
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/*----------------------------------------------------------------------------*
 * Public-Facing Functionality
 *----------------------------------------------------------------------------*/

require_once( plugin_dir_path( __FILE__ ) . 'public/class-media-search-enhanced.php' );

/*
 * Register hooks that are fired when the plugin is activated or deactivated.
 * When the plugin is deleted, the uninstall.php file is loaded.
 *
 */

add_action( 'plugins_loaded', array( 'Media_Search_Enhanced', 'get_instance' ) );
