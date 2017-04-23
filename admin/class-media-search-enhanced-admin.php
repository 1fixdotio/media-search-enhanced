<?php
/**
 * Media Search Enhanced.
 *
 * @package   Media_Search_Enhanced_Admin
 * @author    1fixdotio <1fixdotio@gmail.com>
 * @license   GPL-2.0+
 * @link      http://1fix.io
 * @copyright 2014 1Fix.io
 */

/**
 * Plugin class. This class should ideally be used to work with the
 * administrative side of the WordPress site.
 *
 * If you're interested in introducing public-facing
 * functionality, then refer to `class-media-search-enhanced.php`
 *
 * @package Media_Search_Enhanced_Admin
 * @author  1fixdotio <1fixdotio@gmail.com>
 */
class Media_Search_Enhanced_Admin {

	/**
	 * Instance of this class.
	 *
	 * @since    0.0.1
	 *
	 * @var      object
	 */
	protected static $instance = null;

	/**
	 * Slug of the plugin screen.
	 *
	 * @since    0.0.1
	 *
	 * @var      string
	 */
	protected $plugin_screen_hook_suffix = null;

	/**
	 * Initialize the plugin by loading admin scripts & styles and adding a
	 * settings page and menu.
	 *
	 * @since     0.0.1
	 */
	private function __construct() {
            
            add_action( 'admin_menu', array($this, 'mse_add_admin_menu' ));
            
            //AJAX Call
            add_action( 'admin_footer', array($this, 'ajax_script' ));
            add_action( 'wp_ajax_approal_action', array($this, 'ajax_handler' ));
	}

	/**
	 * Return an instance of this class.
	 *
	 * @since     0.0.1
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}
        
        public function mse_add_admin_menu(  ) { 

                add_options_page( 'Media Search Enhanced', 'Media Search Enhanced', 'manage_options', 'media_search_enhanced', array($this, 'mse_options_page' ));

        } 

        public function mse_options_page(  ) {                                      
            ?>
            
            <div class="wrap">
                                
                <div style="display: none" id="notice-success" class="notice notice-success">
                    <p>Settings saved.</p>
                </div>
                
                <div style="display: none" id="notice-error" class="notice notice-error">
                    <p>An error ocoured while saving your settings. Please, try it again.</p>
                </div>                
                
                <h1>Media Search Settings</h1>
                <br>
                <?php 
                            settings_fields( 'mse_settings' );
                            do_settings_sections( 'mse_settings' );
                ?>
                
                <h3>Search results</h3>
                <h4>Image size to be loaded (eg. large): 
                <input id="image_size" type="text" name="image_size" value="<?php echo get_option('image_size', 'thumbnail'); ?>">
                </h4>                
                
                <h4>Where do you want to display the excerpt?
                    <select id="excerpt_display">
                        <option value="above" <?php echo get_option( 'excerpt_display', '') == "above" ? "selected":""; ?>>above the image</option>
                        <option value="underneath" <?php echo get_option( 'excerpt_display', '') == "underneath" ? "selected":""; ?>>underneath</option>
                        <option value="none" <?php echo get_option( 'excerpt_display', '') == "none" ? "selected":""; ?>>do not display</option>
                    </select>
                </h4>
                
                <h4>Add an unique css id to the excerpt. (eg. mse-image-exerpt)
                    <input id="excerpt_id" type="text" value="<?php echo get_option( 'excerpt_id', 'mse-image-excerpt'); ?>">
                </h4>
                
                <h3>Shortcode</h3>
                <h4>Shortcode for the frontend: [mse-search-form]</h4>
                
                <br>
                <button id="save_settings">Save Settings</button>
                
                <div id="result">
                    
                </div>
            </div>
            <?php
        }        
        
        public function ajax_script() {
            ?>
            <script type="text/javascript" >
            jQuery(document).ready(function($) {                                
                $( '#save_settings' ).click( function() {                 

                    //get the values from the form
                    var image_size = $( '#image_size' ).val();
                    var excerpt_display = $( '#excerpt_display' ).find( ':selected' ).val();
                    var excerpt_id = $( '#excerpt_id' ).val();
                    
                    //send the ajax request to the ajax handler
                    $.ajax({
                        method: "POST",
                        url: ajaxurl,
                        data: { 'action': 'approal_action', 'image_size': image_size, 'excerpt_display': excerpt_display, 'excerpt_id': excerpt_id }
                    })                

                    .done(function( data ) {
                        $( '#notice-success' ).show();
                        setTimeout (function(){
                            $( '#notice-success' ).hide();
                        }, 4000);
                    })
                    .fail(function( data ) {
                        $( '#notice-error' ).show();
                
                        setTimeout( function() {
                            $( '#notice-error' ).hide();
                        }, 4000);
                    });
                });
            });
            </script>
            <?php
        }
                        
        public function ajax_handler() {
            $jsArray = array();
            $jsArray[ 'image_size' ] =  $_POST[ 'image_size' ];
            $jsArray[ 'excerpt_display' ] = $_POST[ 'excerpt_display' ];
            $jsArray[ 'excerpt_id' ] = $_POST[ 'excerpt_id' ];
            
            update_option( 'image_size', $jsArray[ 'image_size' ]);
            update_option( 'excerpt_display', $jsArray[ 'excerpt_display' ]);
            update_option( 'excerpt_id', $jsArray[ 'excerpt_id' ]);
            
            echo json_encode($jsArray);
            wp_die(); // just to be safe
        } 
}
