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
				?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="ndlash5pextractor_option_show_solutions">
								<?php esc_html_e( 'Show solutions', 'ndlas-h5p-extractor' ); ?>
							</label>
						</th>
						<td>
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
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
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
	}

	/**
	 * Sanitize each setting field as needed.
	 *
	 * @param array $input Contains all settings fields as array keys.
	 * @return array Output.
	 */
	public function sanitize( $input ) {
		$input = (array) $input;

		$new_input                   = array();
		$new_input['show_solutions'] = isset( $input['show_solutions'] ) ? true : false;

		return $new_input;
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
	 * Init function for the class.
	 */
	public static function init() {
		self::$options = get_option( self::$option_slug, false );
	}
}

Options::init();
