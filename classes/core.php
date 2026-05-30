<?php

class Meow_MGL_Core {

	private $gallery_process = false;
	private $gallery_layout = 'tiles';
	private $is_gallery_used = true; // TODO: Would be nice to detect if the gallery is actually used on the current page.
	private $skeleton_handler;
	private $pro_module = false;

	private static $plugin_option_name = 'mgl_options';
	private $pro;
	private $option_name = 'mgl_options';
	private $infinite_layouts = [
		'tiles',
		'masonry',
		'justified',
		'square',
		'cascade',
		// 'carousel', Added dynamically if the option is enabled
	];

	private $rewrittenMwlData = [];

	public function __construct() {
		load_plugin_textdomain( MGL_DOMAIN, false, MGL_PATH . '/languages' );

		//TODO: Move Skeleton into PRO
		// Initialize skeleton handler
		require_once( MGL_PATH . '/classes/skeleton.php' );
		$this->skeleton_handler = new Meow_MGL_Skeleton();

		// Initializes the classes needed
		MeowKit_MGL_Helpers::is_rest() && new Meow_MGL_Rest( $this );

		// The gallery build process should only be enabled if the request is non-asynchronous
		if ( !MeowKit_MGL_Helpers::is_asynchronous_request()  ) {
			add_filter( 'wp_get_attachment_image_attributes', array( $this, 'wp_get_attachment_image_attributes' ), 25, 3 );
			if ( is_admin() || $this->is_gallery_used ) {
				new Meow_MGL_Run( $this );
			}
		}

		// Load the Pro version *after* loading the Run class due to the JS file was gatherd into one file.

		$this->pro_module = class_exists( 'MeowPro_MGL_Core' );
		if ( $this->pro_module ) {
			$this->pro = new MeowPro_MGL_Core( $this );
		} else {
			add_shortcode( 'meow-collection', array( $this, 'collection' ) );
		}

		// Initialize the Admin if needed
		add_action( 'init', array( $this, 'init' ) );
	}

	function init() {
		is_admin() && new Meow_MGL_Admin( $this );

		global $wpmgl;
		$wpmgl = $this;

		if ( $this->pro_module ) {
			global $wpmgl_pro;
			$wpmgl_pro = $this->pro;
		}
	}

	function collection() {
		return "<b>Meow Collection</b>: This is only available in the Pro version. Please <a href='https://meowapps.com/products/meow-gallery-pro/'>upgrade to Meow Gallery Pro</a> to use this feature.";
	}

	public function can_access_settings() {
		return apply_filters( 'mgl_allow_setup', current_user_can( 'manage_options' ) );
	}

	public function can_access_features() {
		return apply_filters( 'mgl_allow_usage', current_user_can( 'upload_files' ) );
	}

	// Use by the Gutenberg block

	// Rewrite the sizes attributes of the src-set for each image
	function wp_get_attachment_image_attributes( $attr, $attachment, $size ) {
		if (!$this->gallery_process)
			return $attr;

		$sizes = null;
		if ( $this->gallery_layout === 'tiles' )
			$sizes = '50vw';
		else if ( $this->gallery_layout === 'masonry' )
			$sizes = '50vw';
		else if ( $this->gallery_layout === 'square' )
			$sizes = '33vw';
		else if ( $this->gallery_layout === 'cascade' )
			$sizes = '80vw';
		else if ( $this->gallery_layout === 'justified' )
			$sizes = '(max-width: 800px) 80vw, 50vw';
		$sizes = apply_filters( 'mgl_sizes', $sizes, $this->gallery_layout, $attachment, $attr );
		if ( !empty( $sizes ) )
			$attr['sizes'] = $sizes;
		return $attr;
	}

	function get_rewritten_mwl_data() {
		return $this->rewrittenMwlData;
	}

