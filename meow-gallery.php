<?php
/*
Plugin Name: Meow Gallery (Google Photos Edition)
Plugin URI: https://github.com/pepeenr/meow-gallery-google-photos
Description: Customized fork of Meow Gallery that adds support for displaying photos from a public Google Photos album via the [mgl-google-photos] shortcode or the "Meow Gallery: Google Photos" block. Based on Meow Gallery by Jordy Meow.
Version: 1.0.0
Author: Jordy Meow (Google Photos edition by pepeenr)
Author URI: https://github.com/pepeenr/meow-gallery-google-photos
Text Domain: meow-gallery
Domain Path: /languages
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Customized fork that merges Google Photos album support into Meow Gallery.

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License, version 2 or (at your option) any
later version. See the LICENSE file for the full license text.

Credits:
- Meow Gallery, by Jordy Meow / Meow Apps (https://meowapps.com) — the gallery
  engine this plugin is based on (GPLv2 or later).
- Simple Google Photos Grid, by Josheli
  (https://github.com/datvance/simple-google-photos-grid) — the public Google
  Photos album fetching approach was adapted from this plugin (GPL2).
*/

if ( !defined( 'MGL_VERSION' ) ) {
  // Fork release version (keep in sync with the "Version:" header above and
  // readme.txt "Stable tag:"). This is what WordPress uses for update checks.
  define( 'MGL_VERSION', '1.0.0' );
  // Upstream Meow Gallery version this fork is currently based on. Bump this
  // (not as the release version) whenever upstream changes are merged in.
  define( 'MGL_UPSTREAM_VERSION', '5.5.0' );
  define( 'MGL_PREFIX', 'mgl' );
  define( 'MGL_DOMAIN', ' meow-gallery' );
  define( 'MGL_ENTRY', __FILE__ );
  define( 'MGL_PATH', dirname( __FILE__ ) );
  define( 'MGL_URL', plugin_dir_url( __FILE__ ) );
  define( 'MGL_ITEM_ID', 6232 );
}

require_once( 'classes/init.php');

?>
