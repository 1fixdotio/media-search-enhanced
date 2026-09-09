<?php
/**
 * Tests for the [mse-search-form] shortcode markup.
 */
class ShortcodeTest extends WP_UnitTestCase {

	/**
	 * The shortcode renders a search form scoped to attachments.
	 */
	public function test_shortcode_renders_attachment_search_form() {
		$html = do_shortcode( '[mse-search-form]' );

		$this->assertStringContainsString( '<form', $html, 'The shortcode should render a form.' );
		$this->assertStringContainsString( 'name="s"', $html, 'The form should submit a search term.' );
		$this->assertStringContainsString(
			'<input type="hidden" name="post_type" value="attachment" />',
			$html,
			'The form should scope the search to attachments.'
		);
		$this->assertStringContainsString( 'mse-search-form', $html, 'The form should carry the plugin class.' );
	}
}
