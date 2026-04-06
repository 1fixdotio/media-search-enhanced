<?php
/**
 * Large-scale profiling tests — local only, excluded from CI.
 *
 * Run with: vendor/bin/phpunit --group slow
 *
 * @group slow
 */
class LargeScaleProfilingTest extends WP_UnitTestCase {

	/**
	 * @var int[] Attachment IDs created during seeding.
	 */
	private static $attachment_ids = array();

	/**
	 * Seed 5,000+ attachments for realistic volume testing.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		$count = 5000;

		for ( $i = 0; $i < $count; $i++ ) {
			$id = $factory->attachment->create( array(
				'post_title'     => "profiling-attachment-{$i}",
				'post_status'    => 'inherit',
				'post_mime_type' => ( $i % 4 === 0 ) ? 'image/png' : 'image/jpeg',
				'post_content'   => "Description for profiling attachment {$i}",
				'post_excerpt'   => "Caption for profiling {$i}",
			) );

			update_post_meta( $id, '_wp_attachment_image_alt', "Alt text for profiling image {$i}" );
			update_post_meta( $id, '_wp_attached_file', "2024/01/profiling-file-{$i}.jpg" );

			self::$attachment_ids[] = $id;
		}

		// Assign taxonomy terms to a subset.
		register_taxonomy( 'media_tag', 'attachment', array( 'public' => true ) );
		$terms = array( 'landscape', 'portrait', 'product', 'team', 'event', 'banner', 'icon', 'hero' );
		foreach ( $terms as $term_name ) {
			if ( ! term_exists( $term_name, 'media_tag' ) ) {
				wp_insert_term( $term_name, 'media_tag' );
			}
		}
		foreach ( self::$attachment_ids as $idx => $id ) {
			if ( $idx % 10 === 0 ) {
				$term_name = $terms[ $idx % count( $terms ) ];
				wp_set_object_terms( $id, $term_name, 'media_tag' );
			}
		}
	}

	/**
	 * Profile a search query and log detailed results.
	 */
	public function test_large_scale_query_profiling() {
		global $wpdb, $wp_query;

		$wpdb->queries = array();
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}

		$search = 'profiling-attachment';

		$args = array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			's'           => $search,
			'fields'      => 'ids',
		);

		// The plugin reads from global $wp_query->query_vars.
		$original_vars        = $wp_query->query_vars;
		$wp_query->query_vars = $args;

		$start = microtime( true );

		$query = new WP_Query( $args );

		$elapsed = microtime( true ) - $start;

		$wp_query->query_vars = $original_vars;

		// Find the main query SQL.
		$captured_sql = '';
		foreach ( $wpdb->queries as $q ) {
			if ( stripos( $q[0], $search ) !== false && stripos( $q[0], 'SELECT' ) !== false ) {
				$captured_sql = $q[0];
				break;
			}
		}

		$this->assertNotEmpty( $captured_sql, 'Should have captured the search SQL.' );

		// Run EXPLAIN.
		$explain = $wpdb->get_results( 'EXPLAIN ' . $captured_sql );

		// Detailed log output.
		$log  = "\n=== Large-Scale Profiling (5,000 attachments) ===\n";
		$log .= sprintf( "Results found: %d\n", $query->found_posts );
		$log .= sprintf( "Query time: %.4f seconds\n", $elapsed );
		$log .= "SQL:\n" . $captured_sql . "\n\n";
		$log .= "EXPLAIN output:\n";

		if ( ! empty( $explain ) ) {
			$headers = array_keys( get_object_vars( $explain[0] ) );
			$log    .= implode( "\t", $headers ) . "\n";
			foreach ( $explain as $row ) {
				$log .= implode( "\t", array_values( get_object_vars( $row ) ) ) . "\n";
			}
		}

		$log .= "================================================\n";

		fwrite( STDERR, $log );

		$this->assertTrue( true );
	}
}
