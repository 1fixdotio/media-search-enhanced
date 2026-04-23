<?php
/**
 * Seed script for the Playground demo.
 *
 * Downloads the placeholder images from the repo and creates 8 attachments
 * with metadata crafted so that a search for "mountain" demonstrates the
 * plugin's cross-field reach (filename, alt text, caption, description, ID).
 *
 * Run from the Blueprint's runPHP step after wp-load.php is loaded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once '/wordpress/wp-load.php';
}
require_once ABSPATH . 'wp-admin/includes/image.php';

$base_url = 'https://raw.githubusercontent.com/1fixdotio/media-search-enhanced/master/blueprint/seed/images/';

$upload     = wp_upload_dir();
$target_dir = $upload['path'];
wp_mkdir_p( $target_dir );

/*
 * Seed data. "mountain" appears in exactly one field per row so each row
 * exercises a different match path:
 *   #1 title         (core finds it)
 *   #2 filename      (MSE only — _wp_attached_file postmeta)
 *   #3 alt           (MSE only — _wp_attachment_image_alt postmeta)
 *   #4 description   (core finds it — post_content)
 *   #5 caption       (core finds it — post_excerpt)
 *   #6 no match      (stays out of results)
 *   #7 filename+alt  (MSE only; double-match into both filename and alt)
 *   #8 id demo       (search by numeric ID — MSE exact-match)
 */
$seed = array(
	array( 'file' => 'photo-01.jpg',                 'title' => 'Mountain sunset at Rainier' ),
	array( 'file' => 'mountain-trail-alps.jpg',      'title' => 'Landscape 04' ),
	array( 'file' => 'photo-03.jpg',                 'title' => 'Photo 22',        'alt' => 'snowy mountain peaks at dawn' ),
	array( 'file' => 'photo-04.jpg',                 'title' => 'IMG_4512',        'description' => 'Mountain biking trip in the Alps' ),
	array( 'file' => 'photo-05.jpg',                 'title' => 'Untitled',        'caption' => 'Shot on our mountain hike' ),
	array( 'file' => 'photo-06.jpg',                 'title' => 'Forest walk' ),
	array( 'file' => 'mountain-peak-dolomites.jpg',  'title' => 'Alpine view',     'alt' => 'hiker on mountain ridge' ),
	array( 'file' => 'photo-08.jpg',                 'title' => 'ID demo file' ),
);

foreach ( $seed as $item ) {
	$dest = trailingslashit( $target_dir ) . $item['file'];

	$response = wp_remote_get( $base_url . $item['file'], array( 'timeout' => 30 ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		continue;
	}
	file_put_contents( $dest, wp_remote_retrieve_body( $response ) );

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $item['title'],
			'post_content'   => isset( $item['description'] ) ? $item['description'] : '',
			'post_excerpt'   => isset( $item['caption'] ) ? $item['caption'] : '',
			'post_status'    => 'inherit',
			'guid'           => trailingslashit( $upload['url'] ) . $item['file'],
		),
		$dest
	);

	if ( is_wp_error( $attachment_id ) ) {
		continue;
	}

	if ( ! empty( $item['alt'] ) ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $item['alt'] );
	}

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $dest ) );
}
