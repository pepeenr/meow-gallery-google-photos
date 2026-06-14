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
		$photos   = [];
		$response = wp_safe_remote_get( $album_url, [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) ) {
			error_log( 'Meow Gallery (Google Photos): ' . $response->get_error_message() );
			return $photos;
		}
		$body = wp_remote_retrieve_body( $response );
		preg_match_all( '@\["AF1Q.*?",\["(.*?)"\,@', $body, $urls );
		if ( isset( $urls[1] ) ) {
			$photos = array_values( array_unique( array_filter( $urls[1], function ( $url ) {
				return strpos( $url, 'https://' ) === 0;
			} ) ) );
		}
		return $photos;
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
		// Trigger the Meow Gallery JS/CSS enqueue.
		do_action( 'mgl_gallery_created' );

		$uid      = uniqid();
		$class_id = 'mgl-gallery-' . $uid;

		$gallery_images = [];
		$mwl_entries    = [];

		foreach ( $photos as $url ) {
			[ $w, $h ] = $this->get_dimensions_from_url( $url );
			$fullres  = $this->get_fullres_url( $url );
			$fake_id  = 'mwl-gphoto-' . md5( $url );

			$mwl_entries[ $fake_id ] = [
				'success'   => true,
				'file'      => $fullres,
				'dimension' => [ $w, $h ],
				'data'      => [
					'id'          => $fake_id,
					'title'       => '',
					'description' => '',
					'caption'     => '',
					'alt'         => 'Google Photo',
					'mime_type'   => 'image/jpeg',
					'exif'        => [
						'camera'      => 'N/A',
						'aperture'    => 'N/A',
						'shutter'     => 'N/A',
						'focal'       => 'N/A',
						'iso'         => 'N/A',
						'date'        => 'N/A',
						'keywords'    => [],
						'orientation' => 1,
					],
					'url'    => $fullres,
					'width'  => $w,
					'height' => $h,
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
		$gallery_options = $this->core->get_gallery_options( $fake_ids_str, [ 'layout' => $layout, 'link' => 'media' ], false, false, $layout );
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
			$html   .= '<a href="' . esc_url( $fullres ) . '" target="_self" rel="">';
			$html   .= '<img loading="lazy" src="' . esc_url( $url ) . '" alt="Google Photo" />';
			$html   .= '</a>';
		}
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}
}
