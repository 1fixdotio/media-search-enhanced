<?php
/**
 * Baseline correctness tests for Media_Search_Enhanced::posts_clauses().
 *
 * All tests must pass against the current (pre-refactor) code.
 */
class SearchTest extends WP_UnitTestCase {

	/**
	 * Run an attachment search query and return the found post IDs.
	 *
	 * @param string $search    The search term.
	 * @param array  $extra_args Additional WP_Query args.
	 * @return int[] Array of post IDs.
	 */
	private function search_attachments( $search, $extra_args = array() ) {
		global $wp_query;

		$args = array_merge( array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			's'           => $search,
			'fields'      => 'ids',
			'orderby'     => 'ID',
			'order'       => 'ASC',
		), $extra_args );

		// The plugin reads search params from global $wp_query->query_vars,
		// so we must temporarily set them for the filter to activate.
		$original_vars        = $wp_query->query_vars;
		$wp_query->query_vars = $args;

		try {
			$query = new WP_Query( $args );
			return $query->posts;
		} finally {
			$wp_query->query_vars = $original_vars;
		}
	}

	/**
	 * Helper to create an attachment with optional meta and taxonomy terms.
	 *
	 * @param array $post_args Overrides for wp_insert_attachment.
	 * @param array $meta      Key-value pairs of post meta.
	 * @return int Attachment ID.
	 */
	private function create_attachment( $post_args = array(), $meta = array() ) {
		$defaults = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => 'Test Attachment',
			'post_mime_type' => 'image/jpeg',
			'post_content'   => '',
			'post_excerpt'   => '',
		);
		$post_args = array_merge( $defaults, $post_args );
		$id = wp_insert_attachment( $post_args );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * Test 1: Search by title.
	 */
	public function test_search_by_title() {
		$id = $this->create_attachment( array( 'post_title' => 'sunset-beach-photo' ) );
		$results = $this->search_attachments( 'sunset' );
		$this->assertContains( $id, $results );
	}

	/**
	 * Test 2: Search by alt text.
	 */
	public function test_search_by_alt_text() {
		$id = $this->create_attachment( array(), array(
			'_wp_attachment_image_alt' => 'beautiful mountain landscape',
		) );
		$results = $this->search_attachments( 'mountain landscape' );
		$this->assertContains( $id, $results );
	}

	/**
	 * Test 3: Search by filename.
	 */
	public function test_search_by_filename() {
		$id = $this->create_attachment( array(), array(
			'_wp_attached_file' => '2024/01/company-logo-dark.png',
		) );
		$results = $this->search_attachments( 'company-logo' );
		$this->assertContains( $id, $results );
	}

	/**
	 * Test 4: Search by exact ID.
	 */
	public function test_search_by_id() {
		$id = $this->create_attachment( array( 'post_title' => 'id-search-test' ) );
		$results = $this->search_attachments( (string) $id );
		$this->assertContains( $id, $results );
	}

	/**
	 * Test 4b: Search by ID with leading zeros (e.g. "007" matches ID 7).
	 */
	public function test_search_by_id_with_leading_zeros() {
		$id = $this->create_attachment( array( 'post_title' => 'leading-zero-id-test' ) );
		$padded = str_pad( (string) $id, strlen( (string) $id ) + 2, '0', STR_PAD_LEFT );
		$results = $this->search_attachments( $padded );
		$this->assertContains( $id, $results, "Search for '{$padded}' should find ID {$id}." );
	}

	/**
	 * Test 4c: Numeric search uses exact ID match, not partial LIKE.
	 *
	 * Verifies via SQL inspection that searching "12" produces ID = 12,
	 * not ID LIKE '%12%'. A results-based test is unreliable here because
	 * GUIDs contain the post ID (e.g. ?p=123 matches LIKE '%12%').
	 */
	public function test_numeric_search_uses_exact_id_in_sql() {
		global $wpdb, $wp_query;

		$wpdb->queries = array();

		$args = array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			's'           => '12',
			'fields'      => 'ids',
		);
		$original_vars        = $wp_query->query_vars;
		$wp_query->query_vars = $args;
		try {
			new WP_Query( $args );
		} finally {
			$wp_query->query_vars = $original_vars;
		}

		$captured_sql = '';
		foreach ( $wpdb->queries as $q ) {
			if ( stripos( $q[0], 'SELECT' ) !== false && stripos( $q[0], "'attachment'" ) !== false ) {
				$captured_sql = $q[0];
				break;
			}
		}

		$this->assertNotEmpty( $captured_sql, 'Should have captured the search SQL.' );
		$this->assertMatchesRegularExpression( '/\.ID\s*=\s/', $captured_sql, 'Numeric search should use ID = (exact match).' );
		$this->assertDoesNotMatchRegularExpression( '/\.ID\s+LIKE/i', $captured_sql, 'Numeric search should not use ID LIKE (partial match).' );
	}

	/**
	 * Test 4d: Non-numeric search should not match any ID.
	 */
	public function test_non_numeric_search_does_not_match_ids() {
		$id = $this->create_attachment( array( 'post_title' => 'non-numeric-id-unique-xyz' ) );
		$results = $this->search_attachments( 'zzz-no-match-anywhere' );
		$this->assertNotContains( $id, $results );
	}

	/**
	 * Test 5: Search by caption (post_excerpt).
	 */
	public function test_search_by_caption() {
		$id = $this->create_attachment( array(
			'post_excerpt' => 'A photo of the annual company picnic',
		) );
		$results = $this->search_attachments( 'annual company picnic' );
		$this->assertContains( $id, $results );
	}

	/**
	 * Test 6: Search by description (post_content).
	 */
	public function test_search_by_description() {
		$id = $this->create_attachment( array(
			'post_content' => 'Detailed description of the product launch event',
		) );
		$results = $this->search_attachments( 'product launch event' );
		$this->assertContains( $id, $results );
	}

	/**
	 * Test 7: Search by GUID (partial URL match).
	 */
	public function test_search_by_guid() {
		$id = $this->create_attachment( array(
			'guid' => 'http://example.org/wp-content/uploads/unique-guid-image.jpg',
		) );
		$results = $this->search_attachments( 'unique-guid-image' );
		$this->assertContains( $id, $results );
	}

	/**
	 * Test 7b: Search fields can disable GUID matching without affecting title.
	 */
	public function test_search_fields_filter_can_disable_guid_matches() {
		$guid_only_id = $this->create_attachment( array(
			'post_title' => 'guid-filter-control-title',
			'guid'       => 'http://example.org/wp-content/uploads/guid-filter-only-match.jpg',
		) );
		$title_id = $this->create_attachment( array(
			'post_title' => 'guid-filter-title-match',
			'guid'       => 'http://example.org/wp-content/uploads/unrelated-guid-value.jpg',
		) );

		$filter = function() {
			return array( 'guid' => false );
		};

		add_filter( 'mse_search_fields', $filter );

		try {
			$guid_results  = $this->search_attachments( 'guid-filter-only-match' );
			$title_results = $this->search_attachments( 'guid-filter-title-match' );
		} finally {
			remove_filter( 'mse_search_fields', $filter );
		}

		$this->assertNotContains( $guid_only_id, $guid_results, 'GUID-only matches should be skipped when guid search is disabled.' );
		$this->assertContains( $title_id, $title_results, 'Other enabled fields should keep working when guid search is disabled.' );
	}

	/**
	 * Test 7c: Search fields filter receives query and terms context.
	 */
	public function test_search_fields_filter_receives_query_context() {
		$received_query = null;
		$received_terms = null;

		$filter = function( $fields, $query = null, $terms = null ) use ( &$received_query, &$received_terms ) {
			$received_query = $query;
			$received_terms = $terms;
			return $fields;
		};

		add_filter( 'mse_search_fields', $filter, 10, 3 );

		try {
			$this->search_attachments( 'context-check-term' );
		} finally {
			remove_filter( 'mse_search_fields', $filter, 10 );
		}

		$this->assertInstanceOf( 'WP_Query', $received_query, 'Search field filters should receive the active WP_Query instance.' );
		$this->assertSame( array( 'context-check-term' ), $received_terms, 'Search field filters should receive the parsed search terms.' );
	}

	/**
	 * Test 7d: Disabling all search fields returns no results.
	 */
	public function test_search_fields_filter_can_disable_all_fields() {
		$id = $this->create_attachment( array( 'post_title' => 'all-fields-disabled-test' ) );

		$filter = function( $fields ) {
			return array_fill_keys( array_keys( $fields ), false );
		};

		add_filter( 'mse_search_fields', $filter );

		try {
			$results = $this->search_attachments( 'all-fields-disabled-test' );
		} finally {
			remove_filter( 'mse_search_fields', $filter );
		}

		$this->assertNotContains( $id, $results, 'No results should be returned when every search field is disabled.' );
		$this->assertEmpty( $results, 'Disabling every search field should force the search to return no results.' );
	}

	/**
	 * Test 8: Search by taxonomy term name and slug.
	 */
	public function test_search_by_taxonomy_term() {
		// Register a taxonomy for attachments.
		register_taxonomy( 'media_category', 'attachment', array(
			'public' => true,
		) );

		$id   = $this->create_attachment( array( 'post_title' => 'taxonomy-test-image' ) );
		$term = wp_insert_term( 'Corporate Events', 'media_category', array(
			'slug'        => 'corporate-events',
			'description' => 'Quarterly leadership summit',
		) );
		wp_set_object_terms( $id, $term['term_id'], 'media_category' );

		// Search by term name.
		$results = $this->search_attachments( 'Corporate Events' );
		$this->assertContains( $id, $results, 'Should find attachment by taxonomy term name.' );

		// Search by term slug.
		$results = $this->search_attachments( 'corporate-events' );
		$this->assertContains( $id, $results, 'Should find attachment by taxonomy term slug.' );

		// Search by term description.
		$results = $this->search_attachments( 'Quarterly leadership summit' );
		$this->assertContains( $id, $results, 'Should find attachment by taxonomy term description.' );
	}

	/**
	 * Test 9: No false positives.
	 */
	public function test_no_false_positives() {
		$this->create_attachment( array( 'post_title' => 'real-photo' ) );
		$results = $this->search_attachments( 'xyzzy-nonexistent-gibberish-99' );
		$this->assertEmpty( $results );
	}

	/**
	 * Test 10: No duplicates when attachment has multiple meta entries.
	 */
	public function test_no_duplicates() {
		$id = $this->create_attachment(
			array( 'post_title' => 'duplicate-check-image' ),
			array(
				'_wp_attachment_image_alt' => 'duplicate-check-image',
				'_wp_attached_file'        => 'duplicate-check-image.jpg',
			)
		);

		$results = $this->search_attachments( 'duplicate-check-image' );
		$unique  = array_unique( $results );

		$this->assertCount( count( $unique ), $results, 'Results should contain no duplicate IDs.' );
		$this->assertContains( $id, $results );
	}

	/**
	 * Test 11: MIME type filter.
	 */
	public function test_mime_type_filter() {
		$image_id = $this->create_attachment( array(
			'post_title'     => 'mime-filter-test',
			'post_mime_type' => 'image/jpeg',
		) );
		$video_id = $this->create_attachment( array(
			'post_title'     => 'mime-filter-test',
			'post_mime_type' => 'video/mp4',
		) );

		$results = $this->search_attachments( 'mime-filter-test', array(
			'post_mime_type' => 'image',
		) );

		$this->assertContains( $image_id, $results );
		$this->assertNotContains( $video_id, $results );
	}

	/**
	 * Test 12: Date filter (year/month).
	 */
	public function test_date_filter() {
		$jan_id = $this->create_attachment( array(
			'post_title' => 'date-filter-test',
			'post_date'  => '2024-01-15 10:00:00',
		) );
		$jun_id = $this->create_attachment( array(
			'post_title' => 'date-filter-test',
			'post_date'  => '2024-06-15 10:00:00',
		) );

		$results = $this->search_attachments( 'date-filter-test', array(
			'm' => '202401',
		) );

		$this->assertContains( $jan_id, $results );
		$this->assertNotContains( $jun_id, $results );
	}

	/**
	 * Test 13: Post parent filter.
	 */
	public function test_post_parent_filter() {
		$parent = self::factory()->post->create();

		$attached_id = $this->create_attachment( array(
			'post_title'  => 'parent-filter-test',
			'post_parent' => $parent,
		) );
		$other_parent = self::factory()->post->create();
		$other_id = $this->create_attachment( array(
			'post_title'  => 'parent-filter-test',
			'post_parent' => $other_parent,
		) );

		$results = $this->search_attachments( 'parent-filter-test', array(
			'post_parent' => $parent,
		) );

		$this->assertContains( $attached_id, $results );
		$this->assertNotContains( $other_id, $results );
	}

	/**
	 * Test 14: Unattached filter (post_parent = 0).
	 */
	public function test_unattached_filter() {
		$unattached_id = $this->create_attachment( array(
			'post_title'  => 'unattached-filter-test',
			'post_parent' => 0,
		) );
		$parent = self::factory()->post->create();
		$attached_id = $this->create_attachment( array(
			'post_title'  => 'unattached-filter-test',
			'post_parent' => $parent,
		) );

		$results = $this->search_attachments( 'unattached-filter-test', array(
			'post_parent' => 0,
		) );

		$this->assertContains( $unattached_id, $results );
		$this->assertNotContains( $attached_id, $results );
	}

	/**
	 * Test 15: Multi-word search is treated as a single literal string.
	 *
	 * "sunset beach" should match the literal phrase, not each word separately.
	 */
	public function test_multi_word_search_is_literal() {
		$match_id = $this->create_attachment( array(
			'post_title' => 'a sunset beach panorama',
		) );
		$no_match_id = $this->create_attachment( array(
			'post_title' => 'sunset in the mountains',
		) );

		$results = $this->search_attachments( 'sunset beach' );

		$this->assertContains( $match_id, $results, 'Should find the attachment containing the full phrase.' );
		$this->assertNotContains( $no_match_id, $results, 'Should not find attachment matching only one word of the phrase.' );
	}

	/**
	 * Test 16: WPML non-interference.
	 *
	 * When WPML_Media class does not exist, the WPML code path should not execute.
	 */
	public function test_wpml_non_interference() {
		$this->assertFalse( class_exists( 'WPML_Media' ), 'WPML_Media should not exist in the test environment.' );

		$id = $this->create_attachment( array( 'post_title' => 'wpml-safe-test' ) );
		$results = $this->search_attachments( 'wpml-safe-test' );

		$this->assertContains( $id, $results, 'Search should work normally without WPML.' );
	}

	/**
	 * Test 17: $_REQUEST['query'] AJAX fallback path.
	 *
	 * When $wp_query->query_vars is empty (AJAX media modal), the plugin
	 * falls back to $_REQUEST['query']. We test this by calling
	 * posts_clauses() directly.
	 */
	public function test_request_query_ajax_fallback() {
		$id = $this->create_attachment( array( 'post_title' => 'ajax-fallback-test' ) );

		// Simulate the AJAX media modal request.
		$_REQUEST['query']  = array(
			's'         => 'ajax-fallback-test',
			'post_type' => 'attachment',
		);

		// Save and replace $wp_query with one that has empty query_vars.
		global $wp_query, $wpdb;
		$original_wp_query    = $wp_query;
		$wp_query             = new WP_Query();
		$wp_query->query_vars = array();

		$pieces = array(
			'where'    => " AND {$wpdb->posts}.post_type = 'attachment' AND {$wpdb->posts}.post_status = 'inherit'",
			'join'     => '',
			'distinct' => '',
			'orderby'  => "{$wpdb->posts}.post_date DESC",
			'fields'   => '',
			'limits'   => '',
			'groupby'  => '',
		);

		try {
			$result = Media_Search_Enhanced::posts_clauses( $pieces, $wp_query );
		} finally {
			$wp_query = $original_wp_query;
			unset( $_REQUEST['action'], $_REQUEST['query'] );
		}

		// Verify the WHERE clause was rewritten to include our search conditions.
		$this->assertStringContainsString( 'ajax-fallback-test', $result['where'], 'The fallback path should inject the search term into the WHERE clause.' );
		$this->assertStringContainsString( 'post_title LIKE', $result['where'], 'The fallback path should search post_title.' );
		$this->assertStringContainsString( 'EXISTS', $result['where'], 'The fallback path should use EXISTS subqueries for postmeta.' );
	}

	/**
	 * Test 18: Authors can find their own private attachments but not others'.
	 */
	public function test_author_sees_own_private_attachment() {
		$author_a = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_b = self::factory()->user->create( array( 'role' => 'author' ) );

		$own_private = $this->create_attachment( array(
			'post_title'  => 'private-own-attachment',
			'post_status' => 'private',
			'post_author' => $author_a,
		) );
		$other_private = $this->create_attachment( array(
			'post_title'  => 'private-other-attachment',
			'post_status' => 'private',
			'post_author' => $author_b,
		) );
		$public = $this->create_attachment( array(
			'post_title'  => 'private-public-attachment',
			'post_status' => 'inherit',
		) );

		wp_set_current_user( $author_a );

		try {
			$results = $this->search_attachments( 'private' );
		} finally {
			wp_set_current_user( 0 );
		}

		$this->assertContains( $own_private, $results, 'Author should find their own private attachment.' );
		$this->assertNotContains( $other_private, $results, 'Author should not find another author\'s private attachment.' );
		$this->assertContains( $public, $results, 'Author should find public attachments.' );
	}

	/**
	 * Test 19: Comma-separated search finds attachments matching ANY term.
	 * Multi-term search is gated to the WordPress admin; the filter forces it on here.
	 */
	public function test_comma_separated_search() {
		add_filter( 'mse_allow_multi_term_search', '__return_true' );

		$id_a = $this->create_attachment( array( 'post_title' => 'alpha-comma-test' ) );
		$id_b = $this->create_attachment( array( 'post_title' => 'bravo-comma-test' ) );
		$id_c = $this->create_attachment( array( 'post_title' => 'charlie-no-match' ) );

		try {
			$results = $this->search_attachments( 'alpha-comma-test, bravo-comma-test' );
		} finally {
			remove_filter( 'mse_allow_multi_term_search', '__return_true' );
		}

		$this->assertContains( $id_a, $results, 'Should find attachment matching first term.' );
		$this->assertContains( $id_b, $results, 'Should find attachment matching second term.' );
		$this->assertNotContains( $id_c, $results, 'Should not find attachment matching neither term.' );
	}

	/**
	 * Test 19b: Default admin context enables multi-term search without any filter.
	 *
	 * Proves that `is_admin() === true` is sufficient to trigger the comma split —
	 * no `mse_allow_multi_term_search` override required. This is the behaviour
	 * the Media Library list view (`upload.php`) and other admin screens rely on.
	 */
	public function test_comma_separated_search_in_admin_by_default() {
		set_current_screen( 'upload.php' );

		$id_a = $this->create_attachment( array( 'post_title' => 'admin-default-alpha' ) );
		$id_b = $this->create_attachment( array( 'post_title' => 'admin-default-bravo' ) );
		$id_c = $this->create_attachment( array( 'post_title' => 'admin-default-charlie-no-match' ) );

		try {
			$this->assertTrue( is_admin(), 'set_current_screen() should flip is_admin() to true.' );
			$results = $this->search_attachments( 'admin-default-alpha, admin-default-bravo' );
		} finally {
			set_current_screen( 'front' );
		}

		$this->assertContains( $id_a, $results, 'Admin default should match first term.' );
		$this->assertContains( $id_b, $results, 'Admin default should match second term.' );
		$this->assertNotContains( $id_c, $results, 'Admin default should not match unrelated attachment.' );
	}

	/**
	 * Test 19: Single term without comma behaves identically to before.
	 */
	public function test_single_term_without_comma_unchanged() {
		$id = $this->create_attachment( array( 'post_title' => 'singleton-search-test' ) );
		$no_match = $this->create_attachment( array( 'post_title' => 'unrelated-thing' ) );

		$results = $this->search_attachments( 'singleton-search-test' );

		$this->assertContains( $id, $results );
		$this->assertNotContains( $no_match, $results );
	}

	/**
	 * Test 20: Comma-separated search across different fields.
	 */
	public function test_comma_search_across_fields() {
		add_filter( 'mse_allow_multi_term_search', '__return_true' );

		$id_by_title = $this->create_attachment( array( 'post_title' => 'multi-field-title-match' ) );
		$id_by_alt   = $this->create_attachment( array( 'post_title' => 'unrelated-alt-holder' ) );
		update_post_meta( $id_by_alt, '_wp_attachment_image_alt', 'multi-field-alt-match' );

		try {
			$results = $this->search_attachments( 'multi-field-title-match, multi-field-alt-match' );
		} finally {
			remove_filter( 'mse_allow_multi_term_search', '__return_true' );
		}

		$this->assertContains( $id_by_title, $results, 'Should find attachment matching by title.' );
		$this->assertContains( $id_by_alt, $results, 'Should find attachment matching by alt text.' );
	}

	/**
	 * Test 21: Empty terms from extra commas are ignored.
	 */
	public function test_comma_search_ignores_empty_terms() {
		add_filter( 'mse_allow_multi_term_search', '__return_true' );

		$id = $this->create_attachment( array( 'post_title' => 'empty-comma-test' ) );

		try {
			$results = $this->search_attachments( ',, empty-comma-test ,,' );
		} finally {
			remove_filter( 'mse_allow_multi_term_search', '__return_true' );
		}

		$this->assertContains( $id, $results, 'Should find attachment despite extra commas.' );
	}

	/**
	 * Test 21b: Frontend search treats commas literally (no splitting).
	 */
	public function test_frontend_comma_search_is_literal() {
		// No admin context in this test run, so is_admin() is false and the
		// multi-term gate stays closed — the comma is matched literally.
		$id_a = $this->create_attachment( array( 'post_title' => 'frontend-alpha-test' ) );
		$id_b = $this->create_attachment( array( 'post_title' => 'frontend-bravo-test' ) );

		$results = $this->search_attachments( 'frontend-alpha-test, frontend-bravo-test' );

		$this->assertEmpty( $results, 'Frontend comma search should be literal (no split), matching nothing.' );
	}

	/**
	 * Test 21c: All-commas search returns no results (not all attachments).
	 * Must be authenticated to exercise the comma-splitting + empty guard path.
	 */
	public function test_all_commas_search_returns_empty() {
		add_filter( 'mse_allow_multi_term_search', '__return_true' );

		$id = $this->create_attachment( array( 'post_title' => 'should-not-appear' ) );

		try {
			$results = $this->search_attachments( ',,,' );
		} finally {
			remove_filter( 'mse_allow_multi_term_search', '__return_true' );
		}

		$this->assertEmpty( $results, 'Search with only commas should return no results.' );
	}

	/**
	 * Test 22: Terms are capped at 10.
	 */
	public function test_comma_search_caps_at_10_terms() {
		add_filter( 'mse_allow_multi_term_search', '__return_true' );

		// Use non-overlapping names (alpha, bravo, etc.) to avoid LIKE substring matches.
		$names = array( 'alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel', 'india', 'juliet', 'kilo', 'lima' );
		$ids   = array();
		foreach ( $names as $i => $name ) {
			$ids[ $i ] = $this->create_attachment( array( 'post_title' => "captest-{$name}" ) );
		}

		$search = implode( ', ', array_map( function( $name ) { return "captest-{$name}"; }, $names ) );

		try {
			$results = $this->search_attachments( $search, array( 'posts_per_page' => -1 ) );
		} finally {
			remove_filter( 'mse_allow_multi_term_search', '__return_true' );
		}

		// First 10 terms should match.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertContains( $ids[ $i ], $results, "Term '{$names[$i]}' (within cap) should match." );
		}
		// Terms 11-12 should be dropped.
		for ( $i = 10; $i < 12; $i++ ) {
			$this->assertNotContains( $ids[ $i ], $results, "Term '{$names[$i]}' (over cap) should not match." );
		}
	}
}
