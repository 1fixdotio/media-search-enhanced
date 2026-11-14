<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package   Media_Search_Enhanced
 * @author    1fixdotio <1fixdotio@gmail.com>
 * @license   GPL-2.0+
 * @link      http://1fix.io
 * @copyright 2014 1Fix.io
 */

// If uninstall not called from WordPress, then exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/public/class-media-search-enhanced-photo-keywords.php';

// Remove the photo keyword index and its backfill state from the current site.
$mse_uninstall_site = static function () {
	delete_post_meta_by_key( Media_Search_Enhanced_Photo_Keywords::META_KEY );
	delete_option( Media_Search_Enhanced_Photo_Keywords::BACKFILL_OPTION );
	wp_clear_scheduled_hook( Media_Search_Enhanced_Photo_Keywords::BACKFILL_HOOK );
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mse_site_id ) {
		switch_to_blog( $mse_site_id );
		$mse_uninstall_site();
		restore_current_blog();
	}
} else {
	$mse_uninstall_site();
}
