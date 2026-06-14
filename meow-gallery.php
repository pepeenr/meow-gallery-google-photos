<?php
/*
Plugin Name: Meow Gallery (Google Photos Edition)
Plugin URI: https://github.com/pepeenr/meow-gallery-google-photos
Description: Customized fork of Meow Gallery that adds support for displaying photos from a public Google Photos album via the [mgl-google-photos] shortcode or the "Meow Gallery: Google Photos" block. Based on Meow Gallery by Jordy Meow.
Version: 5.5.0
Author: Jordy Meow (Google Photos edition by pepeenr)
Author URI: https://github.com/pepeenr/meow-gallery-google-photos
Text Domain: meow-gallery
Domain Path: /languages

Customized fork that merges Google Photos album support into Meow Gallery.
Original plugin developed by Jordy Meow:
- Jordy Meow (https://offbeatjapan.org)
- Haikyo (https://haikyo.org)
*/

if ( !defined( 'MGL_VERSION' ) ) {
  define( 'MGL_VERSION', '5.5.0' );
  define( 'MGL_PREFIX', 'mgl' );
  define( 'MGL_DOMAIN', ' meow-gallery' );
  define( 'MGL_ENTRY', __FILE__ );
  define( 'MGL_PATH', dirname( __FILE__ ) );
  define( 'MGL_URL', plugin_dir_url( __FILE__ ) );
  define( 'MGL_ITEM_ID', 6232 );
}

require_once( 'classes/init.php');

?>
