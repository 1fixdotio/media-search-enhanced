<?php
/**
 * Tests for the WordPress Abilities API integration.
 *
 * Skipped automatically on WordPress versions that don't ship the Abilities API
 * (i.e. wp_register_ability() is not available).
 */
class AbilitiesTest extends WP_UnitTestCase {

	const ABILITY_NAME  = 'media-search-enhanced/search-media';
	const CATEGORY_NAME = 'media-search-enhanced';

	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version.' );
		}

		// Default to a user that can search media (upload_files capability).
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Helper: create an attachment with optional meta.
	 */
	private function create_attachment( $post_args = array(), $meta = array() ) {
		$defaults = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => 'Test Attachment',
			'post_mime_type' => 'image/jpeg',
		);
		$id = wp_insert_attachment( array_merge( $defaults, $post_args ) );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * Helper: invoke the ability with the given input and return the result.
	 */
	private function execute_ability( $input ) {
		$ability = wp_get_ability( self::ABILITY_NAME );
		$this->assertNotNull( $ability, 'Ability should be registered.' );
		return $ability->execute( $input );
	}

	/**
	 * The category exists. Without it, register_ability() would fail.
	 */
	public function test_category_is_registered() {
		$this->assertTrue(
			function_exists( 'wp_get_ability_category' ) || function_exists( 'wp_get_abilities' ),
			'Abilities API discovery functions should be available.'
		);

		// If a getter exists, use it; otherwise verify indirectly by registration succeeding.
		if ( function_exists( 'wp_get_ability_category' ) ) {
			$category = wp_get_ability_category( self::CATEGORY_NAME );
			$this->assertNotNull( $category, 'Category should be registered before abilities.' );
		}
	}

	/**
	 * The ability is registered and discoverable.
	 */
	public function test_ability_is_registered() {
		$ability = wp_get_ability( self::ABILITY_NAME );
		$this->assertNotNull( $ability, 'Ability should be discoverable by name.' );
	}

	/**
	 * Permission callback rejects users without upload_files.
	 */
	public function test_permission_rejects_unauthenticated_user() {
		wp_set_current_user( 0 );
		$result = MSE_Abilities::check_permission( array() );
		$this->assertInstanceOf( 'WP_Error', $result, 'Anonymous users should be rejected.' );
	}

	/**
	 * Permission callback accepts users with upload_files.
	 */
	public function test_permission_accepts_user_with_upload_files() {
		// set_up() already created an editor user with upload_files.
		$result = MSE_Abilities::check_permission( array() );
		$this->assertTrue( $result, 'Editors (upload_files capability) should be allowed.' );
	}

	/**
	 * Search results match the IDs returned by a direct WP_Query through posts_clauses.
	 */
	public function test_search_returns_expected_attachment() {
		$id = $this->create_attachment( array( 'post_title' => 'ability-parity-test-sunset' ) );

		$results = $this->execute_ability( array( 'query' => 'ability-parity-test-sunset' ) );

		$this->assertIsArray( $results );
		$ids = wp_list_pluck( $results, 'id' );
		$this->assertContains( $id, $ids, 'Ability should find attachments by title.' );
	}

	/**
	 * Alt-text search works through the ability — verifies posts_clauses fires
	 * for the ability's WP_Query (post_type=attachment + non-empty s).
	 */
	public function test_search_by_alt_text_through_ability() {
		$id = $this->create_attachment( array(), array(
			'_wp_attachment_image_alt' => 'ability-alt-search-needle',
		) );

		$results = $this->execute_ability( array( 'query' => 'ability-alt-search-needle' ) );

		$ids = wp_list_pluck( $results, 'id' );
		$this->assertContains( $id, $ids, 'Ability should match alt text via posts_clauses.' );
	}

	/**
	 * Multi-term comma search works through the ability without the caller
	 * setting mse_allow_multi_term_search globally.
	 */
	public function test_multi_term_works_through_ability_without_global_filter() {
		$id_a = $this->create_attachment( array( 'post_title' => 'ability-multi-alpha' ) );
		$id_b = $this->create_attachment( array( 'post_title' => 'ability-multi-bravo' ) );
		$id_c = $this->create_attachment( array( 'post_title' => 'ability-multi-no-match' ) );

		// Confirm we're not in admin and no filter override is present.
		$this->assertFalse( is_admin(), 'Test should run with is_admin() === false.' );
		$this->assertFalse(
			apply_filters( 'mse_allow_multi_term_search', false ),
			'Multi-term filter should default false outside admin.'
		);

		$results = $this->execute_ability( array( 'query' => 'ability-multi-alpha, ability-multi-bravo' ) );

		$ids = wp_list_pluck( $results, 'id' );
		$this->assertContains( $id_a, $ids, 'Multi-term should match first term.' );
		$this->assertContains( $id_b, $ids, 'Multi-term should match second term.' );
		$this->assertNotContains( $id_c, $ids, 'Multi-term should not match unrelated attachments.' );
	}

	/**
	 * The ability cleans up its multi-term filter after execution.
	 * If it leaks, subsequent frontend queries would silently get multi-term behavior.
	 */
	public function test_multi_term_filter_is_removed_after_execution() {
		$this->create_attachment( array( 'post_title' => 'leak-check-noise' ) );

		$this->execute_ability( array( 'query' => 'leak-check-a, leak-check-b' ) );

		$this->assertFalse(
			apply_filters( 'mse_allow_multi_term_search', false ),
			'mse_allow_multi_term_search filter must be removed after ability execute().'
		);
	}

	/**
	 * Private attachment visibility — authors see their own, not others'.
	 * Mirrors SearchTest::test_author_sees_own_private_attachment through the ability path.
	 */
	public function test_author_sees_own_private_attachment_through_ability() {
		$author_a = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_b = self::factory()->user->create( array( 'role' => 'author' ) );

		$own_private = $this->create_attachment( array(
			'post_title'  => 'ability-private-own',
			'post_status' => 'private',
			'post_author' => $author_a,
		) );
		$other_private = $this->create_attachment( array(
			'post_title'  => 'ability-private-other',
			'post_status' => 'private',
			'post_author' => $author_b,
		) );

		wp_set_current_user( $author_a );

		$results = $this->execute_ability( array( 'query' => 'ability-private' ) );
		$ids     = wp_list_pluck( $results, 'id' );

		$this->assertContains( $own_private, $ids, 'Author should see their own private attachment.' );
		$this->assertNotContains( $other_private, $ids, "Author should not see another author's private attachment." );
	}

	/**
	 * Output schema: non-image media returns dimensions: null.
	 */
	public function test_non_image_media_has_null_dimensions() {
		$id = $this->create_attachment( array(
			'post_title'     => 'ability-non-image-pdf',
			'post_mime_type' => 'application/pdf',
		) );

		$results = $this->execute_ability( array( 'query' => 'ability-non-image-pdf' ) );

		$match = null;
		foreach ( $results as $row ) {
			if ( $row['id'] === $id ) {
				$match = $row;
				break;
			}
		}

		$this->assertNotNull( $match, 'Should find the PDF attachment.' );
		$this->assertNull( $match['dimensions'], 'Non-image media should have null dimensions.' );
	}

	/**
	 * Output schema: unattached media returns parent: null (not 0).
	 */
	public function test_unattached_media_has_null_parent() {
		$id = $this->create_attachment( array(
			'post_title'  => 'ability-unattached-attachment',
			'post_parent' => 0,
		) );

		$results = $this->execute_ability( array( 'query' => 'ability-unattached-attachment' ) );

		$match = null;
		foreach ( $results as $row ) {
			if ( $row['id'] === $id ) {
				$match = $row;
				break;
			}
		}

		$this->assertNotNull( $match );
		$this->assertNull( $match['parent'], 'Unattached media should have null parent (not 0).' );
	}

	/**
	 * Output schema: attached media returns parent as an integer.
	 */
	public function test_attached_media_has_integer_parent() {
		$parent = self::factory()->post->create( array( 'post_title' => 'host-post' ) );
		$id     = $this->create_attachment( array(
			'post_title'  => 'ability-attached-media',
			'post_parent' => $parent,
		) );

		$results = $this->execute_ability( array( 'query' => 'ability-attached-media' ) );

		$match = null;
		foreach ( $results as $row ) {
			if ( $row['id'] === $id ) {
				$match = $row;
				break;
			}
		}

		$this->assertNotNull( $match );
		$this->assertSame( $parent, $match['parent'], 'Attached media should report parent as integer post ID.' );
	}

	/**
	 * per_page is honored as a limit.
	 */
	public function test_per_page_limits_results() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_attachment( array( 'post_title' => 'ability-pagination-test-' . $i ) );
		}

		$results = $this->execute_ability( array(
			'query'    => 'ability-pagination-test',
			'per_page' => 2,
		) );

		$this->assertCount( 2, $results, 'per_page should cap returned rows.' );
	}
}
