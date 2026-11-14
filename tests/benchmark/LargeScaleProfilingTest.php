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

	/**
	 * @var int Number of attachments seeded.
	 */
	private static $count = 0;

	/**
	 * @var array<string, int[]> IDs of the attachments carrying each embedded keyword.
	 */
	private static $keyword_ids = array(
		'travel'  => array(),
		'aurora'  => array(),
		'harbour' => array(),
	);

	/**
	 * Times each scenario runs; the median is reported.
	 */
	const RUNS = 3;

	/**
	 * Seed attachments for volume testing.
	 * Count is controlled by MSE_PROFILE_COUNT env var (default: 5000).
	 *
	 * Every attachment gets realistic `_wp_attachment_metadata` (file names,
	 * sizes, camera), with embedded keywords on a subset:
	 * - `travel`: every second attachment (common keyword)
	 * - `aurora`: every 1000th attachment, from the first (rare keyword)
	 * - `harbour`: every 1000th attachment, from the 501st (rare keyword)
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

			$keywords = array();
			if ( $i % 2 === 0 ) {
				$keywords[] = 'travel';
			}
			if ( $i % 1000 === 0 ) {
				$keywords[] = 'aurora';
			}
			if ( $i % 1000 === 500 ) {
				$keywords[] = 'harbour';
			}
			foreach ( $keywords as $keyword ) {
				self::$keyword_ids[ $keyword ][] = $id;
			}

			// Indexed into _mse_photo_keywords by the plugin's meta hooks.
			update_post_meta( $id, '_wp_attachment_metadata', self::attachment_metadata( $i, $keywords ) );

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
		$log  = sprintf( "\n=== Large-Scale Profiling (%s attachments) ===\n", number_format( self::$count ) );
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

	/**
	 * Profile searches that exercise each part of the query, verify their
	 * results, and log timing and peak memory.
	 *
	 * The title search above matches every row on the first condition, so
	 * MySQL never has to evaluate the keyword subquery. These scenarios do.
	 * Timings are reported, not asserted: they vary too much across machines.
	 */
	public function test_search_scenarios_profiling() {
		global $wpdb;

		$scenarios = array(
			array( 'Title, matches every row', 'profiling-attachment', self::$attachment_ids ),
			array( 'Common keyword', 'travel', self::$keyword_ids['travel'] ),
			array( 'Rare keyword', 'aurora', self::$keyword_ids['aurora'] ),
			array( 'No match', 'zq-no-match-qz', array() ),
			array( 'Metadata only, must not match', 'FinePix', array() ),
			array( 'Multi-term, two rare keywords', 'aurora, harbour', array_merge( self::$keyword_ids['aurora'], self::$keyword_ids['harbour'] ), true ),
		);

		$indexed = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = %s", Media_Search_Enhanced_Photo_Keywords::META_KEY )
		);

		$log  = sprintf(
			"\n=== Search scenarios (%s attachments, %s with keywords; median of %d runs) ===\n",
			number_format( self::$count ),
			number_format( $indexed ),
			self::RUNS
		);
		$log .= sprintf( "%-32s %-22s %8s %10s %12s %8s\n", 'Scenario', 'Search', 'Found', 'Time', 'Peak memory', 'Queries' );

		foreach ( $scenarios as $scenario ) {
			list( $label, $search, $expected ) = $scenario;
			$multi_term = ! empty( $scenario[3] );

			$result = $this->profile_search( $search, $multi_term );
			$query  = $result['query'];

			$this->assertSame( count( $expected ), (int) $query->found_posts, "$label: number of results" );
			$this->assertSame( array(), array_values( array_diff( $query->posts, $expected ) ), "$label: results outside the expected set" );

			$log .= sprintf(
				"%-32s %-22s %8d %9.4fs %12s %8d\n",
				$label,
				$search,
				$query->found_posts,
				$result['time'],
				null === $result['peak'] ? 'n/a' : sprintf( '%.1f MB', $result['peak'] / 1048576 ),
				$result['queries']
			);
		}

		if ( ! function_exists( 'memory_reset_peak_usage' ) ) {
			$log .= "Peak memory needs PHP 8.2+.\n";
		}
		$log .= "================================================\n";

		fwrite( STDERR, $log );
	}

	/**
	 * Run an admin-sized attachment search and measure it.
	 *
	 * @param string $search     Search term.
	 * @param bool   $multi_term Whether to allow comma-separated terms.
	 * @return array { query: WP_Query, time: float median seconds, peak: int|null bytes, queries: int }
	 */
	private function profile_search( $search, $multi_term ) {
		global $wpdb, $wp_query;

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => $search,
			'fields'         => 'ids',
			'posts_per_page' => 40,
			// Repeated runs would otherwise be answered from WP_Query's result cache.
			'cache_results'  => false,
		);

		// The plugin reads from global $wp_query->query_vars.
		$original_vars        = $wp_query->query_vars;
		$wp_query->query_vars = $args;
		if ( $multi_term ) {
			add_filter( 'mse_allow_multi_term_search', '__return_true' );
		}

		$times   = array();
		$peak    = null;
		$queries = 0;
		try {
			for ( $run = 0; $run < self::RUNS; $run++ ) {
				$wpdb->queries = array();
				$baseline      = memory_get_usage();
				if ( function_exists( 'memory_reset_peak_usage' ) ) {
					memory_reset_peak_usage();
				}

				$start   = microtime( true );
				$query   = new WP_Query( $args );
				$times[] = microtime( true ) - $start;

				if ( function_exists( 'memory_reset_peak_usage' ) ) {
					$peak = max( (int) $peak, memory_get_peak_usage() - $baseline );
				}
				$queries = count( $wpdb->queries );
			}
		} finally {
			$wp_query->query_vars = $original_vars;
			if ( $multi_term ) {
				remove_filter( 'mse_allow_multi_term_search', '__return_true' );
			}
		}

		sort( $times );

		return array(
			'query'   => $query,
			'time'    => $times[ (int) floor( count( $times ) / 2 ) ],
			'peak'    => $peak,
			'queries' => $queries,
		);
	}

	/**
	 * Attachment metadata shaped like what WordPress stores for an uploaded photo.
	 *
	 * @param int      $i        Attachment number.
	 * @param string[] $keywords Embedded keywords.
	 * @return array
	 */
	private static function attachment_metadata( $i, $keywords ) {
		$sizes = array();
		foreach ( array( 'thumbnail' => 150, 'medium' => 300, 'medium_large' => 768, 'large' => 1024 ) as $name => $width ) {
			$sizes[ $name ] = array(
				'file'      => "profiling-file-{$i}-{$width}x{$width}.jpg",
				'width'     => $width,
				'height'    => (int) ( $width * 0.66 ),
				'mime-type' => 'image/jpeg',
				'filesize'  => 10000 + $i,
			);
		}

		return array(
			'width'      => 6000,
			'height'     => 4000,
			'file'       => "2024/01/profiling-file-{$i}.jpg",
			'filesize'   => 5000000 + $i,
			'sizes'      => $sizes,
			'image_meta' => array(
				'aperture'          => '8',
				'credit'            => 'Profiling Photographer',
				'camera'            => 'FinePix X100',
				'caption'           => '',
				'created_timestamp' => '1700000000',
				'copyright'         => '',
				'focal_length'      => '23',
				'iso'               => '200',
				'shutter_speed'     => '0.004',
				'title'             => '',
				'orientation'       => '1',
				'keywords'          => $keywords,
			),
		);
	}
}