	function gallery( $atts, $options = [] ) {
		$atts = apply_filters( 'shortcode_atts_gallery', $atts, null, $atts, 'gallery' );

		$isPreview = isset( $options['isPreview'] ) ? $options['isPreview'] : false;
		$isRest = isset( $options['isRest'] ) ? $options['isRest'] : false;

		// Sanitize the atts to avoid XSS
		$atts = array_map( function( $x ) { 
			if ( is_array( $x ) ) {
				// In case it contains an array, we need to sanitize each element, and avoid a string conversion issue
				return array_map( function( $y ) { return is_null( $y ) ? $y : esc_attr( $y ); }, $x );
			} else {
				// We don't sanitize null value, as it would convert it to a empty string
				return is_null( $x ) ? $x : esc_attr( $x ); 
			}
		}, $atts );

		if ( isset( $atts['meow'] ) && $atts['meow'] === 'false' ) {
			return gallery_shortcode( $atts );
		}

		// If the attributes contain "collection" then use the collection shortcode instead
		if ( isset( $atts['collection'] ) && !empty( $atts['collection'] ) ) {
			return do_shortcode( '[meow-collection id="' . $atts['collection'] . '"]' );
		}

		$image_ids = array();
		$layout = '';

		if ( isset( $atts['id'] ) && isset( $atts['ids'] ) ) {

			// Check if the ids are empty, then we can use the id
			if ( empty( $atts['ids'] ) ) {
				unset( $atts['ids'] );
			} else {
				error_log( "⚠️ Meow Gallery: in gallery $atts[id] both 'id' and 'ids' attributes are used in the same shortcode. 'id' will be ignored." );
			}
		}

		// Get the IDs
		#region media_ids
		if ( (isset( $atts['id'] ) && !empty($atts['id']) ) && !isset( $atts['ids'] ) ) {
			$shortcode_id = $atts['id'];

			try {
				$shortcode = $this->get_gallery_by_id( $shortcode_id );
			}
			catch ( Exception $e ) {
				return "<p class='meow-error'><b>Meow Gallery:</b> This ID wasn't found in the Gallery Manager. (ID: $shortcode_id). " . $e->getMessage() . "</p>";
			}

			if ( !isset( $shortcode['medias'] ) || !isset( $shortcode['medias']['thumbnail_ids'])) {
				return "<p class='meow-error'><b>Meow Gallery:</b> Thumbnail IDs not found.</p>";
			}

			$image_ids = $shortcode['medias']['thumbnail_ids'];
			unset( $shortcode['medias'] );

			//* We merge $atts into $shortcode ( not $shortcode into $atts ) so we can override the gallery settings even when using the ID.
			// Override specifics: "default" layout from atts should be the layout from shortcode
			if( array_key_exists('layout', $atts) && $atts['layout'] == 'default' )
			{ 
				$atts['layout'] = $shortcode['layout'];
			}

			$atts = array_merge( $shortcode, $atts );
		}

		if ( isset( $atts['ids'] ) ) {
			$image_ids = $atts['ids'];
		}
		if ( isset( $atts['include'] ) ) {
			$image_ids = is_array( $atts['include'] ) ? implode( ',', $atts['include'] ) : $atts['include'];
			$atts['include'] = $image_ids;
		}

		// Tags support
		if ( isset( $atts['tags'] ) && !empty( $atts['tags'] ) ) {
			$tags = is_array( $atts['tags'] ) ? $atts['tags'] : array_map( 'trim', explode( ',', $atts['tags'] ) );
			
			// Try multiple common taxonomies used for media tagging
			$taxonomies_to_try = [ 'post_tag', 'media_tag', 'attachment_tag', 'attachment_category' ];
			$taxonomies_to_try = apply_filters( 'mgl_tags_taxonomies', $taxonomies_to_try );
			
			$tagged_media_ids = [];
			
			foreach ( $taxonomies_to_try as $taxonomy ) {
				if ( !taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				
				$args = [
					'post_type' => 'attachment',
					'post_status' => 'inherit',
					'posts_per_page' => -1,
					'fields' => 'ids',
					'tax_query' => [
						[
							'taxonomy' => $taxonomy,
							'field' => 'slug',
							'terms' => $tags,
						]
					]
				];
				
				$query = new WP_Query( $args );
				if ( !empty( $query->posts ) ) {
					$tagged_media_ids = array_merge( $tagged_media_ids, $query->posts );
				}
			}
			
			$tagged_media_ids = array_unique( $tagged_media_ids );
			
			if ( !empty( $tagged_media_ids ) ) {
				if ( !empty( $image_ids ) ) {
					// Merge with existing IDs
					$existing_ids = is_array( $image_ids ) ? $image_ids : explode( ',', $image_ids );
					$image_ids = implode( ',', array_unique( array_merge( $existing_ids, $tagged_media_ids ) ) );
				} else {
					$image_ids = implode( ',', $tagged_media_ids );
				}
			}
		}

		if ( isset( $atts['latest_posts'] ) ) {
			$num_posts = intval( $atts['latest_posts'] );

			if ( $num_posts > 0 ) {
				
				$latest_posts = get_posts( [ 'numberposts' => $num_posts ] );
				$latest_posts_ids = array_map( function( $x ) { return $x->ID; }, $latest_posts );

				if ( isset( $atts['posts'] ) ) {
					error_log( "⚠️ Meow Gallery: in gallery $atts[id] both 'latest_posts' and 'posts' attributes are used in the same shortcode. 'latest_posts' will be merged with 'posts'.");
					$atts['posts'] = array_merge( $latest_posts_ids, explode(',', $atts['posts']) );
				}
				else {
					$atts['posts'] = implode( ',', $latest_posts_ids );
				}
			}

		}

		$posts_ids = [];
		if (isset($atts['posts'])) {
			
			$posts_ids = is_array( $atts['posts'] ) ? $atts['posts'] : explode( ',', $atts['posts'] );
			$featured_images = [];

			foreach ($posts_ids as $key => $post_id) {
				$image_id = get_post_thumbnail_id($post_id);
				if ($image_id === false || $image_id == 0) {
					unset($posts_ids[$key]);
				} else {
					$featured_images[] = $image_id;
				}
			}

			if ( count( $posts_ids ) !== count( $featured_images ) ) {
				return "<p class='meow-error'><b>Meow Gallery:</b> The number of featured images and posts id should be the same.</p>";
			}

			$image_ids = implode(',', $featured_images);
			$posts_ids = array_values($posts_ids);
		}


		// Filter the IDs
		$ids = is_array( $image_ids ) ? $image_ids : explode( ',', $image_ids );
		$ids = apply_filters( 'mgl_ids', $ids, $atts );
		$image_ids = implode( ',', $ids );

		#endregion

		// Use attached images if still empty
		if ( empty( $image_ids ) ) {
			$attachments = get_attached_media( 'image' );
			$attachmentIds = array_map( function($x) { return $x->ID; }, $attachments );
			if ( !empty( $attachmentIds ) ) {
				$image_ids = implode( ',', $attachmentIds );
			}
			else {
				return "<p class='meow-error'><b>Meow Gallery:</b> The gallery is empty.</p>";
			}
		}

		if ( $isPreview ) {
			$check = explode( ',', $image_ids );
			$check = array_slice( $check, 0, 40 );
			$image_ids = implode( ',', $check );
		}

		// Limit images on archive/listing pages (not viewing the full single post)
		$should_truncate = $this->get_option( 'truncate_on_listing', true );
		$is_archive_context = !is_singular() && !is_admin() && !$isPreview && !$isRest && $should_truncate;
		$is_archive_context = apply_filters( 'mgl_is_archive_context', $is_archive_context, $atts );
		if ( $is_archive_context ) {
			$archive_limit = intval( $this->get_option( 'truncate_count', 4 ) );

			if( $archive_limit === 0 ) {
				return ""; // If the user does not want the gallery to be shown at all on listing pages
			}

			if ( $archive_limit > 0 ) {
				$check = explode( ',', $image_ids );
				if ( count( $check ) > $archive_limit ) {
					$check = array_slice( $check, 0, $archive_limit );
					$image_ids = implode( ',', $check );
					$atts['is_truncated'] = true; // Flag to potentially show "view more" indicator
				}
			}
		}

		// Ordering
		if ( isset( $atts['orderby'] ) || isset( $atts['order_by'] ) ) {

			$orderby = '';
			$order   = 'asc';

			if ( isset( $atts['order'] ) ) {
				$order = $atts['order'];
			}

			if ( isset( $atts['orderby'] ) ) {
				$orderby = $atts['orderby'];
			}

			if ( isset( $atts['order_by'] ) ) {
				$orderby = $atts['order_by'];
			}

			if( strpos( $orderby, '-' ) != false ) {
				$left = explode( '-', $orderby )[0];
				$right   = explode( '-', $orderby )[1];

				$orderby = $left;
				$order   = $right;
			}


			$image_ids = explode( ',', $image_ids );
			$image_ids = Meow_MGL_OrderBy::run( $image_ids, $orderby, $order );
			$image_ids = implode( ',', $image_ids );
		}

		// Layout
		if ( isset( $atts['layout'] ) && $atts['layout'] != 'default' ) {
			$layout = $atts['layout'];
		}
		else if ( isset( $atts['mgl-layout'] ) && $atts['mgl-layout'] != 'default' ) {
			$layout = $atts['mgl-layout'];
			$atts['layout'] = $layout;
		} else {
			$layout = $this->get_option( 'layout', 'tiles' );
			$atts['layout'] = $layout;
		}

		
		if ( $layout === 'none' || $layout === '' ) {
			$layout = $this->get_option( 'layout', 'tiles' );
		}


		$layoutClass = 'Meow_MGL_Builders_' . ucfirst( $layout );
		if ( !class_exists( $layoutClass ) ) {
			error_log( "Meow Gallery: Class $layoutClass does not exist." );
			return "<p class='meow-error'><b>Meow Gallery:</b> The layout $layout is not available in this version.</p>";
		}

		// Captions
		if ( isset( $atts['captions'] ) && ( $atts['captions'] === false || $atts['captions'] === 'false' ) ) {
			// This is to avoid issues linked to the old block editor for the Meow Gallery
			$atts['captions'] = 'none';	
		}

		// apply filter 'mgl_sort_ahead' to sort the images before anything else
		if ( !empty( $image_ids ) ){
			$image_ids = implode( ',', apply_filters( 'mgl_sort_ahead', explode( ',', $image_ids ), $layout, $atts ) );
		}

		//DEBUG: Display $atts
		//error_log( print_r( $atts, 1 ) );

		// Start the process of building the gallery
		$this->gallery_process = true;
		$this->gallery_layout = $layout;

		// This should be probably removed.
		// wp_enqueue_style( 'mgl-css' );

		$infinite = $this->get_option( 'infinite', false ) && class_exists( 'MeowPro_MGL_Core' );

		// $gen = new $layoutClass( $atts, !$isPreview && $infinite, $isPreview );
		// $result = $gen->build( $image_ids );
		
		do_action( 'mgl_' . $layout . '_gallery_created', $layout );
		//$result = apply_filters( 'post_gallery', $result, $atts, null );
		
		$this->rewrittenMwlData = apply_filters('mgl_force_rewrite_mwl_data',  explode( ',', $image_ids ) );
		do_action( 'mgl_gallery_created', $atts, explode( ',', $image_ids ), $layout );

		$gallery_options = $this->get_gallery_options( $image_ids, $atts, $infinite, $isPreview, $layout );

		// If infinite scroll option was enabled, get the images up to 12 at first.
		$loading_image_ids = explode(',', $image_ids);
		$loading_image_ids = apply_filters( 'mgl_sort', $loading_image_ids, [], $layout, $atts );

		// Only add the carousel to the infinite layouts if the option is enabled
		if( $infinite && $this->get_option( 'carousel_infinite', false ) ) {
			$this->infinite_layouts[] = 'carousel';
		}

		if (!$isPreview && $infinite && in_array( $layout, $this->infinite_layouts ) ) {
			$loading_image_ids = array_slice( $loading_image_ids, 0, 12 );
		}

		$gallery_images = $this->get_gallery_images( $loading_image_ids, $atts, $layout, $gallery_options['size'], $posts_ids );

		// Get the class and data attributes
		$class = $this->get_mgl_root_class( $atts );
		$data_atts            = $this->get_data_as_json( $atts );
		$data_gallery_options = $this->get_data_as_json( $gallery_options );
		$data_gallery_images  = $this->get_data_as_json( $gallery_images );

		$html = sprintf(
			'<div class="%s" data-gallery-options="%s" data-gallery-images="%s" data-atts="%s">',
			esc_attr( $class ),
			$data_gallery_options,
			$data_gallery_images,
			$data_atts
		);

		// Run at /wp-includes/formatting.php on line 6037
		// WordPress returns early if the text is simple ASCII without emoji-like content,
		// so we only need to check preg_split if WordPress would actually run it.
		$needs_emoji_check = str_contains( $html, '&#x' ) || 
			!( ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $html, 'ASCII' ) ) || ! preg_match( '/[^\x00-\x7F]/', $html ) );
		
