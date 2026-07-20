<?php
/**
 * GitHub Releases updater for the public VibeCodeRacing/VibeShift-EU repo.
 *
 * Uses the plugin header `Update URI` (hostname github.com) and the
 * `update_plugins_github.com` filter (WP 5.8+) so dashboard update checks
 * discover new versions published as GitHub Release ZIP assets.
 *
 * Expected release asset: vibeshift-eu-shipping-{version}.zip (preferred)
 * or vibeshift-eu-shipping.zip, with root folder vibeshift-eu-shipping/.
 * The repository is public, so release checks are unauthenticated and
 * downloads use the asset's browser_download_url — no token, ever.
 *
 * @package vibeshift-eu-shipping
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Vibeshift_GitHub_Updater
 */
class Vibeshift_GitHub_Updater {

	/**
	 * GitHub owner/repo.
	 *
	 * @var string
	 */
	const REPO = 'VibeCodeRacing/VibeShift-EU';

	/**
	 * Transient cache key for the latest release payload.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'vibeshift_github_release';

	/**
	 * Cache TTL in seconds (12 hours).
	 *
	 * @var int
	 */
	const CACHE_TTL = 43200;

	/**
	 * Plugin slug (directory name).
	 *
	 * @var string
	 */
	const SLUG = 'vibeshift-eu-shipping';

	/**
	 * Option recording the outcome of the last real release check (not autoloaded).
	 *
	 * @var string
	 */
	const STATUS_OPTION = 'vibeshift_update_status';

	/**
	 * Register hooks.
	 *
	 * @since 1.2.0
	 */
	public static function init() {
		// Hostname comes from Update URI: https://github.com/...
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );

