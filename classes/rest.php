<?php

class Meow_MGL_Rest
{
	private $core;
	private $namespace = 'meow-gallery/v1';

	public function __construct( $core ) {
    $this->core = $core;

		// FOR DEBUG
		// For experiencing the UI behavior on a slower install.
		// sleep( 1 );
		// For experiencing the UI behavior on a buggy install.
		// trigger_error( "Error", E_USER_ERROR );
		// trigger_error( "Warning", E_USER_WARNING );
		// trigger_error( "Notice", E_USER_NOTICE );
		// trigger_error( "Deprecated", E_USER_DEPRECATED );

		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
	}


	function rest_api_init( ) {

		// Settings
		register_rest_route( $this->namespace, '/update_option/', array(
			'methods' => 'POST',
			'permission_callback' => array( $this->core, 'can_access_settings' ),
			'callback' => array( $this, 'rest_update_option' )
		) );
		register_rest_route( $this->namespace, '/all_settings/', array(
			'methods' => 'GET',
			'permission_callback' => array( $this->core, 'can_access_settings' ),
			'callback' => array( $this, 'rest_all_settings' )
		) );
		register_rest_route( $this->namespace, '/reset_options', array(
			'methods' => 'POST',
			'permission_callback' => array( $this->core, 'can_access_settings' ),
			'callback' => array( $this, 'rest_reset_options' )
		) );


		// Gallery Manager
		register_rest_route( $this->namespace, '/latest_photos', array(
			'methods' => 'GET',
			'permission_callback' => array( $this->core, 'can_access_settings' ),
			'callback' => array( $this, 'rest_latest_photos' ),
			'args' => array(
				'search' => array( 'required' => false ),
				'offset' => array( 'required' => false, 'default' => 0 ),
				'except' => array( 'required' => false ),
			)
		) );
		register_rest_route( $this->namespace, '/save_shortcode', array(
			'methods' => 'POST',
			'permission_callback' => array( $this->core, 'can_access_settings' ),
			'callback' => array( $this, 'rest_save_shortcode' ),
		) );
		register_rest_route( $this->namespace, '/remove_shortcode', array(
			'methods' => 'POST',
			'permission_callback' => array( $this->core, 'can_access_settings' ),
			'callback' => array( $this, 'rest_remove_shortcode' ),
		) );
		register_rest_route( $this->namespace, '/update_gallery_rank', array(
			'methods' => 'POST',
			'permission_callback' => array( $this->core, 'can_access_settings' ),
			'callback' => array( $this, 'rest_update_gallery_rank' ),
		) );

		
		register_rest_route( $this->namespace, '/fetch_shortcodes', array(
			'methods' => 'POST',
			'permission_callback' => array( $this->core, 'can_access_features' ),
			'callback' => array( $this, 'rest_fetch_shortcodes' ),
		) );
		register_rest_route( $this->namespace, '/fetch_gallery_items', array(
			'methods' => 'POST',
			'permission_callback' => array( $this->core, 'can_access_features' ),
			'callback' => array( $this, 'rest_fetch_gallery_items' ),
		) );

		

		//Collection Manager
		register_rest_route( $this->namespace, '/save_collection', array(
			'methods' => 'POST',
			'permission_callback' => array( $this->core, 'can_access_settings' ),
			'callback' => array( $this, 'rest_save_collection' ),
		) );
		register_rest_route( $this->namespace, '/remove_collection', array(
			'methods' => 'POST',
			'permission_callback' => array( $this->core, 'can_access_settings' ),
			'callback' => array( $this, 'rest_remove_collection' ),
		) );

		register_rest_route( $this->namespace, '/fetch_collections', array(
			'methods' => 'POST',
			'permission_callback' => array( $this->core, 'can_access_features' ),
			'callback' => array( $this, 'rest_fetch_collections' ),
		) );
		
		register_rest_route( $this->namespace, '/load_gallery_collection', array(
			'methods' => 'POST',
			'permission_callback' => '__return_true',
			'callback' => array( $this, 'rest_load_gallery_collection' ),
		) );

		// Gutenberg Block
		register_rest_route( $this->namespace, '/preview', array(
			'methods' => 'POST',
			'permission_callback' => array( $this->core, 'can_access_features' ),
			'callback' => array( $this, 'preview' ),
		) );

		// Gallery
		register_rest_route( $this->namespace, '/images/', array(
			'methods' => 'POST',
			'permission_callback' => '__return_true',
			'callback' => array( $this, 'rest_images' )
		) );

		register_rest_route( $this->namespace, '/fetch_posts', array(
			'methods' => 'POST',
			'permission_callback' => array( $this->core, 'can_access_features' ),
			'callback' => array( $this, 'rest_fetch_posts' ),
			'args' => array(
				'search' => array( 'required' => false ),
				'offset' => array( 'required' => false, 'default' => 0 ),
				'limit' => array( 'required' => false, 'default' => 10 ),
			)
		) );
	}

