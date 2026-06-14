<?php

class Meow_MGL_Google_Photos {

	const CACHE_INTERVAL = 15; // minutes

	private $core;
	private $valid_domains = [
		'photos.app.goo.gl',
		'photos.google.com',
	];

	public function __construct( $core ) {
		$this->core = $core;
	}

	public function register_block() {
		if ( !function_exists( 'register_block_type' ) ) {
			return;
		}

		$handle      = 'mgl-google-photos-block';
		$script_path = MGL_PATH . '/app/google-photos-block.js';
		$cache_buster = file_exists( $script_path ) ? filemtime( $script_path ) : MGL_VERSION;

		wp_register_script(
			$handle,
			MGL_URL . 'app/google-photos-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			$cache_buster,
			true
		);

		register_block_type( 'meow-gallery/google-photos', array(
			'editor_script'   => $handle,
			'attributes'      => array(
				'albumUrl'      => array( 'type' => 'string', 'default' => '' ),
				'cacheInterval' => array( 'type' => 'integer', 'default' => self::CACHE_INTERVAL ),
				'layout'        => array( 'type' => 'string', 'default' => '' ),
			),
			'render_callback' => array( $this, 'render_block' ),
		) );
	}

	public function render_block( $attributes ) {
		$album_url = isset( $attributes['albumUrl'] ) ? trim( $attributes['albumUrl'] ) : '';
		if ( !$this->is_valid_album_url( $album_url ) ) {
			return '';
		}

		$cache_interval = isset( $attributes['cacheInterval'] ) ? intval( $attributes['cacheInterval'] ) : self::CACHE_INTERVAL;
		$layout = !empty( $attributes['layout'] ) ? sanitize_key( $attributes['layout'] ) : $this->core->get_option( 'layout', 'tiles' );

		$photos = $this->get_photos( $album_url, $cache_interval );
		if ( empty( $photos ) ) {
			return '';
		}

		return $this->render_gallery( $photos, $layout );
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts( [
			'album_url'      => '',
			'cache_interval' => self::CACHE_INTERVAL,
			'layout'         => '',
		], $atts, 'mgl-google-photos' );

		$album_url = trim( $atts['album_url'] );
		if ( !$this->is_valid_album_url( $album_url ) ) {
			return '<p><b>Meow Gallery:</b> Please provide a valid Google Photos album URL (https://photos.app.goo.gl/... or https://photos.google.com/...).</p>';
		}

		$cache_interval = intval( $atts['cache_interval'] );
		$layout = !empty( $atts['layout'] ) ? sanitize_key( $atts['layout'] ) : $this->core->get_option( 'layout', 'tiles' );

		$photos = $this->get_photos( $album_url, $cache_interval );
		if ( empty( $photos ) ) {
			return '<p><b>Meow Gallery:</b> No photos found in the album. Make sure the album is set to public.</p>';
		}

		return $this->render_gallery( $photos, $layout );
	}

	public function is_valid_album_url( $url ) {
		if ( !is_string( $url ) ) return false;
		$parsed = parse_url( strtolower( trim( $url ) ) );
		if ( !isset( $parsed['scheme'] ) || $parsed['scheme'] !== 'https' ) return false;
		if ( !isset( $parsed['host'] ) || !in_array( $parsed['host'], $this->valid_domains ) ) return false;
		return true;
	}

	public function get_photos( $album_url, $cache_interval ) {
		$option_name = 'mgl_gphoto_' . md5( $album_url );
		$cached      = get_option( $option_name );
		if ( $cached && !empty( $cached['photos'] ) && ( $cached['time'] + ( $cache_interval * 60 ) > time() ) ) {
			return $cached['photos'];
		}
		$photos = $this->fetch_from_google( $album_url );
		if ( $cache_interval && !empty( $photos ) ) {
			update_option( $option_name, [ 'time' => time(), 'photos' => $photos ] );
		}
		return $photos;
	}

	public function clear_cache( $album_url ) {
		delete_option( 'mgl_gphoto_' . md5( $album_url ) );
	}

	private function fetch_from_google( $album_url ) {
		$photos = [];
		$body   = $this->http_get( $album_url );
		if ( empty( $body ) ) {
			error_log( 'Meow Gallery (Google Photos): empty response when fetching ' . $album_url );
			return $photos;
		}
		preg_match_all( '@\["AF1Q.*?",\["(.*?)"\,@', $body, $urls );
		if ( isset( $urls[1] ) ) {
			$photos = array_values( array_unique( array_filter( $urls[1], function ( $url ) {
				return strpos( $url, 'https://' ) === 0;
			} ) ) );
		}
		return $photos;
	}