		if ( $needs_emoji_check ) {
			$textarr = preg_split( '/(<.*>)/U', $html , -1, PREG_SPLIT_DELIM_CAPTURE);
			if ( $textarr === false ) {
				$error = preg_last_error();
				error_log( "[MEOW GALLERY] Regex: " . preg_last_error_msg() . " (Code $error)" );
				return "<p class='meow-error'><b>Meow Gallery:</b> The gallery is too large for your Wordpress pcre.backtrack_limit, increase it in your server settings, or reduce your gallery size.";
			}
		}
		
		//The Gallery Container is where the images in the right layout will be rendered.
		$html .= '<div class="mgl-gallery-container"></div>';
		
		// Add skeleton loading placeholder to prevent layout shift
		$skeleton_loading = $this->get_option( 'skeleton_loading', true );
		if ( $skeleton_loading ) {
			$image_count = count( $gallery_images );
			$html .= $this->skeleton_handler->get_skeleton_html( $layout, $gallery_options, $image_count );
		}
		
		// Use the DOM to generate the images (so that lightboxes can hook into them, and for better SEO)
		// If there are no images, the JS will look for the img_html and build the gallery from there.
		// TODO: We should check why it's not working with the carousel (for map, it's normal).
		if ( $layout !== 'map' && $layout !== 'carousel' && $this->get_option( 'rendering_mode', 'dom' ) === 'dom' ) {
			$html .= '<div class="mgl-gallery-images">';

			foreach ( $gallery_images as $image ) {
				if ( !empty( $image['link_href'] ) ) {
					// If there is a link, we will get the alt from the image id so we have a proper aria-label
					$aria = get_post_meta( $image['id'], '_wp_attachment_image_alt', true );

					$custom_link_classes = apply_filters( 'mgl_custom_link_classes', '', $image );
					$html .= '<a class="' . $custom_link_classes . '" href="' . $image['link_href'] . '" target="' . $image['link_target'] . '" rel="' . $image['link_rel'] . 
					'" aria-label="' . $aria . '">';
					$html .= $image['img_html'];
					$html .= '</a>';
				}
				else {
					$html .= $image['img_html'];
				}
			}
			$html .= '</div>';
		}

		$html .= '</div>';

