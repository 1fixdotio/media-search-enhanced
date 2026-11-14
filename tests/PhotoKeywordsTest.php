<?php
/**
 * Tests for the photo keyword index (Media_Search_Enhanced_Photo_Keywords).
 */
class PhotoKeywordsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Media_Search_Enhanced_Photo_Keywords::BACKFILL_OPTION );
		wp_clear_scheduled_hook( Media_Search_Enhanced_Photo_Keywords::BACKFILL_HOOK );
	}

	public function tear_down() {
		wp_clear_scheduled_hook( Media_Search_Enhanced_Photo_Keywords::BACKFILL_HOOK );
		parent::tear_down();
	}

	/**
	 * Create an attachment without metadata.
	 *
	 * @return int Attachment ID.
	 */
	private function create_attachment() {
		return wp_insert_attachment( array(
			'post_title'     => 'IMG_0001',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		) );
	}

	/**
	 * Attachment metadata as WordPress stores it, with the given keywords.
	 *
	 * @param string[] $keywords Embedded keywords.
	 * @return array
	 */
	private function metadata( $keywords ) {
		return array(
			'width'      => 6000,
			'height'     => 4000,
			'file'       => '2024/01/IMG_0001.jpg',
			'sizes'      => array(
				'thumbnail' => array( 'file' => 'IMG_0001-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg' ),
			),
			'image_meta' => array(
				'camera'   => 'X-T5',
				'keywords' => $keywords,
			),
		);
	}

	/**
	 * Write `_wp_attachment_metadata` straight to the database, the way it
	 * exists for attachments uploaded before the index did.
	 *
	 * @param int   $post_id  Attachment ID.
	 * @param array $metadata Attachment metadata.
	 * @return void
	 */
	private function insert_metadata_without_hooks( $post_id, $metadata ) {
		global $wpdb;

		$wpdb->insert( $wpdb->postmeta, array(
			'post_id'    => $post_id,
			'meta_key'   => '_wp_attachment_metadata',
			'meta_value' => serialize( $metadata ),
		) );
		wp_cache_delete( $post_id, 'post_meta' );
	}

	private function indexed( $post_id ) {
		return get_post_meta( $post_id, Media_Search_Enhanced_Photo_Keywords::META_KEY, true );
	}

	public function test_keywords_are_indexed_one_per_line() {
		$id = $this->create_attachment();
		wp_update_attachment_metadata( $id, $this->metadata( array( 'amalfi coast', 'positano' ) ) );

		$this->assertSame( "amalfi coast\npositano", $this->indexed( $id ) );
	}

	public function test_only_keywords_are_indexed() {
		$id = $this->create_attachment();
		wp_update_attachment_metadata( $id, $this->metadata( array( 'harbour' ) ) );

		$this->assertSame( 'harbour', $this->indexed( $id ), 'Camera, file names and sizes must not be indexed.' );
	}

	/**
	 * wp_read_image_metadata() runs wp_kses_post_deep(), which stores `B&W` as `B&amp;W`.
	 */
	public function test_entities_are_decoded() {
		$id = $this->create_attachment();
		wp_update_attachment_metadata( $id, $this->metadata( wp_kses_post_deep( array( 'B&W', 'Fish & Chips' ) ) ) );

		$this->assertSame( "B&W\nFish & Chips", $this->indexed( $id ) );
	}

	public function test_keywords_are_cleaned_up() {
		$id = $this->create_attachment();
		// Called directly: wp_update_attachment_metadata() itself strips backslashes before the hook runs.
		Media_Search_Enhanced_Photo_Keywords::index( $id, $this->metadata( array( '  sunset ', "two\nlines", '', 'sunset', 'C:\\photos', array( 'nested' ) ) ) );

		$this->assertSame( "sunset\ntwo lines\nC:\\photos", $this->indexed( $id ), 'Keywords should be trimmed, de-duplicated, kept on one line each and keep backslashes.' );
	}

	public function test_index_follows_metadata_updates() {
		$id = $this->create_attachment();
		wp_update_attachment_metadata( $id, $this->metadata( array( 'positano' ) ) );
		wp_update_attachment_metadata( $id, $this->metadata( array( 'ravello' ) ) );

		$this->assertSame( 'ravello', $this->indexed( $id ) );

		wp_update_attachment_metadata( $id, $this->metadata( array() ) );

		$this->assertSame( '', $this->indexed( $id ), 'Removing the keywords should remove the index.' );
	}

	public function test_index_is_removed_with_the_metadata() {
		$id = $this->create_attachment();
		wp_update_attachment_metadata( $id, $this->metadata( array( 'positano' ) ) );
		delete_post_meta( $id, '_wp_attachment_metadata' );

		$this->assertSame( '', $this->indexed( $id ) );
	}

	public function test_backfill_indexes_existing_attachments() {
		$with_keywords  = $this->create_attachment();
		$no_keywords    = $this->create_attachment();
		$empty_keywords = $this->create_attachment();
		$also_keywords  = $this->create_attachment();

		$this->insert_metadata_without_hooks( $with_keywords, $this->metadata( array( 'positano' ) ) );
		$this->insert_metadata_without_hooks( $no_keywords, array( 'width' => 10, 'height' => 10, 'file' => 'keywords.jpg' ) );
		$this->insert_metadata_without_hooks( $empty_keywords, $this->metadata( array() ) );
		$this->insert_metadata_without_hooks( $also_keywords, $this->metadata( wp_kses_post_deep( array( 'B&W' ) ) ) );

		$this->assertSame( '', $this->indexed( $with_keywords ), 'Precondition: rows written without hooks are not indexed.' );

		Media_Search_Enhanced_Photo_Keywords::run_backfill();

		$this->assertSame( 'positano', $this->indexed( $with_keywords ) );
		$this->assertSame( 'B&W', $this->indexed( $also_keywords ) );
		$this->assertSame( '', $this->indexed( $no_keywords ) );
		$this->assertSame( '', $this->indexed( $empty_keywords ) );
		$this->assertSame( Media_Search_Enhanced_Photo_Keywords::BACKFILL_DONE, get_option( Media_Search_Enhanced_Photo_Keywords::BACKFILL_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Media_Search_Enhanced_Photo_Keywords::BACKFILL_HOOK ), 'A finished backfill should not reschedule itself.' );
	}

	public function test_backfill_batch_reports_progress() {
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = $this->create_attachment();
			$this->insert_metadata_without_hooks( end( $ids ), $this->metadata( array( "keyword-$i" ) ) );
		}

		$this->assertSame( $ids[1], Media_Search_Enhanced_Photo_Keywords::backfill_batch( 0, 2 ), 'A full batch returns the last indexed ID.' );
		$this->assertSame( '', $this->indexed( $ids[2] ), 'The batch should stop at its limit.' );
		$this->assertNull( Media_Search_Enhanced_Photo_Keywords::backfill_batch( $ids[1], 2 ), 'A partial batch means the backfill is done.' );
		$this->assertSame( 'keyword-2', $this->indexed( $ids[2] ) );
	}

	public function test_backfill_resumes_from_saved_position() {
		$before = $this->create_attachment();
		$after  = $this->create_attachment();
		$this->insert_metadata_without_hooks( $before, $this->metadata( array( 'skipped' ) ) );
		$this->insert_metadata_without_hooks( $after, $this->metadata( array( 'resumed' ) ) );

		update_option( Media_Search_Enhanced_Photo_Keywords::BACKFILL_OPTION, $before );
		Media_Search_Enhanced_Photo_Keywords::run_backfill();

		$this->assertSame( '', $this->indexed( $before ) );
		$this->assertSame( 'resumed', $this->indexed( $after ) );
	}

	public function test_backfill_is_scheduled_until_done() {
		Media_Search_Enhanced_Photo_Keywords::maybe_schedule_backfill();

		$this->assertSame( 0, (int) get_option( Media_Search_Enhanced_Photo_Keywords::BACKFILL_OPTION ), 'The backfill should start at the beginning.' );
		$this->assertNotFalse( wp_next_scheduled( Media_Search_Enhanced_Photo_Keywords::BACKFILL_HOOK ) );

		// An unfinished backfill whose event was lost (e.g. the plugin was deactivated) is restarted.
		wp_clear_scheduled_hook( Media_Search_Enhanced_Photo_Keywords::BACKFILL_HOOK );
		update_option( Media_Search_Enhanced_Photo_Keywords::BACKFILL_OPTION, 123 );
		Media_Search_Enhanced_Photo_Keywords::maybe_schedule_backfill();

		$this->assertNotFalse( wp_next_scheduled( Media_Search_Enhanced_Photo_Keywords::BACKFILL_HOOK ) );
		$this->assertSame( 123, (int) get_option( Media_Search_Enhanced_Photo_Keywords::BACKFILL_OPTION ), 'Restarting should keep the saved position.' );

		// A finished backfill is not scheduled again.
		wp_clear_scheduled_hook( Media_Search_Enhanced_Photo_Keywords::BACKFILL_HOOK );
		update_option( Media_Search_Enhanced_Photo_Keywords::BACKFILL_OPTION, Media_Search_Enhanced_Photo_Keywords::BACKFILL_DONE );
		Media_Search_Enhanced_Photo_Keywords::maybe_schedule_backfill();

		$this->assertFalse( wp_next_scheduled( Media_Search_Enhanced_Photo_Keywords::BACKFILL_HOOK ) );
	}
}
