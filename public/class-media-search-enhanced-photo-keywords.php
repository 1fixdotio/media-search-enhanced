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
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_saved' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_saved' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_deleted' ), 10, 3 );
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
