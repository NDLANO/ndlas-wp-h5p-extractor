<?php
/**
 * Capabilities management for H5P related plugins.
 *
 * @package ndlas-h5p-extractor
 */

namespace NDLA\H5PExtractor;

// as suggested by the WordPress community.
defined( 'ABSPATH' ) || die( 'No script kiddies please!' );

/**
 * Class for managing capabilities.
 */
class Capabilities {

	/**
	 * Add default capabilities.
	 */
	public static function add_capabilities() {
		// Add capabilities.
		global $wp_roles;

		$all_roles = $wp_roles->roles;
		foreach ( $all_roles as $role_name => $role_info ) {
			$role = get_role( $role_name );

			self::map_capability( $role, $role_info, 'manage_h5p_libraries', 'manage_h5p_theme_global_settings' );
		}
	}

	/**
	 * Remove default capabilities.
	 */
	public static function remove_capabilities() {
		// Remove capabilities.
		global $wp_roles;

		$all_roles = $wp_roles->roles;
		foreach ( $all_roles as $role_name => $role_info ) {
			$role = get_role( $role_name );

			if ( isset( $role_info['capabilities']['manage_h5p_theme_global_settings'] ) ) {
				$role->remove_cap( 'manage_h5p_theme_global_settings' );
			}
		}
	}

	/**
	 * Make sure that a role has or hasn't the provided capability depending on existing roles.
	 *
	 * @param \WP_Role     $role Role object.
	 * @param array        $role_info Role information.
	 * @param string|array $existing_cap Existing capability.
	 * @param string       $new_cap New capability.
	 */
	private static function map_capability( $role, $role_info, $existing_cap, $new_cap ) {
		if ( isset( $role_info['capabilities'][ $new_cap ] ) ) {
			// Already has new cap.
			if ( ! self::has_capability( $role_info['capabilities'], $existing_cap ) ) {
				// But shouldn't have it!
				$role->remove_cap( $new_cap );
			}
		} elseif ( self::has_capability( $role_info['capabilities'], $existing_cap ) ) {
			// Should have new cap.
			$role->add_cap( $new_cap );
		}
	}

	/**
	 * Check that role has the needed capabilities.
	 *
	 * @param array        $role_capabilities Role capabilities.
	 * @param string|array $capability Capabilities to check for.
	 *
	 * @return bool True, if role has capability, else false.
	 */
	private static function has_capability( $role_capabilities, $capability ) {
		$capabilities = (array) $capability;

		foreach ( $capabilities as $cap ) {
			if ( ! isset( $role_capabilities[ $cap ] ) ) {
				return false;
			}
		}

		return true;
	}
}
