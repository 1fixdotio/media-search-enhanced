<?php
/**
 * Plugin Name: Media Search Enhanced — Playground Demo Notice
 * Description: Adds an admin notice on the Media Library explaining the Playground demo. Loaded only inside the Playground demo environment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_notices', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'upload' !== $screen->id ) {
		return;
	}

	$search = isset( $_GET['s'] ) ? wp_unslash( $_GET['s'] ) : '';
	?>
	<div class="notice notice-info is-dismissible" style="border-left-color:#2271b1;">
		<h2 style="margin:0.5em 0 0.25em;">Media Search Enhanced — try it out</h2>
		<p style="margin:0.25em 0;">
			The library has 8 seeded files, all related to <strong>mountains</strong>.
			With this plugin active, searching <code>mountain</code> returns
			<strong>6 hits</strong> — matches in <em>filename</em> and <em>alt text</em> included.
			WordPress core's default search would only find <strong>3</strong>
			(the ones with <code>mountain</code> in the title, caption, or description).
		</p>
		<?php if ( '' !== $search ) : ?>
			<p style="margin:0.25em 0;">
				You're viewing results for
				<code><?php echo esc_html( $search ); ?></code>.
				<a href="<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>">Clear search</a>
				to see all 8 files, then try searching an attachment's numeric ID
				(hover a row to reveal it) for the exact-ID match feature.
			</p>
		<?php else : ?>
			<p style="margin:0.25em 0;">
				Try searching <a href="<?php echo esc_url( admin_url( 'upload.php?mode=list&s=mountain' ) ); ?>"><code>mountain</code></a>,
				or search any attachment's numeric ID for an exact match.
			</p>
		<?php endif; ?>
	</div>
	<?php
} );
