<?php
/**
 * Options page for the plugin.
 *
 * @package ndlas-h5p-extractor
 */

namespace NDLA\H5PExtractor;

// as suggested by the WordPress community.
defined( 'ABSPATH' ) || die( 'No script kiddies please!' );

/**
 * Options page for the plugin.
 */
class Options {

	/**
	 * Option slug.
	 *
	 * @var string
	 */
	private static $option_slug = 'ndlash5pextractor_option';

	/**
	 * Options.
	 *
	 * @var array
	 */
	private static $options;

	/**
	 * Start up.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'page_init' ) );
	}

	/**
	 * Get the option slug.
	 *
	 * @return string Option slug.
	 */
	public static function get_slug() {
		return self::$option_slug;
	}

	/**
	 * Delete options.
	 */
	public static function delete_options() {
		delete_option( self::$option_slug );
		delete_site_option( self::$option_slug );
	}

	/**
	 * Add options page.
	 */
	public function add_plugin_page() {
		// This page will be under "Settings".
		add_options_page(
			'Settings Admin',
			'H5P Extractor',
			'manage_options',
			'ndlash5pextractor-admin',
			array( $this, 'create_admin_page' )
		);
	}

	/**
	 * Options page callback.
	 */
	public function create_admin_page() {
		?>
		<div class="wrap">
			<h2><?php echo esc_html( __( 'H5P Extractor', 'ndlas-h5p-extractor' ) ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'ndlash5pextractor_option_group' );
				do_settings_sections( 'ndlash5pextractor-admin' );
				?>

				<?php $this->render_default_behavior_section(); ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the "Default behavior" settings section.
	 */
	private function render_default_behavior_section() {
		// Intentionally empty — WordPress renders the <h3> from the section title.
	}

	/**
	 * Render the "Extraction mode" field.
	 */
	public function render_extraction_mode_field() {
		$mode = self::get_extraction_mode();
		?>
		<select
			name="ndlash5pextractor_option[extraction_mode]"
			id="ndlash5pextractor_option_extraction_mode"
		>
			<option
				value="off"
				<?php selected( $mode, 'off' ); ?>
			>
				<?php esc_html_e( 'Off', 'ndlas-h5p-extractor' ); ?>
			</option>
			<option
				value="trigger_by_button"
				<?php selected( $mode, 'trigger_by_button' ); ?>
			>
				<?php esc_html_e( 'Trigger by button', 'ndlas-h5p-extractor' ); ?>
			</option>
			<option
				value="automatic"
				<?php selected( $mode, 'automatic' ); ?>
			>
				<?php esc_html_e( 'Automatic', 'ndlas-h5p-extractor' ); ?>
			</option>
		</select>
		<br>
		<span class="description">
			<?php esc_html_e( 'Set how extraction should be handled.', 'ndlas-h5p-extractor' ); ?>
		</span>
		<?php
	}

	/**
	 * Render the "Show solutions" field.
	 */
	public function render_show_solutions_field() {
		?>
		<input
			name="ndlash5pextractor_option[show_solutions]"
			type="checkbox"
			id="ndlash5pextractor_option_show_solutions"
			value="1"
			<?php checked( self::get_show_solutions(), true ); ?>
		/>
		<label for="ndlash5pextractor_option_show_solutions">
			<?php esc_html_e( 'Show solutions underneath the extracted H5P content.', 'ndlas-h5p-extractor' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the "Allow override" field.
	 */
	public function render_allow_override_field() {
		?>
		<input
			name="ndlash5pextractor_option[allow_override]"
			type="checkbox"
			id="ndlash5pextractor_option_allow_override"
			value="1"
			<?php checked( self::get_allow_override(), true ); ?>
		/>
		<label for="ndlash5pextractor_option_allow_override">
			<?php esc_html_e( 'Content authors can override the default behavior per H5P content.', 'ndlas-h5p-extractor' ); ?>
		</label>
		<?php
	}

	/**
	 * Register and add settings.
	 */
	public function page_init() {
		register_setting(
			'ndlash5pextractor_option_group',
			'ndlash5pextractor_option',
			array( $this, 'sanitize' )
		);

		add_settings_section(
			'ndlash5pextractor_default_behavior',
			__( 'Default behavior', 'ndlas-h5p-extractor' ),
			array( $this, 'render_section_heading' ),
			'ndlash5pextractor-admin'
		);

		add_settings_field(
			'ndlash5pextractor_option_extraction_mode',
			__( 'Extraction mode', 'ndlas-h5p-extractor' ),
			array( $this, 'render_extraction_mode_field' ),
			'ndlash5pextractor-admin',
			'ndlash5pextractor_default_behavior'
		);

		add_settings_field(
			'ndlash5pextractor_option_show_solutions',
			__( 'Solutions', 'ndlas-h5p-extractor' ),
			array( $this, 'render_show_solutions_field' ),
			'ndlash5pextractor-admin',
			'ndlash5pextractor_default_behavior'
		);

		add_settings_section(
			'ndlash5pextractor_overrides',
			__( 'Overrides', 'ndlas-h5p-extractor' ),
			array( $this, 'render_section_heading' ),
			'ndlash5pextractor-admin'
		);

		add_settings_field(
			'ndlash5pextractor_option_allow_override',
			__( 'Allow override', 'ndlas-h5p-extractor' ),
			array( $this, 'render_allow_override_field' ),
			'ndlash5pextractor-admin',
			'ndlash5pextractor_overrides'
		);
	}

	/**
	 * Render the section heading callback.
	 */
	public function render_section_heading() {
		// Intentionally empty — WordPress renders the <h3> from the section title.
	}

	/**
	 * Sanitize each setting field as needed.
	 *
	 * @param array $input Contains all settings fields as array keys.
	 * @return array Output.
	 */
	public function sanitize( $input ) {
		$input = (array) $input;

		$new_input = array();

		$valid_modes = array( 'off', 'trigger_by_button', 'automatic' );
		if ( isset( $input['extraction_mode'] ) && in_array( $input['extraction_mode'], $valid_modes, true ) ) {
			$new_input['extraction_mode'] = $input['extraction_mode'];
		} else {
			$new_input['extraction_mode'] = 'off';
		}
		$new_input['show_solutions'] = isset( $input['show_solutions'] ) ? true : false;
		$new_input['allow_override'] = isset( $input['allow_override'] ) ? true : false;

		return $new_input;
	}

	/**
	 * Get extraction mode option.
	 *
	 * @return string Extraction mode.
	 */
	public static function get_extraction_mode() {
		return ( isset( self::$options['extraction_mode'] ) ) ? self::$options['extraction_mode'] : 'off';
	}

	/**
	 * Get show solutions option.
	 *
	 * @return bool Show solutions.
	 */
	public static function get_show_solutions() {
		return ( isset( self::$options['show_solutions'] ) ) ? (bool) self::$options['show_solutions'] : false;
	}

	/**
	 * Get allow override option.
	 *
	 * @return bool Allow override.
	 */
	public static function get_allow_override() {
		return ( isset( self::$options['allow_override'] ) ) ? (bool) self::$options['allow_override'] : false;
	}

	/**
	 * Init function for the class.
	 */
	public static function init() {
		self::$options = get_option( self::$option_slug, false );
	}
}

Options::init();
