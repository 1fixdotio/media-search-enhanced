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

	/**
	 * Phase 2 composite-index comparison.
	 *
	 * Captures EXPLAIN + wall-clock timing for each scenario twice:
	 *   1. Against WordPress core's default wp_postmeta schema
	 *      (PRIMARY, post_id, meta_key(191)).
	 *   2. After adding a candidate composite index on wp_postmeta:
	 *      (meta_key, post_id, meta_value(191)).
	 *
	 * The index is created inside this test method and dropped in finally{}
	 * plus a register_shutdown_function guard, so the schema is always
	 * restored — even on a fatal error. No schema change is performed by the
	 * plugin itself: this test is a measurement tool for Phase 2 of #22.
	 *
	 * Reuses the same seeded attachments (class fixtures) as
	 * test_large_scale_query_profiling so a single `bin/profile.sh N` run
	 * covers both measurements without double-seeding the DB.
	 */
	const PHASE2_INDEX_NAME = 'mse_phase2_idx_meta_key_post_id_value';

	public function test_phase2_composite_index_comparison() {
		global $wpdb;

		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}

		$runs = 3;

		// Belt-and-braces: if the process fatals between CREATE INDEX and the
		// finally{} below, still drop the index so the schema is clean for
		// subsequent runs. DDL implicitly commits, so the tear-down
		// transaction-rollback WP_UnitTestCase does between tests is not a
		// safety net for us.
		register_shutdown_function( array( __CLASS__, 'phase2_drop_candidate_index' ) );

		// Warm the InnoDB buffer pool before measuring "before". Otherwise the
		// first scenario would pay the page-load cost and look artificially
		// slow, inflating the apparent "after" improvement.
		$this->phase2_run_scenarios( 1 );

		$before = $this->phase2_run_scenarios( $runs );

		$after = array();
		try {
			self::phase2_create_candidate_index();
			// Warm again so the first "after" measurement isn't penalized by
			// the cold-cache cost of loading the new index's pages.
			$this->phase2_run_scenarios( 1 );
			$after = $this->phase2_run_scenarios( $runs );
		} finally {
			self::phase2_drop_candidate_index();
		}

		fwrite( STDERR, $this->phase2_format_diff_report( $before, $after ) );

		// Informational benchmark: just confirm we captured data for every
		// scenario under both conditions. Timings themselves are not asserted.
		foreach ( array_keys( $before ) as $label ) {
			$this->assertArrayHasKey( $label, $after, "Missing 'after' capture for {$label}" );
			$this->assertNotEmpty( $before[ $label ]['sql'], "No SQL captured for {$label} (before)" );
			$this->assertNotEmpty( $after[ $label ]['sql'],  "No SQL captured for {$label} (after)" );
		}
	}

	/**
	 * Scenario list for the Phase 2 comparison. Mirrors the scenarios used by
	 * test_large_scale_query_profiling so before/after can be related to the
	 * one-pass EXPLAIN dump above.
	 *
	 * @return array<int,array{label:string,search:string,multi_term?:bool}>
	 */
	private function phase2_scenarios() {
		return array(
			array( 'label' => 'zero-match',    'search' => self::$scenario_terms['none'] ),
			array( 'label' => 'broad-title',   'search' => 'profiling-attachment' ),
			array( 'label' => 'title-only',    'search' => self::$scenario_terms['title'] ),
			array( 'label' => 'alt-only',      'search' => self::$scenario_terms['alt'] ),
			array( 'label' => 'filename-only', 'search' => self::$scenario_terms['filename'] ),
			array( 'label' => 'taxonomy-only', 'search' => self::$scenario_terms['taxonomy'] ),
			array(
				'label'      => 'multi-term',
				'search'     => self::$scenario_terms['title'] . ', ' . self::$scenario_terms['alt'],
				'multi_term' => true,
			),
		);
	}

	/**
	 * Run a single search and capture SQL, wall-clock time, and result count.
	 *
	 * We flush the WP object cache and disable WP_Query result-caching for
	 * each invocation so the N measured runs actually hit MySQL — otherwise
	 * only the first run pays the real query cost and the rest return from
	 * object cache, giving meaningless sub-millisecond timings.
	 *
	 * @param string $search Search term.
	 * @return array{sql:string,time:float,results_count:int}
	 */
	private function phase2_profile_query( $search ) {
		global $wpdb, $wp_query;

		$wpdb->queries = array();
		wp_cache_flush();

		$args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			's'                      => $search,
			'fields'                 => 'ids',
			'cache_results'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$original_vars        = $wp_query->query_vars;
		$wp_query->query_vars = $args;

		$start   = microtime( true );
		$query   = new WP_Query( $args );
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
	 * Execute every scenario $runs times and return a per-scenario bundle:
	 *   [label => ['sql' => .., 'times' => [..], 'results_count' => .., 'explain' => [..] ]]
	 *
	 * SQL and EXPLAIN are captured from the last run so they reflect the
	 * current schema state (important because "after" runs with the candidate
	 * index created). Times are collected from all runs for min/median.
	 *
	 * @param int $runs Number of measured runs per scenario.
	 * @return array
	 */
	private function phase2_run_scenarios( $runs ) {
		global $wpdb;

		$out = array();
		foreach ( $this->phase2_scenarios() as $scenario ) {
			if ( ! empty( $scenario['multi_term'] ) ) {
				add_filter( 'mse_allow_multi_term_search', '__return_true' );
			}

			$times         = array();
			$last_sql      = '';
			$results_count = 0;

			try {
				for ( $i = 0; $i < $runs; $i++ ) {
					$res           = $this->phase2_profile_query( $scenario['search'] );
					$times[]       = $res['time'];
					$last_sql      = $res['sql'];
					$results_count = $res['results_count'];
				}
			} finally {
				if ( ! empty( $scenario['multi_term'] ) ) {
					remove_filter( 'mse_allow_multi_term_search', '__return_true' );
				}
			}

			$explain_rows = $last_sql !== '' ? $wpdb->get_results( 'EXPLAIN ' . $last_sql ) : array();

			$out[ $scenario['label'] ] = array(
				'search'        => $scenario['search'],
				'sql'           => $last_sql,
				'times'         => $times,
				'results_count' => $results_count,
				'explain'       => $explain_rows,
			);
		}
		return $out;
	}

	/**
	 * Create the candidate composite index:
	 *   (meta_key, post_id, meta_value(191))
	 *
	 * Why this shape: the EXISTS subqueries emitted by build_search_conditions
	 * have the form `WHERE post_id = X AND meta_key = 'K' AND meta_value LIKE
	 * '%..%'`. The leading wildcard makes the LIKE non-sargable, so an index
	 * can only help with the equality portion. This composite gives MySQL:
	 *   - leading `meta_key` for the equality filter,
	 *   - `post_id` for the correlation back to wp_posts.ID,
	 *   - `meta_value(191)` as a trailing covering column so the non-sargable
	 *     LIKE can be evaluated against the index leaves rather than by
	 *     jumping back to the clustered PK.
	 */
	private static function phase2_create_candidate_index() {
		global $wpdb;

		// Defensive: drop any stale copy left by a crashed prior run.
		self::phase2_drop_candidate_index();

		$wpdb->query( sprintf(
			'ALTER TABLE %s ADD INDEX %s (meta_key, post_id, meta_value(191))',
			$wpdb->postmeta,
			self::PHASE2_INDEX_NAME
		) );
	}

	/**
	 * Drop the candidate index unconditionally. Safe to call repeatedly.
	 */
	public static function phase2_drop_candidate_index() {
		global $wpdb;

		$wpdb->suppress_errors( true );
		$wpdb->query( sprintf(
			'ALTER TABLE %s DROP INDEX %s',
			$wpdb->postmeta,
			self::PHASE2_INDEX_NAME
		) );
		$wpdb->suppress_errors( false );
	}

	/**
	 * Format a human-readable side-by-side report: timing summary, then
	 * EXPLAIN deltas for the wp_postmeta subqueries per scenario.
	 */
	private function phase2_format_diff_report( array $before, array $after ) {
		$log  = "\n";
		$log .= sprintf(
			"=== Phase 2 Composite Index Comparison (%s attachments, index=(meta_key, post_id, meta_value(191)) as %s) ===\n",
			number_format( self::$count ),
			self::PHASE2_INDEX_NAME
		);
		$log .= "Methodology: 1 warmup pass per condition (discarded), then 3 measured runs per scenario.\n";
		$log .= "All WP object caches flushed between runs; WP_Query result caching disabled so each run hits MySQL.\n";
		$log .= "Reporting min/median wall-clock across the 3 measured runs, in seconds.\n\n";

		$log .= "-- Timing summary --\n";
		$log .= sprintf(
			"%-16s %12s %12s %12s %12s %12s\n",
			'scenario', 'before_min', 'before_med', 'after_min', 'after_med', 'delta_med'
		);
		foreach ( $before as $label => $b ) {
			$a     = $after[ $label ];
			$b_min = min( $b['times'] );
			$b_med = $this->phase2_median( $b['times'] );
			$a_min = min( $a['times'] );
			$a_med = $this->phase2_median( $a['times'] );
			$delta = $a_med - $b_med;
			$log  .= sprintf(
				"%-16s %12.4f %12.4f %12.4f %12.4f %+12.4f\n",
				$label, $b_min, $b_med, $a_min, $a_med, $delta
			);
		}
		$log .= "\n";

		$log .= "-- EXPLAIN diff (key / rows / filtered / Extra) per scenario --\n";
		foreach ( $before as $label => $b ) {
			$a    = $after[ $label ];
			$log .= sprintf( "[%s]  results=%d  search=%s\n", $label, $b['results_count'], $b['search'] );
			$log .= "  BEFORE:\n" . $this->phase2_explain_summary( $b['explain'] );
			$log .= "  AFTER:\n"  . $this->phase2_explain_summary( $a['explain'] );
			$log .= "\n";
		}

		$log .= "================================================\n";
		return $log;
	}

	/**
	 * Compress an EXPLAIN result set to the columns relevant to this study.
	 */
	private function phase2_explain_summary( $explain_rows ) {
		if ( empty( $explain_rows ) ) {
			return "    (no EXPLAIN rows)\n";
		}

		$out  = sprintf(
			"    %-6s %-22s %-18s %-40s %8s %10s %s\n",
			'id', 'select_type', 'table', 'key', 'rows', 'filtered', 'Extra'
		);
		foreach ( $explain_rows as $row ) {
			$r = get_object_vars( $row );
			$out .= sprintf(
				"    %-6s %-22s %-18s %-40s %8s %10s %s\n",
				$r['id']          ?? '',
				$r['select_type'] ?? '',
				$r['table']       ?? '',
				$r['key']         ?? 'NULL',
				$r['rows']        ?? '',
				$r['filtered']    ?? '',
				$r['Extra']       ?? ''
			);
		}
		return $out;
	}

	private function phase2_median( array $nums ) {
		sort( $nums );
		$c = count( $nums );
		if ( $c === 0 ) {
			return 0.0;
		}
		$mid = (int) floor( $c / 2 );
		if ( $c % 2 === 1 ) {
			return $nums[ $mid ];
		}
		return ( $nums[ $mid - 1 ] + $nums[ $mid ] ) / 2;
	}
}