		$this->gallery_process = false;

		return $html;
	}

	public function get_gallery_options(string $image_ids, array $atts, bool $infinite, bool $is_preview, string $layout) {
		$image_ids = explode(',', $image_ids);
		$wp_upload_dir = wp_upload_dir();
		$options = $this->get_all_options();
		$id = uniqid();
		$size = isset( $atts['size'] ) ? $atts['size'] : 'large';
		$size =  apply_filters( 'mgl_media_size', $size );
		$custom_class = isset( $atts['custom-class'] ) ? $atts['custom-class'] : null;
		$link = isset( $atts['link'] ) ? $atts['link'] : ( $options['link'] ?? null );
		$updir = trailingslashit( $wp_upload_dir['baseurl'] );
		$captions = isset( $atts['captions'] ) ? $atts['captions'] : ( $options['captions'] ?? 'none' );
		$animation = null;
		if ( isset( $atts['animation'] ) && $atts['animation'] != 'default' ) {
			$animation = $atts['animation'];
		} else {
			$animation = $options['animation'] ?? null;
		}
		$class_id = 'mgl-gallery-' . $id;
		$layouts = [];

		// Justified
		$justified_row_height = $options['justified_row_height'];
		$justified_gutter = $options['justified_gutter'];
		if ( $layout === 'justified' ) {
			$justified_row_height = $atts['row-height'] ?? $options['justified_row_height'];
			$justified_gutter = $atts['gutter'] ?? $options['justified_gutter'];
		}
		// Masonry
		$masonry_gutter = $options['masonry_gutter'];
		$masonry_columns = $options['masonry_columns'];
		if ( $layout === 'masonry' ) {
			$masonry_gutter = $atts['gutter'] ?? $options['masonry_gutter'];
			$masonry_columns = $atts['columns'] ?? $options['masonry_columns'];
		}
		// Square
		$square_gutter = $options['square_gutter'];
		$square_columns = $options['square_columns'];
		if ( $layout === 'square' ) {
			$square_gutter = $atts['gutter'] ?? $options['square_gutter'];
			$square_columns = $atts['columns'] ?? $options['square_columns'];
		}
		// Cascade
		$cascade_gutter = $options['cascade_gutter'];
		if ( $layout === 'cascade' ) {
			$layouts = [ 'o', 'i', 'ii' ];
			$cascade_gutter = $atts['gutter'] ?? $options['cascade_gutter'];
		}
		// Tiles
		$tiles_gutter = $options['tiles_gutter'];
		$tiles_gutter_tablet = $options['tiles_gutter_tablet'];
		$tiles_gutter_mobile = $options['tiles_gutter_mobile'];
		$tiles_density = $options['tiles_density'];
		$tiles_density_tablet = $options['tiles_density_tablet'];
		$tiles_density_mobile = $options['tiles_density_mobile'];
		if ( $layout === 'tiles' ) {
			$tiles_gutter = $atts['gutter'] ?? $options['tiles_gutter'];
			$tiles_gutter_tablet = $atts['gutter'] ?? $options['tiles_gutter_tablet'];
			$tiles_gutter_mobile = $atts['gutter'] ?? $options['tiles_gutter_mobile'];
			$tiles_density = $atts['density'] ?? $options['tiles_density'];
			$tiles_density_tablet = $atts['density'] ?? $options['tiles_density_tablet'];
			$tiles_density_mobile = $atts['density'] ?? $options['tiles_density_mobile'];
		}
		// Horizontal
		$horizontal_gutter = $options['horizontal_gutter'];
		$horizontal_image_height = $options['horizontal_image_height'];
		$horizontal_hide_scrollbar = $options['horizontal_hide_scrollbar'];
		if ( $layout === 'horizontal' ) {
			$horizontal_gutter = $atts['gutter'] ?? $options['horizontal_gutter'];
			$horizontal_image_height = $atts['image_height'] ?? $options['horizontal_image_height'];
			$horizontal_hide_scrollbar = $atts['hide_scrollbar'] ?? $options['horizontal_hide_scrollbar'];
		}
		// Carousel
		$carousel_gutter = $options['carousel_gutter'];
		$carousel_arrow_nav_enabled = $options['carousel_arrow_nav_enabled'];
		$carousel_dot_nav_enabled = $options['carousel_dot_nav_enabled'];
		$carousel_image_height = $options['carousel_image_height'];
		$carousel_keep_aspect_ratio = $options['carousel_aspect_ratio'] ?? false;
		if ( $layout === 'carousel' ) {
			$carousel_gutter = $atts['gutter'] ?? $options['carousel_gutter'];
			$carousel_arrow_nav_enabled = $atts['arrow_nav_enabled'] ?? $options['carousel_arrow_nav_enabled'];
			$carousel_dot_nav_enabled = $atts['dot_nav_enabled'] ?? $options['carousel_dot_nav_enabled'];
			$carousel_image_height = $atts['image_height'] ?? $options['carousel_image_height'];
			$carousel_keep_aspect_ratio = array_key_exists( 'keep-aspect-ratio', $atts ) ? $atts['keep-aspect-ratio'] : 1;
		}
		// Map
		$map_gutter = $options['map_gutter'];
		$map_height = $options['map_height'];
		if ( $layout === 'map' ) {
			$map_gutter = $atts['gutter'] ?? $options['map_gutter'];
			$map_height = $atts['map_height'] ?? $options['map_height'];
		}

		return compact(
			'image_ids',
			'id',
			'size',
			'infinite',
			'custom_class',
			'link',
			'is_preview',
			'updir',
			'captions',
			'animation',
			'layout',
			'justified_row_height',
			'justified_gutter',
			'masonry_gutter',
			'masonry_columns',
			'square_gutter',
			'square_columns',
			'cascade_gutter',
			'class_id',
			'layouts',
			'tiles_gutter',
			'tiles_gutter_tablet',
			'tiles_gutter_mobile',
			'tiles_density',
			'tiles_density_tablet',
			'tiles_density_mobile',
			'horizontal_gutter',
			'horizontal_image_height',
			'horizontal_hide_scrollbar',
			'carousel_gutter',
			'carousel_arrow_nav_enabled',
			'carousel_dot_nav_enabled',
			'carousel_image_height',
			'carousel_keep_aspect_ratio',
			'map_gutter',
			'map_height',
		);
	}

	// #region Options

	static function get_plugin_option_name() {
		return self::$plugin_option_name;
	}

	static function get_plugin_option( $option_name, $default = null ) {
		$options = get_option( self::$plugin_option_name, null );
		if ( !empty( $options ) && array_key_exists( $option_name, $options ) ) {
			return $options[$option_name];
		}
		return $default;
	}

	function get_option( $option_name, $default = null ) {
		$options = $this->get_all_options();
		if ( array_key_exists( $option_name, $options ) ) {
			return $options[$option_name];
		}
		return $default;
	}

	function reset_options() {
		delete_option( 'mgl_db_version');
		delete_option( $this->option_name );
	}

	function list_options() {
		return array(
			'layout' => 'tiles',
			'ç' => 'none',
			'link' => null,
			'caption_source' => 'caption',
			'captions_alignment' => 'center',
			'captions_background' => 'fade-black',
			'animation' => false,
			'image_size' => 'srcset',
			'truncate_on_listing' => true,
			'truncate_count' => 4,
			
			'rendering_mode' => 'dom', // Can be 'dom' or 'js'
			'tiles_gutter' => 10,
			'tiles_gutter_tablet' => 10,
			'tiles_gutter_mobile' => 10,
			'tiles_density' => 'high',
			'tiles_density_tablet' => 'medium',
			'tiles_density_mobile' => 'low',

			'justified_density' => 'low',
			'justified_density_tablet' => 'medium',
			'justified_density_mobile' => 'low',

			'masonry_gutter' => 5,
			'masonry_columns' => 3,
			'masonry_left_to_right' => false,
			'justified_gutter' => 5,
			'justified_row_height' => 200,
			'square_gutter' => 5,
			'square_columns' => 5,
			'cascade_gutter' => 10,
			'horizontal_gutter' => 10,
			'horizontal_image_height' => 500,
			'horizontal_hide_scrollbar' => false,
			'carousel_gutter' => 5,
			'carousel_image_height' => 500,
			'carousel_arrow_nav_enabled' => true,
			'carousel_dot_nav_enabled' => true,
			'carousel_infinite' => false,
			'map_engine' => 'googlemaps',
			'map_height' => 500,
			'map_zoom' => 10,
			'map_gutter' => 10,
			'googlemaps_token' => '',
			'googlemaps_style' => '[]',
			'mapbox_token' => '',
			'mapbox_style' => '{"username":"", "style_id":""}',
			'maptiler_token' => '',
			
			// Stylish effect options
			'stylish_enabled' => false,
			'stylish_border_radius' => 6,
			'stylish_shadow_opacity' => 0.08,
			'stylish_shadow_opacity_hover' => 0.12,
			'stylish_transition_speed' => 250,

			//PRO OPTIONS
			'infinite' => false,
			'infinite_buffer' => 0,
			'right_click' => false,
			'gallery_shortcode_override_disabled' => false,
			'skeleton_loading' => false,
		);
	}

	function list_pro_options() {
		return array(
			'infinite' => false,
			'infinite_buffer' => 0,
			'right_click' => false,
			'gallery_shortcode_override_disabled' => false,
			'skeleton_loading' => false,
		);
	}

	function get_all_options() {
		$options = get_option( $this->option_name, null );
		$options = $this->check_options( $options );
		return $options;
	}

	// Upgrade from the old way of storing options to the new way.
	
	function check_options( $options = [] ) {
		$plugin_options = $this->list_options();
		$pro_options = $this->list_pro_options();

		$options = empty( $options ) ? [] : $options;
		$hasChanges = false;

		foreach ( $plugin_options as $option => $default ) {
			// The option already exists
			if ( isset( $options[$option] ) ) {
				continue;
			}
			// The option does not exist, so we need to add it.
			// Let's use the old value if any, or the default value.
			$options[$option] = get_option( 'mgl_' . $option, $default );
			delete_option( 'mgl_' . $option );
			$hasChanges = true;
		}

		if( !$this->pro_module ) {
			foreach ( $pro_options as $pro_option => $default ) {
				if ( $options[$pro_option] !== $default ) {
					$options[$pro_option] = $default;
					$hasChanges = true;
				}
			}
		}

		if ( $hasChanges ) {
			update_option( $this->option_name , $options );
		}
		return $options;
	}

	function update_options( $options ) {
		if ( !update_option( $this->option_name, $options, false ) ) {
			return false;
		}
		$options = $this->sanitize_options();
		return $options;
	}


	// Validate and keep the options clean and logical.
	function sanitize_options() {
		$options = $this->get_all_options();
		// something to do
		return $options;
	}

	# endregion

	function get_caption_from_source( $image ) {
		$caption_source = $this->get_option( 'caption_source', 'caption' );
		$caption = '';

		switch ( $caption_source ) {
			case 'title':
				$caption = $image->title;
				break;
			case 'caption':
				$caption = $image->caption;
				break;
			case 'description':
				$caption = $image->description;
				break;
			case 'alt':
				$caption = $image->alt;
				break;
			default:
				$caption = $image->caption;
				break;
		}

		return $caption;
	}

	function get_gallery_images( array $image_ids, array $atts, string $layout, string $size, array $posts_ids = []) {
		global $wpdb;

		// Escape the array of IDs for SQL
		$ids = array_map( 'intval', $image_ids );
		$ids_str = implode( ',', $ids );

		$query = "SELECT
					p.ID id,
					p.post_title title,
					p.post_content description,
					p.post_excerpt caption,
					pm.meta_value alt,
					pm2.meta_value meta

			FROM $wpdb->posts p
			LEFT JOIN $wpdb->postmeta pm  ON pm.post_id = p.ID  AND pm.meta_key  = '_wp_attachment_image_alt'
			LEFT JOIN $wpdb->postmeta pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_wp_attachment_metadata'

			WHERE p.post_type = 'attachment'

			AND p.ID IN (" . $ids_str . ")
		";

		$res = $wpdb->get_results( $query );

		$ids = explode( ',', $ids_str );
		$images = [];
		foreach ( $res as $r ) {
			$images[$r->id] = [
				'caption' => $this->get_caption_from_source( $r ),
				'meta' => unserialize( $r->meta ),
			];
		}
		$cleanIds = [];
		foreach ( $ids as $id ) {
			if ( isset( $images[$id] ) )
				array_push( $cleanIds, $id );
		}
		$ids = apply_filters( 'mgl_sort', $cleanIds, $images, $layout, $atts );

		if ($layout === 'map') {
			return $this->get_map_images( $ids, $images, $atts );
		}

		$result = [];
		foreach ($ids as $index => $id) {
			$image = $images[$id];

			// Determine orientation if layout is 'tiles'
			$orientation = [];
			if ($layout === 'tiles') {
				$orientation = [
					'orientation' => ($image['meta']['width'] > $image['meta']['height'] ? 'o' : 'i')
				];
			}
			
			$default_link = $this->get_option( 'link', null );
			$link_attr = $this->get_link_attributes( $id, $atts['link'] ?? $default_link, $image );
			$no_lightbox = $link_attr['type'] === 'link';

			$mergedArray = [
				'id' => $id,
				'caption' => wp_kses( 
					html_entity_decode(apply_filters( 'mgl_caption', $image['caption'], $id ), ENT_QUOTES), 
					[
						'strong' => [], // Bold
						'b' => [],      // Bold alternative
						'em' => [],     // Italic
						'i' => []       // Italic alternative
					]
				),
				'img_html' => apply_filters( 'mgl_gallery_written', 
					$this->get_img_html( $id, $size, $layout, $atts, $image, $no_lightbox ),
					$layout
				),
				'link_href' => $link_attr['href'] ?? null,
				'link_target' => $link_attr['target'] ?? null,
				'link_rel' => $link_attr['rel'] ?? null,
				'attributes' => $this->get_attributes( $id, $image, $layout ),
			];

			if( !empty( $posts_ids ) && isset( $atts['hero'] ) && $atts['hero'] ) {

				$post_id = $posts_ids[$index];
				$post = get_post( $post_id );

				$mergedArray['featured_post_id'] = $post_id;
				$mergedArray['featured_post_title'] = $post->post_title;
				$mergedArray['featured_post_excerpt'] = $post->post_excerpt;
				$mergedArray['featured_post_url'] = get_permalink( $post_id );

			}

			$result[] = array_merge( $image, $mergedArray, $orientation );
		}

		return $result;
	}

	private function get_image_class( $id, $layout, $noLightbox ) {
    $base_class = 'wp-image-' . $id;
	
	if( $layout === 'carousel' ) {
		$base_class .= ' skip-lazy';
	}

    if ( $noLightbox ) {
      $base_class .= ' no-lightbox';
    }
    return $base_class;
	}

	private function get_img_html( $id, $size, $layout, $atts, $data, $noLightbox ) {

		//check if the media is a video
		$media_type = get_post_mime_type( $id );
		if ( strpos( $media_type, 'video' ) !== false ) {
			$video = wp_get_attachment_url( $id );
			$video = apply_filters( 'mgl_video', $video, $id, $data );
			if ( !empty( $video ) ) {
				return '<video class="wp-video-'. $id .'" controls="controls" onclick="() => this.play();"><source src="' . $video . '" type="' . $media_type . '"></video>';
			}
		}

		$image_size = $this->get_option( 'image_size', 'srcset' );
		$img_html = null;
		if ( empty( $image_size ) || $image_size === 'srcset' ) {
			$img_html = wp_get_attachment_image( $id, $size, false, [
				'class' => $this->get_image_class( $id, $layout, $noLightbox ),
				'draggable' => $layout === 'carousel' ? 'false' : null,
			]);
		}
		else {
			$info = wp_get_attachment_image_src( $id, $image_size );
			$alt_text = get_post_meta( $id, '_wp_attachment_image_alt', true );
			
			$img_html = '<img loading="lazy" src="' . $info[0] . '" class="' . $this->get_image_class( $id, $layout, $noLightbox ) . '" alt="' . esc_attr( $alt_text ) . '" />';
		}

		if ( $layout === 'masonry' ) {
			$masonry_column = $this->get_option( 'masonry_column', 3 );
			$columns = ( isset( $atts['columns'] ) ? $atts['columns'] : $masonry_column ) + 1;
			$img_html = str_replace( '100vw', 100 / $columns . 'vw', $img_html );
		}
		else if ( $layout === 'square' ) {
			$square_column = $this->get_option( 'square_columns', 5 );
			$columns = ( isset( $atts['columns'] ) ? $atts['columns'] : $square_column ) + 1;
			$img_html = str_replace( '100vw', 100 / $columns . 'vw', $img_html );
		}
		else if ( $layout === 'cascade' ) {
			$img_html = str_replace( '100vw', 100 / 3 . 'vw', $img_html );
		}

		return wp_kses( $img_html, [ 
			'img' => [
				'src'      => true,
				'srcset'   => true,
				'loading'  => true,
				'tabindex' => true,
				'sizes'    => true,
				'class'    => true,
				'id'       => true,
				'width'    => true,
				'height'   => true,
				'alt'      => true,
				'align'    => true,
				'draggable' => true,
				]
			]
		);
	}

	private function get_link_attributes( $id, $link, $data ) {
		$link_url = null;
		$type = 'media';
		$rel = null;
		$target = '_self';

		if ( $link === 'attachment' ) {
			$link_url = get_permalink( (int)$id );
		}
		else if ( $link === 'media' || $link === 'file' ) {
			$wpUploadDir = wp_upload_dir();
			$updir = trailingslashit( $wpUploadDir['baseurl'] );
			if ( isset( $data['meta']['file'] ) ) {
				$link_url = $updir . $data['meta']['file'];
			} else {
				$link_url = get_permalink( (int)$id );
			}
		}
		else if ( $link === null ){
			$link_url = get_post_meta( $id, '_gallery_link_url', true );
			if ( !empty( $link_url ) ) {
				$type = 'link';
				$target = get_post_meta( $id, '_gallery_link_target', true );
			}
		}

		$link_attr = [
			'href' => !empty( $link_url ) ? esc_url( $link_url ) : null,
			'target' => $target,
			'type' => $type,
			'rel' => $rel,
		];


		return apply_filters( 'mgl_link_attributes', $link_attr, (int)$id, $data );
	}

	private function get_attributes( $id, $data, $layout ) {
		$attributes = '';
		if ( $layout === 'raw' ) {
			if ( isset( $data['meta'] ) && isset( $data['meta']['width'] ) && isset( $data['meta']['height'] ) ) {
				$attributes = 'data-mgl-id=' . $id . ' data-mgl-width=' . $data['meta']['width'] . ' data-mgl-height=' . $data['meta']['height'];
			}
		}
		elseif ( $layout === 'tiles' ) {
			if ( isset( $data['meta'] ) && isset( $data['meta']['width'] ) && isset( $data['meta']['height'] ) ) {
				$attributes = 'data-mgl-id=' . $id . ' data-mgl-width=' . $data['meta']['width'] . ' data-mgl-height=' . $data['meta']['height'];
			}
		}
		$attributes = apply_filters( 'mgl_attributes', $attributes, $id, $data );
		if ( $attributes === '' ) {
			return [];
		}

		$attribute_list = explode( ' ', $attributes );
		$attributes = [];
		foreach ( $attribute_list as $attribute ) {
			list( $key, $value ) = explode( '=', $attribute );
			$attributes[$key] = $value;
		}
		return $attributes;
	}

	private function get_map_images( $ids, $images, $atts = [] ) {
		$map_images = array_map( function ( $id ) use ( $images, $atts ) {

			$image = $images[$id];
			$default_link = $this->get_option( 'link', null );
			$link_attr = $this->get_link_attributes( $id, $atts['link'] ?? $default_link, $image );

			$geo_coordinates = MeowPro_MGL_Exif::get_gps_data( $id, $image['meta'] );
			if ( empty( $geo_coordinates ) ) {
				return null;
			}
			$callback = function ( &$value, $key ) use ( $id ) {
				$imgsrc = wp_get_attachment_image_src( $id, $key );
				$value = $imgsrc[0];
			};
			array_walk( $image['meta']['sizes'], $callback );
			return array_merge(
				$image,
				[
					'id' => $id,
					'file' => $image['meta']['file'],
					'file_full' => wp_get_attachment_url( $id ),
					'file_srcset' => wp_get_attachment_image_srcset( $id, 'full' ),
					'file_sizes' => wp_get_attachment_image_sizes( $id, 'full' ),
					'dimension' => [
						'width' => $image['meta']['width'],
						'height' => $image['meta']['height'],
					],
					'sizes' => $image['meta']['sizes'],
					'data' => [
						'caption' => $image['meta']['image_meta']['caption'],
						'gps' => $geo_coordinates,
					],
					'link' => $link_attr,
				]
			);
		}, $ids );
		return array_values( array_filter( $map_images ) );
	}

	public function get_mgl_root_class( $atts, $classes = ['mgl-root'] ) {
		$classes[] = isset( $atts['align'] ) ?  'align' . $atts['align'] : '';
		return trim( implode( ' ', $classes ) );
	}

	public function get_data_as_json( $data ) {
		return esc_attr( htmlspecialchars( wp_json_encode( $data ), ENT_QUOTES, 'UTF-8' ) );
	}

	public function generate_uniqid($length = 13) {
		// Use WordPress function
		if ( function_exists( 'wp_unique_id' ) ) {
			$prefix = uniqid();
			return wp_unique_id( $prefix );
		} 
		// Fall back 
		else {
			if ( function_exists( "random_bytes" ) ) {
				$bytes = random_bytes( ceil( $length / 2 ) );
			}
			elseif ( function_exists( "openssl_random_pseudo_bytes" ) ) {
				$bytes = openssl_random_pseudo_bytes(ceil($length / 2));
			}
			else {
				throw new Exception( "No cryptographically secure random function available." );
			}
			return substr( bin2hex( $bytes ), 0, $length );
		}
	}


	public function get_gallery_by_id( $id ) {
		global $wpdb;
		$shortcodes_table = $wpdb->prefix . 'mgl_gallery_shortcodes';
		$gallery = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $shortcodes_table WHERE id = %s", $id ), ARRAY_A );

		if ( !$gallery ) {
			throw new Exception( __( 'Gallery not found.', MGL_DOMAIN ));
		}
		$gallery['medias'] = maybe_unserialize( $gallery['medias'] );
		$gallery['posts'] = $gallery['posts'] ? maybe_unserialize( $gallery['posts'] ) : null;
		$gallery['tags'] = $gallery['tags'] ? unserialize( $gallery['tags'] ) : null;

		return $gallery;
	}

	public function get_galleries_by_ids( $ids ){
		global $wpdb;
		$shortcodes_table = $wpdb->prefix . 'mgl_gallery_shortcodes';
		$galleries = [];
		$ids_str = "'" . implode( "','", array_map( 'esc_sql', $ids )) . "'";
		$query = "SELECT * FROM $shortcodes_table WHERE id IN ( $ids_str )";
		$results = $wpdb->get_results( $query, ARRAY_A );
		foreach ( $results as $gallery ) {
			$galleries[$gallery['id']] = [
				'name' => $gallery['name'],
				'description' => $gallery['description'],
				'layout' => $gallery['layout'],
				'medias' => maybe_unserialize( $gallery['medias'] ),
				'lead_image_id' => $gallery['lead_image_id'],
				'order_by' => $gallery['order_by'],
				'is_post_mode' => ( bool )$gallery['is_post_mode'],
				'dynamic_source' => $gallery['dynamic_source'],
				'hero' => ( bool )$gallery['is_hero_mode'],
				'posts' => $gallery['posts'] ? maybe_unserialize( $gallery['posts'] ) : null,
				'latest_posts' => $gallery['latest_posts'],
				'tags' => $gallery['tags'] ? unserialize( $gallery['tags'] ) : null,
				'updated' => strtotime( $gallery['updated_at'] )
			];
		}
		return $galleries;
	}

	public function get_galleries( $offset = 0, $limit = 10, $order = 'DESC', $sort = null, $page = 1, $search = '' ) {
		global $wpdb;
		$shortcodes_table = $wpdb->prefix . 'mgl_gallery_shortcodes';
		Meow_MGL_Migrations::check_db();

		// Get total count
		$total = 0;
		if ( empty( $search ) ) {
			$total = $wpdb->get_var( "SELECT COUNT( * ) FROM $shortcodes_table" );
		} else {
			$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( * ) FROM $shortcodes_table WHERE name LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' ) );
		}

		// Calculate offset based on page if provided
		if ($page > 1 && $offset === 0) {
			$offset = ($page - 1) * $limit;
		}
		
		// Get shortcodes with pagination and sorting
	    $sort_accessor_to_column = [
			'info' => 'name',
			'updated' => 'updated_at',
			'rank' => 'pref_rank',
		];
		
		$sort = $sort_accessor_to_column[$sort] ?? 'pref_rank';
		$order = in_array( strtoupper( $order ), ['ASC', 'DESC'] ) ? strtoupper( $order ) : 'DESC';

		// Sort by rank DESC first, then by updated_at for equal ranks
		$query = $wpdb->prepare(
			"SELECT * FROM $shortcodes_table WHERE name LIKE %s ORDER BY $sort $order, updated_at DESC LIMIT %d, %d",
			'%' . $wpdb->esc_like( $search ) . '%', $offset, $limit
		);
		
		$results = $wpdb->get_results( $query, ARRAY_A );
		$shortcodes = [];
		
		foreach ( $results as $gallery ) {
			// Transform database format to match expected format
			$shortcodes[$gallery['id']] = [
				'name' => $gallery['name'],
				'description' => $gallery['description'],
				'layout' => $gallery['layout'],
				'medias' => maybe_unserialize( $gallery['medias'] ),
				'lead_image_id' => $gallery['lead_image_id'],
				'order_by' => $gallery['order_by'],
				'is_post_mode' => ( bool )$gallery['is_post_mode'],
				'hero' => ( bool )$gallery['is_hero_mode'],
				'posts' => $gallery['posts'] ? maybe_unserialize( $gallery['posts'] ) : null,
				'latest_posts' => $gallery['latest_posts'],
				'tags' => $gallery['tags'] ? unserialize( $gallery['tags'] ) : null,
				'dynamic_source' => $gallery['dynamic_source'],
				'rank' => intval( $gallery['pref_rank'] ?? 0 ),
				'updated' => strtotime( $gallery['updated_at'] )
			];
		}

		return [
			'total' => $total,
			'galleries' => $shortcodes
		];
	}

	public function get_collection_by_id( $id ) {
		global $wpdb;
		$collections_table = $wpdb->prefix . 'mgl_collections';
		$shortcodes_table = $wpdb->prefix . 'mgl_gallery_shortcodes';
		
		// Get the collection
		$collection = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $collections_table WHERE id = %d", $id ), ARRAY_A );
		if ( !$collection ) {
			throw new Exception( __( 'Collection not found.', MGL_DOMAIN ));
		}
		$collection['galleries_ids'] = unserialize( $collection['galleries_ids'] );
		
		// Get the associated galleries
		$galleries = [];
		if ( !empty( $collection['galleries_ids'] )) {
			$galleries_ids_str = "'" . implode( "','", array_map( 'esc_sql', $collection['galleries_ids'] )) . "'";
			$galleries_query = "SELECT * FROM $shortcodes_table WHERE id IN ( $galleries_ids_str )";
			$galleries_data = $wpdb->get_results( $galleries_query, ARRAY_A );
			
			foreach ( $galleries_data as $gallery ) {
				// Transform database format to match expected format
				$galleries[] = [
					'id' => $gallery['id'],
					'name' => $gallery['name'],
					'description' => $gallery['description'],
					'layout' => $gallery['layout'],
					'medias' => unserialize( $gallery['medias'] ),
					'lead_image_id' => $gallery['lead_image_id'],
					'order_by' => $gallery['order_by'],
					'is_post_mode' => ( bool )$gallery['is_post_mode'],
					'hero' => ( bool )$gallery['is_hero_mode'],
					'posts' => $gallery['posts'] ? unserialize( $gallery['posts'] ) : null,
					'latest_posts' => $gallery['latest_posts'],
					'updated' => strtotime( $gallery['updated_at'] )
				];
			}

			// Format collection data
			$collection['galleries'] = $galleries;
		}

		return $collection;
	}

	public function get_collections( $offset = 0, $limit = 10, $order = 'DESC', $page = 1, $search = '' ) {
		global $wpdb;
		$collections_table = $wpdb->prefix . 'mgl_collections';
		$shortcodes_table = $wpdb->prefix . 'mgl_gallery_shortcodes';
		Meow_MGL_Migrations::check_db();

		// Get total count
		if( empty( $search ) ) {
			$total = $wpdb->get_var( "SELECT COUNT( * ) FROM $collections_table" );
		} else {
			$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( * ) FROM $collections_table WHERE name LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' ) );
		}
		// Calculate offset based on page if provided
		if ($page > 1 && $offset === 0) {
			$offset = ($page - 1) * $limit;
		}
		
		// Get collections with pagination and sorting
		$query = $wpdb->prepare(
			"SELECT * FROM $collections_table WHERE name LIKE %s ORDER BY updated_at $order LIMIT %d, %d",
			'%' . $wpdb->esc_like( $search ) . '%', $offset, $limit
		);
		
		$collections = $wpdb->get_results( $query, ARRAY_A );
		$result = [];
		
		foreach ( $collections as $collection ) {
			$collection_id = $collection['id'];
			$galleries_ids = unserialize( $collection['galleries_ids'] );
			
			// Get the associated galleries
			$galleries = [];
			if ( !empty( $galleries_ids )) {
				$galleries_ids_str = "'" . implode( "','", array_map( 'esc_sql', $galleries_ids )) . "'";
				$galleries_query = "SELECT * FROM $shortcodes_table WHERE id IN ( $galleries_ids_str )";
				$galleries_data = $wpdb->get_results( $galleries_query, ARRAY_A );
				
				foreach ( $galleries_data as $gallery ) {
					// Transform database format to match expected format
					$gallery_item = [
						'id' => $gallery['id'],
						'name' => $gallery['name'],
						'description' => $gallery['description'],
						'layout' => $gallery['layout'],
						'medias' => unserialize( $gallery['medias'] ),
						'lead_image_id' => $gallery['lead_image_id'],
						'order_by' => $gallery['order_by'],
						'is_post_mode' => ( bool )$gallery['is_post_mode'],
						'hero' => ( bool )$gallery['is_hero_mode'],
						'posts' => $gallery['posts'] ? unserialize( $gallery['posts'] ) : null,
						'latest_posts' => $gallery['latest_posts'],
						'tags' => $gallery['tags'] ? unserialize( $gallery['tags'] ) : null,
						'dynamic_source' => $gallery['dynamic_source'],
						'updated' => strtotime( $gallery['updated_at'] )
					];
					$galleries[] = $gallery_item;
				}
			}
			
			// Format collection data
			$result[$collection_id] = [
				'name' => $collection['name'],
				'description' => $collection['description'],
				'layout' => $collection['layout'],
				'galleries_ids' => $galleries_ids,
				'galleries' => $galleries,
				'updated' => strtotime( $collection['updated_at'] )
			];
		}

		return [
			'total' => $total,
			'collections' => $result
		];
	}

}

?>