		// Clear cached release data after a successful plugin upgrade.
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );

		// Update-source status: Plugins-row indicator and failure notice (admin only).
		add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_admin_notice' ) );
	}

	/**
	 * Headers for GitHub API requests (unauthenticated; public repo).
	 *
	 * @since  1.2.0
	 * @return array
	 */
	private static function api_headers() {
		return array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'VibeShift-EU-Shipping-WordPress/' . WC_EORI_VAT_VERSION,
		);
	}

	/**
	 * Provide update data for this plugin only.
	 *
	 * @since  1.2.0
	 * @param  array|false $update      Default false.
	 * @param  array       $plugin_data Plugin headers.
	 * @param  string      $plugin_file Plugin basename.
	 * @param  string[]    $locales     Installed locales.
	 * @return array|false
	 */
	public static function filter_update( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $locales );

		if ( WC_EORI_VAT_PLUGIN_BASENAME !== $plugin_file ) {
			return $update;
		}

		$remote = self::get_latest_release();
		if ( empty( $remote['version'] ) || empty( $remote['package'] ) ) {
			return $update;
		}

		$current = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : WC_EORI_VAT_VERSION;
		if ( ! version_compare( $remote['version'], $current, '>' ) ) {
			return $update;
		}

		return array(
			'id'           => 'https://github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'version'      => $remote['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $remote['package'],
			'tested'       => isset( $plugin_data['Tested up to'] ) ? $plugin_data['Tested up to'] : '',
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '7.4',
			'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '6.8',
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
			'translations' => array(),
		);
	}

	/**
	 * "View version details" modal content for the Plugins screen.
	 *
	 * @since  1.2.0
	 * @param  false|object|array $result Existing result.
	 * @param  string             $action API action.
	 * @param  object             $args   Request args.
	 * @return false|object|array
	 */
	public static function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$remote = self::get_latest_release();
		if ( empty( $remote['version'] ) ) {
			return $result;
		}

		$sections = array(
			'description' => '<p>' . esc_html__( 'Collect and validate EORI numbers and EU VAT numbers during WooCommerce checkout. Updates are delivered from GitHub Releases.', 'vibeshift-eu-shipping' ) . '</p>',
			'changelog'   => ! empty( $remote['changelog'] )
				? wp_kses_post( wpautop( $remote['changelog'] ) )
				: '<p>' . esc_html__( 'See the GitHub release notes for this version.', 'vibeshift-eu-shipping' ) . '</p>',
		);

		return (object) array(
			'name'          => 'VibeShift EU Shipping',
			'slug'          => self::SLUG,
			'version'       => $remote['version'],
			'author'        => '<a href="https://VibeCodeRacing.ai">Vibe Code Racing</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.8',
			'requires_php'  => '7.4',
			'tested'        => '7.0',
			'download_link' => isset( $remote['package'] ) ? $remote['package'] : '',
			'trunk'         => 'https://github.com/' . self::REPO,
			'last_updated'  => isset( $remote['published_at'] ) ? $remote['published_at'] : '',
			'sections'      => $sections,
			'banners'       => array(),
			'icons'         => array(),
			'external'      => true,
		);
	}

	/**
	 * Drop the release cache after this plugin is updated.
	 *
	 * @since 1.2.0
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Upgrade options.
	 */
	public static function after_upgrade( $upgrader, $options ) {
		unset( $upgrader );

		if ( empty( $options['action'] ) || 'update' !== $options['action'] ) {
			return;
		}
		if ( empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}

		$plugins = array();
		if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			$plugins = $options['plugins'];
		} elseif ( ! empty( $options['plugin'] ) ) {
			$plugins = array( $options['plugin'] );
		}

		if ( in_array( WC_EORI_VAT_PLUGIN_BASENAME, $plugins, true ) ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Fetch and cache the latest non-prerelease GitHub release with a ZIP asset.
	 *
	 * @since  1.2.0
	 * @return array {
	 *     @type string $version      Semver without leading v.
	 *     @type string $package      Public download URL for the ZIP asset.
	 *     @type string $changelog    Release body markdown/text.
	 *     @type string $published_at ISO8601 publish time.
	 * }
	 */
	public static function get_latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
			return $cached;
		}

		$url = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => self::api_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::record_status( 'network_error', $response->get_error_message() );
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			self::record_status( 'http_' . $code );
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			self::record_status( 'bad_payload' );
			return array();
		}

		// Skip drafts / prereleases (API latest usually excludes them; belt-and-suspenders).
		if ( ! empty( $body['draft'] ) || ! empty( $body['prerelease'] ) ) {
			self::record_status( 'no_release' );
			return array();
		}

		$tag     = isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '';
		$version = ltrim( $tag, 'vV' );
		if ( '' === $version || ! preg_match( '/^\d+\.\d+\.\d+/', $version ) ) {
			self::record_status( 'bad_tag', $tag );
			return array();
		}

		$package = self::find_zip_asset( $body, $version );
		if ( '' === $package ) {
			self::record_status( 'no_asset', $version );
			return array();
		}

		$payload = array(
			'version'      => $version,
			'package'      => $package,
			'changelog'    => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published_at' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);

		set_transient( self::CACHE_KEY, $payload, self::CACHE_TTL );
		self::record_status( 'ok', '', $version );

		return $payload;
	}

	/**
	 * Record the outcome of the last real release check (cache hits are not recorded).
	 *
	 * @since 1.2.0
	 * @param string $result  'ok', 'http_<code>', 'network_error', 'bad_payload', 'no_release', 'bad_tag', or 'no_asset'.
	 * @param string $detail  Optional extra context (error message, tag).
	 * @param string $version Latest version found, when $result is 'ok'.
	 */
	private static function record_status( $result, $detail = '', $version = '' ) {
		update_option(
			self::STATUS_OPTION,
			array(
				'time'    => time(),
				'result'  => $result,
				'detail'  => $detail,
				'version' => $version,
			),
			false
		);
	}

	/**
	 * Append an update-source status line to this plugin's row on the Plugins screen.
	 *
	 * @since  1.2.0
	 * @param  string[] $plugin_meta Row meta links/text.
	 * @param  string   $plugin_file Plugin basename being rendered.
	 * @return string[]
	 */
	public static function plugin_row_meta( $plugin_meta, $plugin_file ) {
		if ( WC_EORI_VAT_PLUGIN_BASENAME !== $plugin_file ) {
			return $plugin_meta;
		}

		$status = get_option( self::STATUS_OPTION );

		if ( ! is_array( $status ) || empty( $status['result'] ) ) {
			$text  = __( 'Updates: GitHub (no check yet)', 'vibeshift-eu-shipping' );
			$color = '#996800';
		} elseif ( 'ok' === $status['result'] ) {
			$text  = sprintf(
				/* translators: 1: latest available version, 2: human-readable time since last check. */
				__( 'Updates: GitHub ✓ latest %1$s, checked %2$s ago', 'vibeshift-eu-shipping' ),
				$status['version'],
				human_time_diff( (int) $status['time'] )
			);
			$color = '#008a20';
		} else {
			$text  = sprintf(
				/* translators: 1: failure code, 2: human-readable time since last check. */
				__( 'Updates: GitHub — check failing (%1$s, %2$s ago)', 'vibeshift-eu-shipping' ),
				$status['result'],
				human_time_diff( (int) $status['time'] )
			);
			$color = '#b32d2e';
		}

		$plugin_meta[] = '<span style="color:' . esc_attr( $color ) . ';">' . esc_html( $text ) . '</span>';

		return $plugin_meta;
	}

	/**
	 * Warn on the Plugins screen when the release check is failing.
	 *
	 * @since 1.2.0
	 */
	public static function maybe_admin_notice() {
		global $pagenow;

		if ( 'plugins.php' !== $pagenow || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$status = get_option( self::STATUS_OPTION );

		// Healthy or not yet checked: nothing to warn about.
		if ( ! is_array( $status ) || empty( $status['result'] ) || 'ok' === $status['result'] ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: failure code from the last release check. */
			__( 'VibeShift EU Shipping: the GitHub update check is failing (%s). Updates may be delayed — check the repository releases page.', 'vibeshift-eu-shipping' ),
			$status['result']
		);

		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Pick the release ZIP asset URL.
	 *
	 * Preference order (case-insensitive): vibeshift-eu-shipping-{version}.zip,
	 * vibeshift-eu-shipping.zip, any vibeshift-eu-shipping*.zip, lone zip asset.
	 *
	 * @since  1.2.0
	 * @param  array  $release GitHub release JSON as array.
	 * @param  string $version Normalized version string.
	 * @return string Public download URL, or '' when no usable asset exists.
	 */
	private static function find_zip_asset( $release, $version ) {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return '';
		}

		$by_name = array();
		foreach ( $release['assets'] as $asset ) {
			if ( empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
				continue;
			}
			$name = (string) $asset['name'];
			if ( ! preg_match( '/\.zip$/i', $name ) ) {
				continue;
			}
			$by_name[ strtolower( $name ) ] = (string) $asset['browser_download_url'];
		}

		$preferred = array(
			self::SLUG . '-' . $version . '.zip',
			self::SLUG . '.zip',
		);

		foreach ( $preferred as $want ) {
			$key = strtolower( $want );
			if ( isset( $by_name[ $key ] ) ) {
				return $by_name[ $key ];
			}
		}

		// Any vibeshift-eu-shipping*.zip asset.
		foreach ( $by_name as $name => $url ) {
			if ( 0 === strpos( $name, strtolower( self::SLUG ) ) ) {
				return $url;
			}
		}

		// Last resort: single zip asset on the release.
		if ( 1 === count( $by_name ) ) {
			return reset( $by_name );
		}

		return '';
	}
}
