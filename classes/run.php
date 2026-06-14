<?php

class Meow_MGL_Run {
	private $isEnqueued = false;
	private $core;
	private $atts;

	public function __construct( $core ) {
		$this->core = $core;

		$override_disabled = get_option( 'mgl_options', [] )[ 'gallery_shortcode_override_disabled' ] ?? false;
		if ( !$override_disabled ) { add_shortcode( 'gallery', array( $core, 'gallery' ) ); }
		add_shortcode( 'meow-gallery', array( $core, 'gallery' ) );

		$google_photos = new Meow_MGL_Google_Photos( $core );
		add_shortcode( 'mgl-google-photos', array( $google_photos, 'shortcode' ) );
		add_action( 'init', array( $google_photos, 'register_block' ) );

		if ( is_admin() ) {
			add_action( 'init', array( $this, 'enqueue_scripts' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		} else {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ), 10 );
			
			add_action( 'mgl_gallery_created', array( $this, 'enqueue_scripts' ), 10, 0 );
			add_action( 'mgl_collection_created', array( $this, 'enqueue_scripts' ), 10, 0 );
		}

		// Yoast: Some people really want this, but it needs to be reviewed as Yoast changed its API
		//add_filter( 'wpseo_sitemap_urlimages', array( $this, 'wpseo_siteimap' ), 10, 2 );
	}

	function enqueue_styles() {
		// Cache buster for CSS
		$css_file = MGL_PATH . '/app/style.min.css';
		$css_cache_buster = file_exists( $css_file ) ? filemtime( $css_file ) : MGL_VERSION;
		wp_enqueue_style( 'mgl-css', plugins_url( '/app/style.min.css', __DIR__ ), null, $css_cache_buster );
	}

	function enqueue_scripts() {
		// Only need to load the scripts once.
		if ( $this->isEnqueued ) { 
			return;
		}
		$this->isEnqueued = true;

		// Load the JS for Meow Gallery
		$physical_file = MGL_PATH . '/app/galleries.js';
		$cache_buster = file_exists( $physical_file ) ? filemtime( $physical_file ) : MGL_VERSION;
		wp_enqueue_script( 'mgl-js', plugins_url( '/app/galleries.js', __DIR__ ), array(), $cache_buster, true );


		// TODO: This should be moved in a getter (since it is also used by tiles.php)
		$density = [];
		if ( isset( $this->atts['density'] ) ) {
			$density['desktop'] = $this->atts['density'];
			$density['tablet'] = $this->atts['density'];
			$density['mobile'] = $this->atts['density'];
		}
		else {
			$density['desktop'] = $this->core->get_option( 'tiles_density', 'high' );
			$density['tablet'] = $this->core->get_option( 'tiles_density_tablet', 'medium' );
			$density['mobile'] = $this->core->get_option( 'tiles_density_mobile', 'low' );
		}

		wp_localize_script('mgl-js', 'mgl_settings',
			array(
				'infinite_buffer' => $this->core->get_option( 'infinite_buffer', 0 ),
				'disable_right_click' => !$this->core->get_option( 'right_click', false ),
				'tiles' => array( 'density' => $density ),
				'api_url' => get_rest_url( null, '/meow-gallery/v1/' ),
				'rest_nonce' => wp_create_nonce( 'wp_rest' ),
				'options' => $this->core->get_all_options(),
			)
		);
	}

	/*
		For Yoast SEO
	*/

	function wpseo_siteimap( $images, $post_id ) {
		$galleries = get_post_galleries( $post_id );
		$images_ids = array();
		foreach ( $galleries as $gallery ) {
			preg_match_all( '/wp\-image\-([0-9]{1,16})/', $gallery, $matches );
			if ( !empty( $matches ) ) {
				foreach ( $matches[1] as $id )
					array_push( $images_ids, $id );
			}
		}
		$images_ids = array_unique( $images_ids );
		foreach ( $images_ids as $id ) {
			array_push( $images, array(
				'src' => wp_get_attachment_url( $id ),
				'title' => get_the_title( $id ),
				'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true )
			) );
		}
		return $images;
	}

}

?>
