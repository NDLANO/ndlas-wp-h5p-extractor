<?php
/**
 * Plugin Name: NDLA's H5P Extractor
 * Plugin URI: https://github.com/ndlano/ndlas-h5p-extractor
 * Text Domain: ndlas-h5p-extractor
 * Description: Generate textual representations of H5P contents.
 * Version: 0.2.3
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: NDLA, Oliver Tacke (SNORDIAN)
 * Author URI: https://explore.ndla.no
 * License: MIT
 *
 * @package ndlas-h5p-extractor
 */

namespace NDLA\H5PExtractor;

// as suggested by the WordPress community.
defined( 'ABSPATH' ) || die( 'No script kiddies please!' );

if ( ! defined( 'NDLASH5PEXTRACTOR_VERSION' ) ) {
	define( 'NDLASH5PEXTRACTOR_VERSION', '0.2.3' );
}

/*
 * NOTE: We deliberately do NOT load `vendor/autoload.php` here. Composer's
 * autoloader would eagerly `require` the bundled `h5p/h5p-core` 1.28 files, which
 * collides with the H5P WordPress plugin's own (older) h5p-core copy and triggers
 * a fatal error because the plugin's `H5PWordPress` class does not implement
 * methods added to `H5PFrameworkInterface` in 1.28 (e.g. resetHubOrganizationData).
 * H5PExtractor itself registers its own `H5PExtractor\*` autoloader on
 * instantiation and only relies on the global H5P core classes that the WP plugin
 * already provides.
 */

require_once join( DIRECTORY_SEPARATOR, array( __DIR__, 'includes', 'class-capabilities.php' ) );
require_once join( DIRECTORY_SEPARATOR, array( __DIR__, 'includes', 'class-main.php' ) );
require_once join( DIRECTORY_SEPARATOR, array( __DIR__, 'includes', 'class-options.php' ) );
require_once join( DIRECTORY_SEPARATOR, array( __DIR__, 'includes', 'class-request-handler.php' ) );

/**
 * Check whether all dependencies are installed.
 *
 * @return bool True if dependencies are fulfilled, else false.
 */
function dependencies_fulfilled() {
	$h5p_plugin_version = get_option( 'h5p_version' );

	return ! empty( $h5p_plugin_version );
}

/**
 * Display admin messages.
 */
function display_admin_messages() {
	if ( get_transient( 'ndlash5pextractor_h5p_not_installed' ) ) {
		delete_transient( 'ndlash5pextractor_h5p_not_installed' );
		// Does not get translated. Wonder why.
		echo '<div class="error">' .
			'<p>' .
				esc_html__(
					'Note: This plugin requires the H5P plugin.',
					'ndlas-h5p-extractor'
				) .
			'</p>' .
		'</div>';
	}
}

/**
 * Handle plugin activation.
 */
function on_activation() {
	if ( ! dependencies_fulfilled() ) {
		set_transient( 'ndlash5pextractor_h5p_not_installed', true, 30 );
	}

	Capabilities::add_capabilities();
}

/**
 * Handle plugin uninstallation.
 */
function on_uninstall() {
	Options::delete_options();
	Capabilities::remove_capabilities();
}

/**
 * Main plugin class.
 *
 * @return object Main.
 */
function init() {
	// I prefer to keep load_plugin_textdomain, so this works without access to wordpress.org, too.
  // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	load_plugin_textdomain(
		'ndlas-h5p-extractor',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);

	return new Main();
}

register_activation_hook( __FILE__, 'NDLA\H5PExtractor\on_activation' );
register_uninstall_hook( __FILE__, 'NDLA\H5PExtractor\on_uninstall' );

add_action( 'init', 'NDLA\H5PExtractor\init' );
add_action( 'admin_notices', 'NDLA\H5PExtractor\display_admin_messages' );
