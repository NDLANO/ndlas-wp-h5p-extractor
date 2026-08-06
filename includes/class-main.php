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

	private const HTTP_BAD_REQUEST           = 400;
	private const HTTP_FORBIDDEN             = 403;
	private const HTTP_INTERNAL_SERVER_ERROR = 500;

	/**
	 * Handle request from client.
	 */
	public function handle_request() {
		$nonce      = isset( $_REQUEST['wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wpnonce'] ) ) : '';
		$content_id = isset( $_REQUEST['contentId'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['contentId'] ) ) : '';
		$format     = 'text';

		// Note that technically the contentId is a string, but WordPress uses it as a number.
		if ( '' === $content_id || ! ctype_digit( $content_id ) || (int) $content_id <= 0 ) {
			wp_send_json_error( 'Invalid parameter contentId', self::HTTP_BAD_REQUEST );
		}

		if ( ! wp_verify_nonce( $nonce, 'ndlas_h5p_extractor' ) ) {
			wp_send_json_error( 'Invalid nonce', self::HTTP_FORBIDDEN );
		}

		$representations = $this->get_h5p_representations( $content_id, $format );

		if ( null === $representations ) {
			wp_send_json_error( 'Could not extract H5P content', self::HTTP_INTERNAL_SERVER_ERROR );
		} else {
			wp_send_json_success(
				array(
					'representation' => $representations['representation'],
					'solution'       => $representations['solution'],
				)
			);
		}
	}

	/**
	 * Get the H5P WordPress plugin instance.
	 *
	 * @return \H5P_Plugin|null
	 */
	private function get_h5p_plugin() {
		if ( ! class_exists( '\H5P_Plugin' ) ) {
			return null;
		}

		return \H5P_Plugin::get_instance();
	}

	/**
	 * Ensure the H5P export file exists.
	 *
	 * @param int $content_id ID of H5P content to ensure export for.
	 *
	 * @return callable Cleanup function to remove the export file if it had not existed before.
	 */
	private function ensure_h5p_export( $content_id ) {
		$plugin = $this->get_h5p_plugin();
		if ( null === $plugin ) {
			return static function () {};
		}

		$core    = $plugin->get_h5p_instance( 'core' );
		$content = $core->loadContent( $content_id );

		if ( ! is_array( $content ) ) {
			return static function () {};
		}

		$export_filename = ( $content['slug'] ? $content['slug'] . '-' : '' ) . $content['id'] . '.h5p';

		if ( $core->fs->hasExport( $export_filename ) ) {
			return static function () {};
		}

		if ( ! $this->create_h5p_export( $content ) ) {
			return static function () {};
		}

		return static function () use ( $content_id ) {
			$plugin          = \H5P_Plugin::get_instance();
			$core            = $plugin->get_h5p_instance( 'core' );
			$content         = $core->loadContent( $content_id );
			$export_filename = ( $content['slug'] ? $content['slug'] . '-' : '' ) . $content['id'] . '.h5p';
			$core->fs->deleteExport( $export_filename );
		};
	}

	/**
	 * Create H5P export file. Part of H5P core's filterParameters that we cannot
	 * call directly because the `h5p_export` option may be disabled.
	 *
	 * @param array $content Object with content data.
	 *
	 * @return bool Whether the export was created successfully.
	 */
	private function create_h5p_export( $content ) {
		if ( ! isset( $content['library'], $content['params'], $content['id'] ) ) {
			return false;
		}

		$plugin = $this->get_h5p_plugin();
		if ( null === $plugin ) {
			return false;
		}

		$core   = $plugin->get_h5p_instance( 'core' );
		$params = (object) array(
			'library' => $core->libraryToString( $content['library'] ),
			'params'  => json_decode( $content['params'] ),
		);

		if ( ! $params->params ) {
			return false;
		}

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $h5pF is defined by H5P core.
		$validator = new \H5PContentValidator( $core->h5pF, $core );
		$validator->validateLibrary( $params, (object) array( 'options' => array( $params->library ) ) );
		$this->apply_matching_addons( $core, $validator, $params->params );

		$filtered_params         = wp_json_encode( $params->params );
		$content['dependencies'] = $validator->getDependencies();
		$content['filtered']     = $filtered_params;
		$content['slug']         = $this->ensure_content_slug( $core, $content );

		$core->h5pF->deleteLibraryUsage( $content['id'] );
		$core->h5pF->saveLibraryUsage( $content['id'], $content['dependencies'] );

		$exporter = new \H5PExport( $core->h5pF, $core );
		$exporter->createExportFile( $content );

		$core->h5pF->updateContentFields(
			$content['id'],
			array(
				'filtered' => $filtered_params,
				'slug'     => $content['slug'],
			)
		);
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		return true;
	}

	/**
	 * Attach addons whose regex matches anywhere in the content params.
	 *
	 * @param \H5PCore             $core           H5P core instance.
	 * @param \H5PContentValidator $validator      Validator collecting addons.
	 * @param mixed                $content_params Decoded content params to scan.
	 */
	private function apply_matching_addons( $core, $validator, $content_params ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $h5pF is defined by H5P core.
		$addons = $core->h5pF->loadAddons();
		foreach ( $addons as $addon ) {
			$add_to = json_decode( $addon['addTo'] );

			if ( ! isset( $add_to->content->types ) ) {
				continue;
			}

			foreach ( $add_to->content->types as $type ) {
				if ( isset( $type->text->regex )
					&& $this->text_addon_matches( $content_params, $type->text->regex )
				) {
					$validator->addon( $addon );
					break;
				}
			}
		}
	}

	/**
	 * Determine if params contain any match for the pattern.
	 *
	 * @param mixed  $params  The parameters to search.
	 * @param string $pattern The pattern to match.
	 *
	 * @return bool Whether a match has been found.
	 */
	private function text_addon_matches( $params, $pattern ) {
		$type = gettype( $params );
		if ( 'string' === $type ) {
			return 1 === preg_match( $pattern, $params );
		}

		if ( 'array' === $type || 'object' === $type ) {
			foreach ( $params as $value ) {
				if ( $this->text_addon_matches( $value, $pattern ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Return the content's slug, generating and clearing the stale export if missing.
	 *
	 * @param \H5PCore $core    H5P core instance.
	 * @param array    $content Content data.
	 *
	 * @return string Existing or newly generated content slug.
	 */
	private function ensure_content_slug( $core, $content ) {
		if ( $content['slug'] ) {
			return $content['slug'];
		}

		$core->fs->deleteExport( $content['id'] . '.h5p' );
		return $this->generate_content_slug( $content );
	}

	/**
	 * Generate a unique content slug.
	 *
	 * @param array $content Object with content data.
	 *
	 * @return string Unique content slug.
	 */
	private function generate_content_slug( $content ) {
		$plugin = $this->get_h5p_plugin();
		$core   = $plugin->get_h5p_instance( 'core' );

		$slug      = \H5PCore::slugify( $content['title'] );
		$available = null;

		while ( ! $available ) {
			if ( false === $available ) {
				$matches = array();
				if ( preg_match( '/(.+-)([0-9]+)$/', $slug, $matches ) ) {
					$slug = $matches[1] . ( (int) $matches[2] + 1 );
				} else {
					$slug .= '-2';
				}
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $h5pF is defined by H5P core.
			$available = $core->h5pF->isContentSlugAvailable( $slug );
		}

		return $slug;
	}

	/**
	 * Get H5P representation.
	 *
	 * @param int    $content_id ID of H5P content to get representation of.
	 * @param string $format Output format ('text' here, but in theory also 'html').
	 *
	 * @return string|null Representation of H5P content or null on failure.
	 */
	private function get_h5p_representations( $content_id, $format = 'text' ) {
		$plugin = $this->get_h5p_plugin();
		if ( null === $plugin ) {
			return null;
		}

		if ( ! $this->load_extractor_class() ) {
			return null;
		}

		try {
			$export_cleanup_callback = $this->ensure_h5p_export( $content_id );
			$path                    = $this->resolve_export_path( $plugin, $content_id );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( null === $path || ! file_exists( $path ) ) {
			return null;
		}

		$result = $this->extract_representations( $path, $content_id, $format );

		if ( is_callable( $export_cleanup_callback ) ) {
			$export_cleanup_callback();
		}

		return $result;
	}

	/**
	 * Load the H5PExtractor class on demand.
	 *
	 * Loaded directly (skipping Composer's autoloader) because Composer would
	 * eagerly load vendor/h5p/h5p-core 1.28, conflicting with the H5P WordPress
	 * plugin's bundled (older) h5p-core. H5PExtractor only needs the global H5P
	 * core classes the WP plugin already provides, plus its own H5PExtractor\*
	 * autoloader, which it registers in its constructor.
	 *
	 * @return bool True if the class is available afterwards.
	 */
	private function load_extractor_class() {
		if ( class_exists( '\H5PExtractor\H5PExtractor' ) ) {
			return true;
		}

		$extractor_main = join(
			DIRECTORY_SEPARATOR,
			array( dirname( __DIR__ ), 'vendor', 'snordian', 'h5p-extractor', 'app', 'H5PExtractor.php' )
		);

		if ( ! file_exists( $extractor_main ) ) {
			return false;
		}

		require_once $extractor_main;
		return true;
	}

	/**
	 * Resolve the absolute path to the H5P export file for given content.
	 *
	 * @param \H5P_Plugin $plugin     H5P plugin instance.
	 * @param int         $content_id ID of H5P content.
	 *
	 * @return string|null Path to the .h5p export, or null if content is unknown.
	 */
	private function resolve_export_path( $plugin, $content_id ) {
		$content = $plugin->get_content( $content_id );
		if ( ! is_array( $content ) ) {
			return null;
		}

		return wp_upload_dir()['basedir'] . DIRECTORY_SEPARATOR .
			'h5p' . DIRECTORY_SEPARATOR .
			'exports' . DIRECTORY_SEPARATOR .
			( $content['slug'] ? $content['slug'] . '-' : '' ) .
			$content['id'] . '.h5p';
	}

	/**
	 * Run the H5P extractor against the given export file.
	 *
	 * @param string $path       Absolute path to the .h5p export file.
	 * @param int    $content_id ID of H5P content (used to build URLs).
	 * @param string $format     Output format ('text' or 'html').
	 *
	 * @return string|null The extracted representation, or null on failure.
	 */
	private function extract_representations( $path, $content_id, $format ) {
		$uploads = wp_upload_dir();
		$config  = array(
			'uploadsPath'     => $uploads['basedir'],
			'h5pContentUrl'   => $uploads['baseurl'] . '/h5p/content/' . $content_id . '/',
			'h5pCoreUrl'      => plugins_url() . '/h5p/h5p-php-library/',
			'h5pLibrariesUrl' => $uploads['baseurl'] . '/h5p/libraries/',
		);

		try {
			$extractor    = new \H5PExtractor\H5PExtractor( $config );
			$extract_args = array(
				'file'   => $path,
				'format' => $format,
			);

			if ( isset( $config['solution'] ) && is_bool( $config['solution'] ) ) {
				$extract_args['solution'] = $config['solution'];
			}

			$extract = $extractor->extract( $extract_args );
		} catch ( \Throwable $e ) {
			// This is meant to be written to the logfile.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $e->getMessage() );
			return null;
		}

		if ( ! isset( $extract['result'] )
			|| str_contains( (string) $extract['result'], 'No HTML renderer for' )
		) {
			return null;
		}

		return array(
			'representation' => $extract['result'],
			'solution'       => $extract['solution'],
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
	 * Render the dashboard sidebar content.
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
			if ( ! isset( $attributes['id'] ) ) {
				// TODO: If H5P ever supports using slugs instead of ids, we'll need to adjust this.
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

	/**
	 * Constructor.
	 */
	public function __construct() {
		new Options();

		// Defer H5P detection to a later hook when post is available.
		add_action( 'wp', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_dashboard_sidebar' ) );

		add_action( 'wp_ajax_ndlas_h5p_extractor_extract', array( $this, 'handle_request' ) );
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
					'extract' => __( 'Extract', 'ndlas_h5p_extractor' ),
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
}