	/**
	 * Fetch a remote URL and return the (decompressed) body.
	 *
	 * Google Photos serves the album over a compressed response and via a
	 * redirect from the short photos.app.goo.gl link. On several PHP/cURL builds
	 * the WordPress HTTP API mishandles this (cURL error 52, or an undecoded
	 * gzip body that yields zero photos), so we use cURL directly with automatic
	 * decompression and redirect following. We fall back to the WordPress HTTP
	 * API when cURL is not available.
	 *
	 * A non-browser User-Agent is used on purpose: with a desktop-browser agent
	 * Google returns a JavaScript "deep link" interstitial that contains no photo
	 * URLs, whereas a plain agent gets redirected to the real album HTML.
	 */
	private function http_get( $url ) {
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt_array( $ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_ENCODING       => '', // Accept and auto-decompress any supported encoding.
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_USERAGENT      => 'Meow-Gallery/' . ( defined( 'MGL_VERSION' ) ? MGL_VERSION : '1.0' ) . ' (+WordPress)',
			] );
			$body  = curl_exec( $ch );
			$errno = curl_errno( $ch );
			$error = curl_error( $ch );
			curl_close( $ch );
			if ( $errno ) {
				error_log( 'Meow Gallery (Google Photos): cURL error ' . $errno . ': ' . $error );
				return '';
			}
			return is_string( $body ) ? $body : '';
		}

		$response = wp_safe_remote_get( $url, [ 'timeout' => 20, 'redirection' => 5 ] );
		if ( is_wp_error( $response ) ) {
			error_log( 'Meow Gallery (Google Photos): ' . $response->get_error_message() );
			return '';
		}
		return wp_remote_retrieve_body( $response );
	}

	private function get_dimensions_from_url( $url ) {
		if ( preg_match( '/=w(\d+)-h(\d+)/', $url, $m ) ) {
			return [ (int) $m[1], (int) $m[2] ];
		}
		return [ 800, 600 ];
	}

	private function get_fullres_url( $url ) {
		return preg_replace( '/=w\d+.*$/', '', $url ) . '=w2048';
	}

	private function render_gallery( $photos, $layout ) {
		$atts = [ 'layout' => $layout, 'link' => 'media' ];

		// Trigger the Meow Gallery pipeline so the gallery JS is enqueued and
		// localized. We must match core's 3-argument signature: other plugins
		// (e.g. Meow Lightbox) hook this action and require all three arguments.
		do_action( 'mgl_gallery_created', $atts, array_fill( 0, count( $photos ), '0' ), $layout );

		$uid      = uniqid();
		$class_id = 'mgl-gallery-' . $uid;

		$gallery_images = [];
		$mwl_entries    = [];

		foreach ( $photos as $url ) {
			[ $w, $h ] = $this->get_dimensions_from_url( $url );
			$fullres  = $this->get_fullres_url( $url );
			$fake_id  = 'mwl-gphoto-' . md5( $url );

			// This must match the structure Meow Lightbox produces in
			// get_exif_info(): the lightbox reads dimension.width/height (an
			// object, not a [w,h] array) and the flat data.* fields below.
			$mwl_entries[ $fake_id ] = [
				'success'       => true,
				'file'          => $fullres,
				'file_srcset'   => '',
				'file_sizes'    => '',
				'dimension'     => [ 'width' => $w, 'height' => $h ],
				'download_link' => $fullres,
				'data'          => [
					'id'            => $fake_id,
					'title'         => '',
					'caption'       => '',
					'description'   => '',
					'alt_text'      => 'Google Photo',
					'gps'           => 'N/A',
					'copyright'     => '',
					'author'        => '',
					'camera'        => 'N/A',
					'date'          => 'N/A',
					'lens'          => 'N/A',
					'aperture'      => 'N/A',
					'focal_length'  => 'N/A',
					'iso'           => 'N/A',
					'shutter_speed' => 'N/A',
					'keywords'      => [],
				],
			];

			$gallery_images[] = [
				'id'          => '0',
				'caption'     => '',
				'img_html'    => '<img loading="lazy" src="' . esc_attr( $url ) . '" data-mwl-img-id="' . esc_attr( $fake_id ) . '" alt="Google Photo" />',
				'link_href'   => $fullres,
				'link_target' => '_self',
				'link_rel'    => null,
				'attributes'  => [
					'data-mgl-id'     => '0',
					'data-mgl-width'  => (string) $w,
					'data-mgl-height' => (string) $h,
				],
				'orientation' => ( $w >= $h ? 'o' : 'i' ),
				'meta'        => [ 'width' => $w, 'height' => $h ],
			];
		}

		add_action( 'wp_footer', function () use ( $mwl_entries ) {
			echo '<script>window.mwl_data=Object.assign({},window.mwl_data||{},' . wp_json_encode( $mwl_entries ) . ');</script>';
		}, 101 );

		$fake_ids_str  = implode( ',', array_fill( 0, count( $photos ), '0' ) );
		$gallery_options = $this->core->get_gallery_options( $fake_ids_str, $atts, false, false, $layout );
		$gallery_options['class_id'] = $class_id;

		$atts_data = [
			'ids'    => $fake_ids_str,
			'link'   => 'media',
			'layout' => $layout,
		];

		$html  = sprintf(
			'<div class="mgl-root" data-gallery-options="%s" data-gallery-images="%s" data-atts="%s">',
			esc_attr( wp_json_encode( $gallery_options ) ),
			esc_attr( wp_json_encode( $gallery_images ) ),
			esc_attr( wp_json_encode( $atts_data ) )
		);
		$html .= '<div class="mgl-gallery-container"></div>';
		$html .= '<div class="mgl-gallery-images">';
		foreach ( $photos as $url ) {
			$fullres = $this->get_fullres_url( $url );
			$fake_id = 'mwl-gphoto-' . md5( $url );
			$html   .= '<a href="' . esc_url( $fullres ) . '" target="_self" rel="">';
			$html   .= '<img loading="lazy" src="' . esc_url( $url ) . '" data-mwl-img-id="' . esc_attr( $fake_id ) . '" alt="Google Photo" />';
			$html   .= '</a>';
		}
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}
}
