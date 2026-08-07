<?php
/**
 * Main plugin class file.
 *
 * @package ndlas-h5p-extractor
 */

namespace NDLA\H5PExtractor;

// as suggested by the WordPress community.
defined( 'ABSPATH' ) || die( 'No script kiddies please!' );

/**
 * Main plugin class.
 */
class Main {

	/**
	 * Constructor.
	 */
	public function __construct() {
		new Options();
		new Request_Handler();

		// Defer H5P detection to a later hook when post is available.
		add_action( 'wp', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_dashboard_sidebar' ) );
	}

	/**
	 * Register the H5P Extractor dashboard sidebar.
	 *
	 * Only registered when editing a post/page that contains H5P content and allow_override is enabled.
	 */
	public function register_dashboard_sidebar() {
		if ( ! Options::get_allow_override() ) {
			return;
		}

		$post = get_post();
		if ( ! $post || empty( $post->post_content ) ) {
			return;
		}

		if ( ! has_shortcode( $post->post_content, 'h5p' ) ) {
			return;
		}

		add_meta_box(
			'ndla_h5p_extractor_dashboard_help',
			__( 'H5P Extractor', 'ndlas-h5p-extractor' ),
			array( $this, 'render_dashboard_sidebar_content' ),
			$post->post_type,
			'side'
		);
	}

	/**
	 * Render the dashboard sidebar content. Not worth using a templating engine just for that bit.
	 */
	public function render_dashboard_sidebar_content() {
		?>
		<p>
			<?php esc_html_e( 'You can override the H5P Extractor behavior by adding key-value pairs to the H5P shortcode:', 'ndlas-h5p-extractor' ); ?>
		</p>
		<ul>
			<li>
				<strong><code>ndla_extractor_mode</code></strong></br>
				<code>off</code>, <code>button</code>, <code>automatic</code>
			</li>
			<li>
				<strong><code>ndla_extractor_solutions</code></strong></br>
				<code>true</code>, <code>false</code>
			</li>
		</ul>
		<p><strong><?php esc_html_e( 'Example', 'ndlas-h5p-extractor' ); ?></strong></p>
		<p>
			<code>[h5p id="1" ndla_extractor="automatic"]</code>
		</p>
		<?php
	}

	/**
	 * Enqueue scripts and styles if H5P content is showing.
	 */
	public function maybe_enqueue_assets() {
		if ( ! $this->is_h5p_in_page_or_post() && ! $this->is_h5p_in_dashboard() ) {
			return;
		}

		$default_extraction_mode  = Options::get_extraction_mode();
		$default_show_solutions   = Options::get_show_solutions();
		$allow_override           = Options::get_allow_override();
		$content_specific_options = array();

		$content_specific_options = $this->get_content_specific_options(
			get_post(),
			$default_extraction_mode,
			$default_show_solutions,
			$allow_override
		);

		wp_enqueue_script(
			'h5p-extractor-script',
			// TODO: I don't like reverse traversal.
			plugins_url( '../js/h5p-extractor.js', __FILE__ ),
			array(),
			NDLASH5PEXTRACTOR_VERSION,
			false
		);

		wp_localize_script(
			'h5p-extractor-script',
			'NDLASH5PEXTRACTOR',
			array(
				'ajaxURL'                => admin_url( 'admin-ajax.php' ),
				'nonce'                  => wp_create_nonce( 'ndlas_h5p_extractor' ),
				'l10n'                   => array(
					'extracting'      => __( 'Extracting...', 'ndlas_h5p_extractor' ),
					'errorExtracting' => __( 'Could not extract plain text.', 'ndlas_h5p_extractor' ),
					'hide'            => __( 'Hide plain text', 'ndlas_h5p_extractor' ),
					'show'            => __( 'Show as plain text', 'ndlas_h5p_extractor' ),
				),
				'extractionMode'         => $default_extraction_mode,
				'showSolutions'          => $default_show_solutions,
				'contentSpecificOptions' => $content_specific_options,
			),
		);

		wp_enqueue_style(
			'h5p-extractor-style',
			// TODO: I don't like reverse traversal.
			plugins_url( '../js/h5p-extractor.css', __FILE__ ),
			array(),
			NDLASH5PEXTRACTOR_VERSION,
			'all'
		);
	}

	/**
	 * Check if current request is H5P dashboard show page.
	 *
	 * @return bool True if viewing H5P content in WordPress dashboard.
	 */
	private function is_h5p_in_dashboard() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Not processing form values, just checking URL.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Not processing form values, just checking URL.
		$task = isset( $_GET['task'] ) ? sanitize_text_field( wp_unslash( $_GET['task'] ) ) : '';

		return 'h5p' === $page && 'show' === $task;
	}

	/**
	 * Check if current page/post contains [h5p] shortcode.
	 *
	 * @return bool True if current page/post contains H5P shortcode.
	 */
	private function is_h5p_in_page_or_post() {
		$post = get_post();
		if ( $post && ! empty( $post->post_content ) ) {
			if ( has_shortcode( $post->post_content, 'h5p' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build content-specific options from shortcode attributes.
	 *
	 * @param \WP_Post|null $post The current post, or null.
	 * @param string        $default_extraction_mode The default extraction mode.
	 * @param bool          $default_show_solutions The default show solutions setting.
	 * @param bool          $allow_override Whether shortcode-level overrides are allowed.
	 *
	 * @return array Content-specific options indexed by discovery order.
	 */
	private function get_content_specific_options( $post, $default_extraction_mode, $default_show_solutions, $allow_override ) {
		$options = array();

		if ( ! $post || empty( $post->post_content ) ) {
			return $options;
		}

		// Extract key value pairs from H5P shortcode.
		$matches = array();
		preg_match_all( '/\[h5p([^]]*)\]/', $post->post_content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$attributes = shortcode_parse_atts( $match[1] );
			if ( ! isset( $attributes['id'] ) && ! isset( $attributes['slug'] ) ) {
				continue;
			}

			$options[] = (object) array(
				'id'             => (int) $attributes['id'],
				'extractionMode' => $allow_override && isset( $attributes['ndla_extractor_mode'] )
					? $attributes['ndla_extractor_mode']
					: $default_extraction_mode,
				'showSolutions'  => $allow_override && isset( $attributes['ndla_extractor_solutions'] )
					? filter_var( $attributes['ndla_extractor_solutions'], FILTER_VALIDATE_BOOLEAN )
					: $default_show_solutions,
			);
		}

		return $options;
	}
}