	function preview( WP_REST_Request $request ) {
		$params = $request->get_body( );
		$params = json_decode( $params );
		$params->ids = implode( ',', $params->ids );
		$atts = ( array ) $params;

		$is_collection = isset( $atts['collection'] ) && !empty( $atts['collection'] );
		if ( $is_collection ) {
			$html = do_shortcode( '[meow-collection id="' . $atts['collection'] . '"]' );
		} else {
			$html = $this->core->gallery( $atts, [ 'isPreview' => true ] );
		}

		
		return new WP_REST_Response( [ 'success' => true, 'data' => $html ], 200 );
	}

	function rest_load_gallery_collection( $request ) {
		try {
			$params = $request->get_json_params( );
			$gallery_id = $params['id'];
			$search_slug = $params['search_slug'];
			$gallery_atts = $params['gallery_atts'];

			$key = [
				'gallery_id' => 'id',
				'wplr_collection_id' => 'wplr-collection',
			];

			$shortcode_atts = array();
			$shortcode_atts[ $key[$search_slug] ] = $gallery_id;
			$shortcode_atts = [...$shortcode_atts, ...$gallery_atts];

			$html = $this->core->gallery( $shortcode_atts, [ 'isPreview' => false, 'isRest' => true ] );
			$mwlData = json_encode( $this->core->get_rewritten_mwl_data( ) );
			return new WP_REST_Response( [ 'success' => true, 'data' => $html, 'mwl_data' => $mwlData ], 200 );
		}
		catch ( Exception $e ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage( ) ], 500 );
		}
	}

	function rest_all_settings( ) {
		return new WP_REST_Response( [ 'success' => true, 'data' => $this->core->get_all_options( ) ], 200 );
	}

	function rest_reset_options( ) {
		$this->core->reset_options( );
		return new WP_REST_Response( [ 'success' => true, 'options' => $this->core->get_all_options( ) ], 200 );
	}

	function rest_save_shortcode( $request ) {
		try {
			global $wpdb;
			$params = $request->get_json_params( );

			$id = $params['id'];
			$medias = $params['medias'];
			$name = $params['name'];
			$layout = $params['layout'];
			$description = $params['description'];
			$posts = $params['posts'];
			$latest_posts = $params['latest_posts'];
			$tags = $params['tags'];
			$dynamic_source = $params['dynamic_source'];
			$lead_image_id = $params['lead_image_id'];
			$order_by = $params['order_by'];
			$is_post_mode = $params['is_post_mode'];
			$is_hero_mode = $params['is_hero_mode'];

			if ( !$name ) {
				throw new Exception( __( 'Please enter a name for your shortcode.', MGL_DOMAIN ));
			}

			if ( !$is_post_mode && ( !$medias || !count( $medias['thumbnail_ids'] )) ) {
				throw new Exception( __( 'Please select at least one image.', MGL_DOMAIN ));
			}

			if ( $is_post_mode && $dynamic_source === 'posts' && ( !$posts && !$latest_posts )) {
				throw new Exception( __( 'Please select at least one post.', MGL_DOMAIN ));
			}

			if ( $is_post_mode && $dynamic_source === 'tags' && !$tags ) {
				throw new Exception( __( 'Please enter at least one tag.', MGL_DOMAIN ));
			}

			if ( $is_hero_mode && !$is_post_mode ) {
				throw new Exception( __( 'Hero mode is only available for post mode.', MGL_DOMAIN ));
			}

			if ( !$id || $id == '' ) {
				$id = $this->core->generate_uniqid( 10 );
			}

			$shortcodes_table = $wpdb->prefix . 'mgl_gallery_shortcodes';
			Meow_MGL_Migrations::check_db();

			// Check if the record exists
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( * ) FROM $shortcodes_table WHERE id = %s", $id ));
			
			$data = [
				'name' => $name,
				'description' => $description,
				'layout' => $layout,
				'medias' => serialize( $medias ),
				'lead_image_id' => $lead_image_id,
				'order_by' => $order_by,
				'is_post_mode' => $is_post_mode ? 1 : 0,
				'is_hero_mode' => $is_hero_mode ? 1 : 0,
				'posts' => $posts ? serialize( $posts ) : null,
				'latest_posts' => $latest_posts,
				'tags' => serialize( $tags ),
				'dynamic_source' => $dynamic_source
			];
			
			if ( $exists ) {
				// Update existing record
				$wpdb->update(
					$shortcodes_table,
					$data,
					['id' => $id]
				);
			} else {
				// Insert new record
				$data['id'] = $id;
				$wpdb->insert( $shortcodes_table, $data );
			}
			

			return new WP_REST_Response( ['success' => true, 'message' => 'Shortcode created.'], 200 );
		} catch ( Exception $e ) {
			return new WP_REST_Response( ['success' => false, 'message' => $e->getMessage( )], 500 );
		}
	}

	function rest_remove_collection( $request ) {
		try {
			global $wpdb;
			$params = $request->get_json_params( );
			$id = $params['id'];
			
			$collections_table = $wpdb->prefix . 'mgl_collections';
			Meow_MGL_Migrations::check_db();

			$wpdb->delete( $collections_table, ['id' => $id] );
			
			
			return new WP_REST_Response( ['success' => true, 'message' => 'Collection removed.'], 200 );
		} catch ( Exception $e ) {
			return new WP_REST_Response( ['success' => false, 'message' => $e->getMessage( )], 500 );
		}
	}

	function rest_save_collection( $request ) {
		try {
			global $wpdb;
			$params = $request->get_json_params( );

			$id = $params['id'];
			$name = $params['name'];
			$layout = $params['layout'];
			$galleries_ids = $params['galleries_ids'];
			$description = $params['description'];
			
			if ( !$name ) {
				throw new Exception( __( 'Please enter a name for your collection.', MGL_DOMAIN ));
			}

			if ( !$galleries_ids || !count( $galleries_ids )) {
				throw new Exception( __( 'Please select at least one gallery.', MGL_DOMAIN ));
			}

			if ( !$id || $id == '' ) {
				$id = $this->core->generate_uniqid( 10 );
			}

			$collections_table = $wpdb->prefix . 'mgl_collections';
			Meow_MGL_Migrations::check_db();

			// Check if the record exists
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( * ) FROM $collections_table WHERE id = %s", $id ));
			
			$data = [
				'name' => $name,
				'description' => $description,
				'layout' => $layout,
				'galleries_ids' => serialize( $galleries_ids )
			];
			
			if ( $exists ) {
				// Update existing record
				$wpdb->update(
					$collections_table,
					$data,
					['id' => $id]
				);
			} else {
				// Insert new record
				$data['id'] = $id;
				$wpdb->insert( $collections_table, $data );
			}
			

			return new WP_REST_Response( ['success' => true, 'message' => 'Collection created.'], 200 );
		} catch ( Exception $e ) {
			return new WP_REST_Response( ['success' => false, 'message' => $e->getMessage( )], 500 );
		}
	}


	function rest_fetch_collections( $request ) {
		try {
			$params = $request->get_json_params( );

			$offset = isset( $params['offset'] ) ? $params['offset'] : 0;
			$limit = isset( $params['limit'] ) ? $params['limit'] : 10;
			$sort_updated = $params['sort']['by']; // desc, asc
			$page = isset( $params['page'] ) ? $params['page'] : 1;
			$order = $sort_updated === 'desc' ? 'DESC' : 'ASC';
			$search = isset( $params['search'] ) ? $params['search'] : '';

			$res = $this->core->get_collections( $offset, $limit, $order, $page, $search );
			$collections = $res['collections'];
			$total = $res['total'];
			
			return new WP_REST_Response( ['success' => true, 'data' => $collections, 'total' => $total], 200 );
		}
		catch ( Exception $e ) {
			return new WP_REST_Response( ['success' => false, 'message' => $e->getMessage( )], 500 );
		}
	}

	function rest_fetch_gallery_items( $request ) {
		try {
			global $wpdb;
			$params = $request->get_json_params( );
			$galleryIds = $params['galleryIds'];
			
			$shortcodes_table = $wpdb->prefix . 'mgl_gallery_shortcodes';
			Meow_MGL_Migrations::check_db();

			$galleries = [];
			if ( !empty( $galleryIds )) {
				$ids_str = "'" . implode( "','", array_map( 'esc_sql', $galleryIds )) . "'";
				$query = "SELECT * FROM $shortcodes_table WHERE id IN ( $ids_str )";
				$results = $wpdb->get_results( $query, ARRAY_A );
				
				foreach ( $results as $gallery ) {
					// Transform database format to match expected format
					$galleries[$gallery['id']] = [
						'name' => $gallery['name'],
						'description' => $gallery['description'],
						'layout' => $gallery['layout'],
						'medias' => unserialize( $gallery['medias'] ),
						'is_post_mode' => ( bool )$gallery['is_post_mode'],
						'hero' => ( bool )$gallery['is_hero_mode'],
						'posts' => $gallery['posts'] ? unserialize( $gallery['posts'] ) : null,
						'latest_posts' => $gallery['latest_posts'],
						'tags' => unserialize( $gallery['tags'] ),
						'dynamic_source' => $gallery['dynamic_source'],
						'updated' => strtotime( $gallery['updated_at'] )
					];
				}
			}
			
			return new WP_REST_Response( ['success' => true, 'data' => $galleries], 200 );
		} catch ( Exception $e ) {
			return new WP_REST_Response( ['success' => false, 'message' => $e->getMessage( )], 500 );
		}
	}

	function rest_fetch_shortcodes( $request ) {
		try {
			$params = $request->get_json_params( );

			$offset = isset( $params['offset'] ) ? $params['offset'] : 0;
			$limit = isset( $params['limit'] ) ? $params['limit'] : 10;
			$page = isset( $params['page'] ) ? $params['page'] : 1;
			
			$search = isset( $params['search'] ) ? $params['search'] : '';
			
			$sort_by  = $params['sort']['accessor'] ?? null;
			$order_by = strtoupper( $params['sort']['by'] ); // desc, asc

			$res = $this->core->get_galleries( $offset, $limit, $order_by, $sort_by, $page, $search );
			$shortcodes = $res['galleries'];
			$total = $res['total'];
			
			return new WP_REST_Response( ['success' => true, 'data' => $shortcodes, 'total' => $total], 200 );
		}
		catch ( Exception $e ) {
			return new WP_REST_Response( ['success' => false, 'message' => $e->getMessage( )], 500 );
		}
	}

	function rest_remove_shortcode( $request ) {
		try {
			global $wpdb;
			$params = $request->get_json_params( );
			$id = $params['id'];
			
			$shortcodes_table = $wpdb->prefix . 'mgl_gallery_shortcodes';
			Meow_MGL_Migrations::check_db();

			$wpdb->delete( $shortcodes_table, ['id' => $id] );
			
			
			return new WP_REST_Response( ['success' => true, 'message' => 'Shortcode removed.'], 200 );
		} catch ( Exception $e ) {
			return new WP_REST_Response( ['success' => false, 'message' => $e->getMessage( )], 500 );
		}
	}

	function rest_update_gallery_rank( $request ) {
		try {
			global $wpdb;
			$params = $request->get_json_params();
			$id = $params['id'];
			$direction = $params['direction']; // 'up' or 'down'
			
			$shortcodes_table = $wpdb->prefix . 'mgl_gallery_shortcodes';
			Meow_MGL_Migrations::check_db();

			// Get current rank
			$current_rank = $wpdb->get_var( $wpdb->prepare( "SELECT pref_rank FROM $shortcodes_table WHERE id = %s", $id ) );
			$current_rank = intval( $current_rank );
			
			// Calculate new rank (up = higher priority = higher number, down = lower priority = lower number)
			$new_rank = $direction === 'up' ? $current_rank + 1 : $current_rank - 1;
			
			// Update the rank
			$wpdb->update(
				$shortcodes_table,
				['pref_rank' => $new_rank],
				['id' => $id]
			);
			
			return new WP_REST_Response( ['success' => true, 'message' => 'Gallery rank updated.', 'new_rank' => $new_rank], 200 );
		} catch ( Exception $e ) {
			return new WP_REST_Response( ['success' => false, 'message' => $e->getMessage()], 500 );
		}
	}

	function rest_latest_photos( $request ) {

		$search = trim( $request->get_param( 'search' ) );
		$offset = trim( $request->get_param( 'offset' ) );
		$limit = trim( $request->get_param( 'limit' ) );

		$except = json_decode( trim( $request->get_param( 'except' ) ), true );
		$unusedImages = trim( $request->get_param( 'unusedImages' ) );

		global $wpdb;
		$searchPlaceholder = $search ? '%' . $search . '%' : '';
		$where_search_clause = $search ? $wpdb->prepare(
			"AND ( p.post_title LIKE %s OR p.post_content LIKE %s OR p.post_name LIKE %s ) ",
			$searchPlaceholder,
			$searchPlaceholder,
			$searchPlaceholder
		) : '';
		$where_search_clause .= $except && count( $except ) ? $wpdb->prepare(
			"AND p.ID NOT IN ( " . implode( ', ', array_fill( 0, count( $except ), '%s' )) . " )", $except
		) : '';
		$join_clause = '';
		if ( $unusedImages ) {
			// Retrieve the serialized option from the database
			$meow_gallery_shortcodes = get_option( 'mgl_shortcodes' );

			// Deserialize the option to get the array
			$shortcodes_array = maybe_unserialize( $meow_gallery_shortcodes );

			// Extract all thumbnail IDs from the array
			$used_thumbnail_ids = [];
			foreach ( $shortcodes_array as $shortcode ) {
				if ( isset( $shortcode['medias']['thumbnail_ids'] ) && is_array( $shortcode['medias']['thumbnail_ids'] ) ) {
					$used_thumbnail_ids = array_merge( $used_thumbnail_ids, $shortcode['medias']['thumbnail_ids'] );
				}
			}

			// Make sure the IDs are integers
			$used_thumbnail_ids = array_map( 'intval', $used_thumbnail_ids );

			// Include the NOT IN clause to exclude used thumbnail IDs
			if ( !empty( $used_thumbnail_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $used_thumbnail_ids ), '%d' ) );
				$where_search_clause .= $wpdb->prepare( " AND p.ID NOT IN ( $placeholders ) ", $used_thumbnail_ids );
			}
		}
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_mime_type 
				FROM $wpdb->posts p 
				$join_clause
				WHERE p.post_type='attachment' 
				AND p.post_status='inherit' 
				$where_search_clause 
				ORDER BY p.post_modified DESC 
				LIMIT %d, $limit", $offset
			), OBJECT
		);
		$posts_count = ( int )$wpdb->get_var(
			"SELECT COUNT( * )
			FROM $wpdb->posts p 
			$join_clause
			WHERE p.post_type='attachment' 
			AND p.post_status='inherit' 
			$where_search_clause"
		);

		$data = [];
		foreach ( $posts as $post ) {
			$file_url = get_attached_file( $post->ID );

			$mime = $post->post_mime_type;
			$is_video = ( strpos( $mime, 'video' ) !== false );

			$thumbnail_url = $is_video ? wp_get_attachment_url( $post->ID ) : wp_get_attachment_image_url( $post->ID, 'thumbnail' );

			if ( file_exists( $file_url ) ) {
				$data[] = [
					'id' => $post->ID,
					'thumbnail_url' => $thumbnail_url,
					'zoom_url' => wp_get_attachment_image_url( $post->ID, 'large' ),
					'title' => $post->post_title,
					'filename' => basename( $file_url ),
					'size' => size_format( filesize( $file_url ) ),
					'mime' => $mime,
				];
			}
		}
		return new WP_REST_Response( [
			'success' => true,
			'data' => $data,
			'total' => $posts_count
		], 200 );
	}

	function rest_update_option( $request ) {
		try {
			$params = $request->get_json_params( );
			$value = $params['options'];
			$options = $this->core->update_options( $value );
			$success = !!$options;
			$message = __( $success ? 'OK' : "Could not update options.", MGL_DOMAIN );
			return new WP_REST_Response( [ 'success' => $success, 'message' => $message, 'options' => $success ? $options : null ], 200 );
		}
		catch ( Exception $e ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage( ) ], 500 );
		}
	}

	function rest_images( $request ) {
		$params = $request->get_json_params( );

		$image_ids = $params['imageIds'];
		$atts = $params['atts'];
		$layout = trim( $params['layout'] );
		$size = trim( $params['size'] );

		return new WP_REST_Response( [
			'success' => true,
			'data' => $this->core->get_gallery_images(  $image_ids, $atts, $layout, $size )
		], 200 );
	}

	function rest_fetch_posts( $request ) {
		try {
			$params = $request->get_json_params();
			$search = isset($params['search']) ? $params['search'] : '';
			$offset = isset($params['offset']) ? intval($params['offset']) : 0;
			$limit = isset($params['limit']) ? intval($params['limit']) : 10;

			global $wpdb;
			$searchPlaceholder = $search ? '%' . $search . '%' : '';
			$where_search_clause = $search ? $wpdb->prepare(
				"AND ( p.post_title LIKE %s OR p.post_content LIKE %s OR p.post_name LIKE %s ) ",
				$searchPlaceholder,
				$searchPlaceholder,
				$searchPlaceholder
			) : '';

			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_date, p.post_status, u.display_name as author
					FROM $wpdb->posts p 
					LEFT JOIN $wpdb->users u ON p.post_author = u.ID
					WHERE p.post_type = 'post' 
					AND p.post_status IN ('publish', 'draft', 'private')
					$where_search_clause 
					ORDER BY p.post_date DESC 
					LIMIT %d, %d", 
					$offset, 
					$limit
				), 
				OBJECT
			);

			$posts_count = (int)$wpdb->get_var(
				"SELECT COUNT(*)
				FROM $wpdb->posts p 
				WHERE p.post_type = 'post' 
				AND p.post_status IN ('publish', 'draft', 'private')
				$where_search_clause"
			);

			$data = array_map(function($post) {
				return [
					'id' => $post->ID,
					'title' => $post->post_title,
					'date' => $post->post_date,
					'author' => $post->author,
					'status' => $post->post_status
				];
			}, $posts);

			return new WP_REST_Response([
				'success' => true,
				'data' => $data,
				'total' => $posts_count
			], 200);
		} catch (Exception $e) {
			return new WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
		}
	}

}

?>