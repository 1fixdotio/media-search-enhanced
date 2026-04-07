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
	 * Test 4: Search by ID.
	 *
	 * Note: Current code uses `ID LIKE '%term%'` which matches partial IDs.
	 * Issue #10 Phase 2 will change this to exact integer match.
	 */
	public function test_search_by_id() {
		$id = $this->create_attachment( array( 'post_title' => 'id-search-test' ) );
		$results = $this->search_attachments( (string) $id );
		$this->assertContains( $id, $results );
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
	 * Test 8: Search by taxonomy term name and slug.
	 */
	public function test_search_by_taxonomy_term() {
		// Register a taxonomy for attachments.
		register_taxonomy( 'media_category', 'attachment', array(
			'public' => true,
		) );

		$id   = $this->create_attachment( array( 'post_title' => 'taxonomy-test-image' ) );
		$term = wp_insert_term( 'Corporate Events', 'media_category', array(
			'slug' => 'corporate-events',
		) );
		wp_set_object_terms( $id, $term['term_id'], 'media_category' );

		// Search by term name.
		$results = $this->search_attachments( 'Corporate Events' );
		$this->assertContains( $id, $results, 'Should find attachment by taxonomy term name.' );

		// Search by term slug.
		$results = $this->search_attachments( 'corporate-events' );
		$this->assertContains( $id, $results, 'Should find attachment by taxonomy term slug.' );
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
		$_REQUEST['action'] = 'query-attachments';
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
			$result = Media_Search_Enhanced::posts_clauses( $pieces );
		} finally {
			$wp_query = $original_wp_query;
			unset( $_REQUEST['action'], $_REQUEST['query'] );
		}

		// Verify the WHERE clause was rewritten to include our search conditions.
		$this->assertStringContainsString( 'ajax-fallback-test', $result['where'], 'The fallback path should inject the search term into the WHERE clause.' );
		$this->assertStringContainsString( 'post_title LIKE', $result['where'], 'The fallback path should search post_title.' );
		$this->assertStringContainsString( 'EXISTS', $result['where'], 'The fallback path should use EXISTS subqueries for postmeta.' );
	}
}
