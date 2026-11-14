<?php
/**
 * Media Search Enhanced: photo keyword index.
 *
 * @package   Media_Search_Enhanced
 * @author    1fixdotio <1fixdotio@gmail.com>
 * @license   GPL-2.0+
 * @link      http://1fix.io
 */

/**
 * Keeps a searchable plain-text copy of the keywords embedded in photos.
 *
 * WordPress reads the IPTC keywords of an uploaded photo into the `keywords`
 * array of `image_meta`, inside the serialized `_wp_attachment_metadata` value.
 * SQL cannot search that value without also matching file names, sizes and
 * camera data, so the keywords are copied into their own meta key whenever the
 * attachment metadata is written. The search then matches that key with an
 * EXISTS subquery, the same way it matches alt text.
 *
 * Attachments uploaded before this index existed are indexed by a WP-Cron
 * backfill.
 *
 * @package Media_Search_Enhanced
 */
class Media_Search_Enhanced_Photo_Keywords {

	/**
	 * Meta key holding the keywords, one per line.
	 *
	 * Search terms never contain line breaks (core strips them), so a term
	 * cannot match across two keywords.
	 *
	 * @var string
	 */
	const META_KEY = '_mse_photo_keywords';

	/**
	 * Meta key WordPress stores attachment metadata under.
	 *
	 * @var string
	 */
	const SOURCE_META_KEY = '_wp_attachment_metadata';

	/**
	 * WP-Cron hook that indexes attachments uploaded before the index existed.
	 *
	 * @var string
	 */
	const BACKFILL_HOOK = 'mse_backfill_photo_keywords';

	/**
	 * Option holding the backfill progress: the last attachment ID examined
	 * (with or without keywords), or BACKFILL_DONE once every attachment has
	 * been examined.
	 *
	 * @var string
	 */
	const BACKFILL_OPTION = 'mse_photo_keywords_backfill';

	/**
	 * Value of BACKFILL_OPTION once the backfill has finished.
	 *
	 * @var string
	 */
	const BACKFILL_DONE = 'done';

	/**
	 * Attachments read per backfill query.
	 *
	 * @var int
	 */
	const BACKFILL_BATCH_SIZE = 200;

	/**
	 * Seconds a single cron run keeps indexing before it reschedules itself.
	 *
	 * wp-cron.php only runs the events that were due when it started, and
	 * spawns at most once per WP_CRON_LOCK_TIMEOUT, so one batch per run would
	 * index a large library very slowly.
	 *
	 * @var int
	 */
	const BACKFILL_TIME_LIMIT = 10;

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_saved' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_saved' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_deleted' ), 10, 3 );

