<?php
/**
 * Large-scale profiling tests — local only, excluded from CI.
 *
 * Run with: vendor/bin/phpunit --group slow
 * Set MSE_PROFILE_COUNT env var to control attachment count (default: 5000).
 *
 * Example: MSE_PROFILE_COUNT=20000 vendor/bin/phpunit --group slow
 *
 * @group slow
 */
class LargeScaleProfilingTest extends WP_UnitTestCase {

	/**
	 * @var int[] Attachment IDs created during seeding.
	 */
	private static $attachment_ids = array();
	private static $scenario_terms = array();

	/**
	 * @var int Number of attachments seeded.
	 */
	private static $count = 0;

	/**
	 * Seed attachments for volume testing.
	 * Count is controlled by MSE_PROFILE_COUNT env var (default: 5000).
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$count = (int) getenv( 'MSE_PROFILE_COUNT' ) ?: 5000;

		for ( $i = 0; $i < self::$count; $i++ ) {
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

		self::$scenario_terms = array(
			'title'    => 'profiling-special-title-only',
			'alt'      => 'profiling-special-alt-only',
			'filename' => 'profiling-special-file-only',
			'taxonomy' => 'profiling-special-taxonomy-only',
			'none'     => 'profiling-special-no-match',
		);

		$title_id = $factory->attachment->create( array(
			'post_title'     => self::$scenario_terms['title'],
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		) );
		$alt_id = $factory->attachment->create( array(
			'post_title'     => 'profiling-special-alt-holder',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		) );
		$file_id = $factory->attachment->create( array(
			'post_title'     => 'profiling-special-file-holder',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		) );
		$taxonomy_id = $factory->attachment->create( array(
			'post_title'     => 'profiling-special-taxonomy-holder',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		) );

		update_post_meta( $alt_id, '_wp_attachment_image_alt', self::$scenario_terms['alt'] );
		update_post_meta( $file_id, '_wp_attached_file', '2024/01/' . self::$scenario_terms['filename'] . '.jpg' );
		wp_insert_term( self::$scenario_terms['taxonomy'], 'media_tag' );
		wp_set_object_terms( $taxonomy_id, self::$scenario_terms['taxonomy'], 'media_tag' );

		self::$attachment_ids[] = $title_id;
		self::$attachment_ids[] = $alt_id;
		self::$attachment_ids[] = $file_id;
		self::$attachment_ids[] = $taxonomy_id;
	}

	/**
	 * Profile a search query and capture the generated SQL.
	 *
	 * @param string $search Search term.
	 * @return array { sql: string, time: float, results_count: int }
	 */
	private function profile_query( $search ) {
		global $wpdb, $wp_query;

		$wpdb->queries = array();

		$args = array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			's'           => $search,
			'fields'      => 'ids',
		);

		$original_vars        = $wp_query->query_vars;
		$wp_query->query_vars = $args;

		$start = microtime( true );
		$query = new WP_Query( $args );
		$elapsed = microtime( true ) - $start;

		$wp_query->query_vars = $original_vars;

		$search_needle = trim( explode( ',', $search )[0] );
		$captured_sql  = '';
		foreach ( $wpdb->queries as $q ) {
			if ( stripos( $q[0], $search_needle ) !== false && stripos( $q[0], 'SELECT' ) !== false ) {
				$captured_sql = $q[0];
				break;
			}
		}

		return array(
			'sql'           => $captured_sql,
			'time'          => $elapsed,
			'results_count' => $query->found_posts,
		);
	}

	/**
	 * Profile a search query and log detailed results.
	 */
	public function test_large_scale_query_profiling() {
		global $wpdb;

		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}

		$scenarios = array(
			array(
				'label'  => 'broad-title',
				'search' => 'profiling-attachment',
			),
			array(
				'label'  => 'title-only',
				'search' => self::$scenario_terms['title'],
			),
			array(
				'label'  => 'alt-only',
				'search' => self::$scenario_terms['alt'],
			),
			array(
				'label'  => 'filename-only',
				'search' => self::$scenario_terms['filename'],
			),
			array(
				'label'  => 'taxonomy-only',
				'search' => self::$scenario_terms['taxonomy'],
			),
			array(
				'label'      => 'multi-term',
				'search'     => self::$scenario_terms['title'] . ', ' . self::$scenario_terms['alt'],
				'multi_term' => true,
			),
			array(
				'label'  => 'zero-match',
				'search' => self::$scenario_terms['none'],
			),
		);

		$log  = sprintf( "\n=== Large-Scale Profiling (%s attachments) ===\n", number_format( self::$count ) );

		foreach ( $scenarios as $scenario ) {
			if ( ! empty( $scenario['multi_term'] ) ) {
				add_filter( 'mse_allow_multi_term_search', '__return_true' );
			}

			try {
				$result = $this->profile_query( $scenario['search'] );
			} finally {
				if ( ! empty( $scenario['multi_term'] ) ) {
					remove_filter( 'mse_allow_multi_term_search', '__return_true' );
				}
			}

			$this->assertNotEmpty( $result['sql'], sprintf( 'Should have captured the search SQL for %s.', $scenario['label'] ) );

			$explain = $wpdb->get_results( 'EXPLAIN ' . $result['sql'] );

			$log .= sprintf( "-- %s (%s) --\n", $scenario['label'], $scenario['search'] );
			$log .= sprintf( "Results found: %d\n", $result['results_count'] );
			$log .= sprintf( "Query time: %.4f seconds\n", $result['time'] );
			$log .= "SQL:\n" . $result['sql'] . "\n";
			$log .= "EXPLAIN output:\n";

			if ( ! empty( $explain ) ) {
				$headers = array_keys( get_object_vars( $explain[0] ) );
				$log    .= implode( "\t", $headers ) . "\n";
				foreach ( $explain as $row ) {
					$log .= implode( "\t", array_values( get_object_vars( $row ) ) ) . "\n";
				}
			}

			$log .= "\n";
		}

		$log .= "================================================\n";

		fwrite( STDERR, $log );

		$this->assertTrue( true );
	}
}
