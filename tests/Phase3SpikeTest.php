<?php
/**
 * Phase 3 spike — validates the read-path injection sketch in
 * docs/phase-3-large-library-mode.md, section 5 (option a).
 *
 * This test does NOT exercise the full large-library mode. It proves one
 * load-bearing assumption: a `posts_clauses` filter can append
 * `AND {$posts}.ID IN (...)` alongside the plugin's existing suppression +
 * private-post visibility widening, and the resulting query still behaves
 * correctly with respect to the `mse_search_fields` filter and private-post
 * author visibility.
 *
 * If this passes, the rest of Phase 3 (schema, write hooks, WP-CLI, flag) is
 * mechanical. If it fails, the whole read-path strategy needs rethinking.
 *
 * @group phase3-spike
 */
class Phase3SpikeTest extends WP_UnitTestCase {

	/**
	 * Load the skeleton class. It is NOT loaded by the plugin bootstrap
	 * (verified below) — the spike deliberately opts in from the test only.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__ ) . '/includes/class-mse-search-index.php';
	}

	/**
	 * Create an attachment with optional title and meta.
	 */
	private function create_attachment( $post_args = array(), $meta = array() ) {
		$defaults = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => 'spike attachment',
			'post_mime_type' => 'image/jpeg',
		);
		$id = wp_insert_attachment( array_merge( $defaults, $post_args ) );

		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}

		return $id;
	}

	/**
	 * Run an attachment search with a Phase-3-style `posts_clauses` injection
	 * that pretends the index returned `$allowed_ids`.
	 *
	 * Runs with a priority later than the plugin's (20) so we see the
	 * post-Phase-1 WHERE and can append to it.
	 *
	 * @param string $search
	 * @param int[]  $allowed_ids
	 * @param array  $extra_args
	 * @return int[] Post IDs returned by WP_Query.
	 */
	private function search_with_index_injection( $search, $allowed_ids, $extra_args = array() ) {
		global $wp_query;

		$captured_where = null;

		$injector = function ( $pieces, $query ) use ( $allowed_ids, &$captured_where ) {
			// Only act on attachment searches — mirror is_mse_search() gating.
			$vars = $query->query_vars;
			if ( empty( $vars['s'] ) || ( $vars['post_type'] ?? '' ) !== 'attachment' ) {
				return $pieces;
			}

			$pieces['where'] .= MSE_Search_Index::build_in_clause( $allowed_ids );
			$captured_where   = $pieces['where'];

			return $pieces;
		};

		// Priority 50 runs AFTER the plugin's own posts_clauses (priority 20).
		add_filter( 'posts_clauses', $injector, 50, 2 );

		$args = array_merge(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				's'           => $search,
				'fields'      => 'ids',
			),
			$extra_args
		);

		$original_vars        = $wp_query->query_vars;
		$wp_query->query_vars = $args;

		try {
			$query = new WP_Query( $args );
			$ids   = $query->posts;
		} finally {
			$wp_query->query_vars = $original_vars;
			remove_filter( 'posts_clauses', $injector, 50 );
		}

		// Expose the captured WHERE to the caller via a side-channel for
		// assertions on SQL shape.
		$this->last_captured_where = $captured_where;

		return $ids;
	}

	/** @var string|null */
	private $last_captured_where;

	/**
	 * Core spike assertion: injecting `ID IN (...)` narrows the LIKE-path
	 * results to exactly the injected set, and the private-post visibility
	 * str_replace in Media_Search_Enhanced::posts_clauses is still present
	 * in the WHERE clause.
	 */
	public function test_id_in_injection_narrows_to_allowed_set_and_preserves_visibility_widening() {
		$match_a = $this->create_attachment( array( 'post_title' => 'spike-match-alpha' ) );
		$match_b = $this->create_attachment( array( 'post_title' => 'spike-match-alpha-beta' ) );
		$match_c = $this->create_attachment( array( 'post_title' => 'spike-match-alpha-gamma' ) );

		// Pretend the Phase 3 index returned only [$match_a, $match_c] for
		// this search — $match_b matches via LIKE but is not in the index set.
		$allowed_ids = array( $match_a, $match_c );

		// Need a logged-in user so the plugin enters the private-post
		// visibility-widening branch (it checks is_user_logged_in()).
		$user = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user );

		try {
			$results = $this->search_with_index_injection( 'spike-match-alpha', $allowed_ids );
		} finally {
			wp_set_current_user( 0 );
		}

		// Core assertion: result set is the intersection of LIKE matches and
		// the injected ID set.
		sort( $results );
		$expected = $allowed_ids;
		sort( $expected );
		$this->assertSame( $expected, $results, 'Result set should equal intersection of LIKE matches and injected IDs.' );

		// Assert private-post widening is still in the WHERE — the
		// str_replace in the plugin ran before our injection, and our
		// injection did not clobber it.
		$this->assertNotNull( $this->last_captured_where );
		$this->assertStringContainsString(
			"post_status = 'private'",
			$this->last_captured_where,
			'Private-post visibility widening must survive the ID IN injection.'
		);

		// Assert the ID IN fragment is actually present.
		$this->assertMatchesRegularExpression(
			'/\.ID\s+IN\s*\(/i',
			$this->last_captured_where,
			'ID IN (...) fragment must be present in the final WHERE clause.'
		);

		// Assert that the Phase 1 LIKE clauses are also still present — we
		// are layering on top of, not replacing, the existing query.
		$this->assertStringContainsString(
			'post_title LIKE',
			$this->last_captured_where,
			'Phase 1 LIKE clauses must remain when Phase 3 layers on via posts_clauses priority 50.'
		);
	}

	/**
	 * Verify that the spike does not regress the `mse_search_fields` filter:
	 * disabling a field at query time still removes the corresponding LIKE
	 * clause even when we are also injecting an ID IN list.
	 *
	 * This is the interop sanity check for section 2 of the design doc —
	 * when large-library mode is OFF (we're just layering, not replacing),
	 * the filter retains its current query-time semantics.
	 */
	public function test_search_fields_filter_still_disables_clauses_under_injection() {
		$this->create_attachment( array(
			'post_title' => 'guid-unrelated-title',
			'guid'       => 'http://example.org/wp-content/uploads/spike-guid-keyword.jpg',
		) );

		$filter = function () {
			return array( 'guid' => false );
		};
		add_filter( 'mse_search_fields', $filter );

		try {
			$this->search_with_index_injection( 'spike-guid-keyword', array( 999999 ) );
		} finally {
			remove_filter( 'mse_search_fields', $filter );
		}

		$this->assertNotNull( $this->last_captured_where );
		$this->assertStringNotContainsString(
			'.guid LIKE',
			$this->last_captured_where,
			'mse_search_fields disabling guid must still suppress the GUID LIKE clause when Phase 3 is layering on top.'
		);
		// And our injection still landed.
		$this->assertMatchesRegularExpression(
			'/\.ID\s+IN\s*\(/i',
			$this->last_captured_where
		);
	}

	/**
	 * Empty ID set must force zero rows. If the index returned nothing, the
	 * final query must not fall through to the pure LIKE path — that would
	 * cause large-library mode to silently disagree with the Phase 1 mode
	 * and confuse operators. The design doc calls this out in section 5.
	 */
	public function test_empty_ids_forces_zero_rows() {
		$id = $this->create_attachment( array( 'post_title' => 'spike-empty-test-target' ) );

		$results = $this->search_with_index_injection( 'spike-empty-test-target', array() );

		$this->assertNotContains( $id, $results, 'Empty injected ID set must force zero results.' );
		$this->assertEmpty( $results, 'Empty injected ID set must force zero results.' );

		$this->assertNotNull( $this->last_captured_where );
		$this->assertStringContainsString(
			'1=0',
			$this->last_captured_where,
			'build_in_clause() must emit 1=0 for empty input so the outer WHERE short-circuits.'
		);
	}

	/**
	 * Sanity: the spike class is NOT loaded by the plugin bootstrap. This
	 * test exists to catch accidental wiring during future refactors.
	 */
	public function test_spike_class_is_not_auto_loaded_by_plugin_bootstrap() {
		$plugin_bootstrap = file_get_contents( dirname( __DIR__ ) . '/media-search-enhanced.php' );
		$this->assertStringNotContainsString(
			'class-mse-search-index',
			$plugin_bootstrap,
			'Phase 3 spike class must remain dormant and not be required() from the plugin bootstrap.'
		);
	}
}