		add_action( 'init', array( __CLASS__, 'maybe_schedule_backfill' ) );
		add_action( self::BACKFILL_HOOK, array( __CLASS__, 'run_backfill' ) );
	}

	/**
	 * Start the backfill, or restart it if its cron event was lost.
	 *
	 * A one-off event whose callback is missing (e.g. the plugin was
	 * deactivated mid-backfill) is dropped when it fires, so the event is
	 * rescheduled whenever the backfill is unfinished and nothing is queued.
	 *
	 * @return void
	 */
	public static function maybe_schedule_backfill() {
		$state = get_option( self::BACKFILL_OPTION );

		if ( self::BACKFILL_DONE === $state ) {
			return;
		}

		if ( false === $state ) {
			add_option( self::BACKFILL_OPTION, 0, '', 'yes' );
		}

		if ( ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time(), self::BACKFILL_HOOK );
		}
	}

	/**
	 * Index existing attachments in batches until done or out of time.
	 *
	 * @return void
	 */
	public static function run_backfill() {
		$state = get_option( self::BACKFILL_OPTION );

		if ( self::BACKFILL_DONE === $state ) {
			return;
		}

		$last_id  = (int) $state;
		$deadline = microtime( true ) + self::BACKFILL_TIME_LIMIT;

		do {
			$last_id = self::backfill_batch( $last_id, self::BACKFILL_BATCH_SIZE );

			if ( null === $last_id ) {
				update_option( self::BACKFILL_OPTION, self::BACKFILL_DONE );
				return;
			}

			update_option( self::BACKFILL_OPTION, $last_id );
		} while ( microtime( true ) < $deadline );

		wp_schedule_single_event( time(), self::BACKFILL_HOOK );
	}

	/**
	 * Index the next batch of attachments.
	 *
	 * Walks attachment IDs on the posts table's primary key, then reads the
	 * metadata of just those attachments. Ordering postmeta rows by post_id
	 * instead makes MySQL sort every remaining metadata row on each batch.
	 * Metadata without keywords is skipped in SQL, so it is never unserialized.
	 *
	 * @param int $after_id Index attachments with a higher post ID than this.
	 * @param int $limit    Maximum number of attachments to look at.
	 * @return int|null The last attachment ID looked at, or null when no attachments are left.
	 */
	public static function backfill_batch( $after_id, $limit ) {
		global $wpdb;

		$ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
					$after_id,
					$limit
				)
			)
		);

		if ( empty( $ids ) ) {
			return null;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM $wpdb->postmeta"
				. ' WHERE post_id IN (' . implode( ',', $ids ) . ')'
				. ' AND meta_key = %s AND meta_value LIKE %s AND meta_value NOT LIKE %s',
				self::SOURCE_META_KEY,
				'%s:8:"keywords";a:%',
				'%s:8:"keywords";a:0:{%'
			)
		);

		foreach ( $rows as $row ) {
			self::index( (int) $row->post_id, $row->meta_value );

			// update_post_meta() caches the post (via wp_is_post_revision()) and,
			// when the value is unchanged, all of its meta. Drop both so a long
			// run does not hold every attachment it has indexed in memory.
			wp_cache_delete( (int) $row->post_id, 'posts' );
			wp_cache_delete( (int) $row->post_id, 'post_meta' );
		}

		if ( count( $ids ) < $limit ) {
			return null;
		}

		return end( $ids );
	}

	/**
	 * Re-index an attachment when its metadata is added or updated.
	 *
	 * Fires once per generated image size during an upload, so it only writes.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Unslashed meta value.
	 * @return void
	 */
	public static function on_meta_saved( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( self::SOURCE_META_KEY !== $meta_key ) {
			return;
		}

		self::index( $object_id, $meta_value );
	}

	/**
	 * Drop the keywords when the attachment metadata is deleted.
	 *
	 * @param string[] $meta_ids  Deleted meta IDs.
	 * @param int      $object_id Post ID. Empty when deleting the key from all posts.
	 * @param string   $meta_key  Meta key.
	 * @return void
	 */
	public static function on_meta_deleted( $meta_ids, $object_id, $meta_key ) {
		if ( self::SOURCE_META_KEY !== $meta_key || ! $object_id ) {
			return;
		}

		delete_post_meta( $object_id, self::META_KEY );
	}

	/**
	 * Write the keyword index for one attachment.
	 *
	 * @param int   $post_id  Attachment ID.
	 * @param mixed $metadata Attachment metadata, serialized or not.
	 * @return void
	 */
	public static function index( $post_id, $metadata ) {
		$keywords = self::extract_keywords( $metadata );

		if ( empty( $keywords ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		// update_post_meta() unslashes its input.
		update_post_meta( $post_id, self::META_KEY, wp_slash( implode( "\n", $keywords ) ) );
	}

	/**
	 * Get the embedded keywords from attachment metadata as plain text.
	 *
	 * wp_read_image_metadata() runs the metadata through wp_kses_post_deep(),
	 * which stores `B&W` as `B&amp;W`. The entities are decoded so the keyword
	 * matches what the user typed.
	 *
	 * @param mixed $metadata Attachment metadata, serialized or not.
	 * @return string[] Unique, non-empty keywords.
	 */
	public static function extract_keywords( $metadata ) {
		$metadata = maybe_unserialize( $metadata );

		if ( ! is_array( $metadata ) || empty( $metadata['image_meta']['keywords'] ) ) {
			return array();
		}

		$keywords = array();
		foreach ( (array) $metadata['image_meta']['keywords'] as $keyword ) {
			if ( ! is_scalar( $keyword ) ) {
				continue;
			}

			$keyword = html_entity_decode( (string) $keyword, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$keyword = trim( str_replace( array( "\r", "\n" ), ' ', $keyword ) );

			if ( '' !== $keyword ) {
				$keywords[] = $keyword;
			}
		}

		return array_values( array_unique( $keywords ) );
	}
}
