# GitHub Releases Auto-Update Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** WordPress installs of VibeShift EU Shipping discover and install new GitHub Releases from the public `VibeCodeRacing/VibeShift-EU` repo; release zips are built by CI on tag push.

**Architecture:** A static updater class hooks WordPress's `update_plugins_github.com` filter (driven by a new `Update URI` plugin header) and offers the latest GitHub Release when it beats the installed version, with a 12-hour transient cache and a status indicator on the Plugins screen. A tag-triggered GitHub Actions workflow verifies version consistency and publishes the installable zip. Spec: `docs/specs/2026-07-20-github-auto-update-design.md`.

**Tech Stack:** WordPress plugin PHP (7.4+ compatible), standalone stub-based CLI tests (`php tests/<file>.php`, repo convention), GitHub Actions.

## Global Constraints

- Plugin lives in the `vibeshift-eu-shipping/` subdirectory of the repo; repo root is not the plugin root.
- New version everywhere: `1.2.0` (header `Version:`, `WC_EORI_VAT_VERSION`, readme.txt `Stable tag`, README.md, CHANGELOG.md).
- Release asset names the updater accepts, in preference order: `vibeshift-eu-shipping-{version}.zip`, `vibeshift-eu-shipping.zip`, any `vibeshift-eu-shipping*.zip`, lone zip. Case-insensitive.
- No token/auth code anywhere — the repo is public; downloads use `browser_download_url`.
- Text domain for all user-facing strings: `vibeshift-eu-shipping`.
- PHP lint with `/opt/homebrew/bin/php -l`; tests run with `php vibeshift-eu-shipping/tests/<file>.php` from the repo root.
- Commit style (repo convention): imperative summary, no prefix required; end with the Claude Code trailer used in prior session commits.

---

### Task 1: Updater class with stub-based tests

**Files:**
- Create: `vibeshift-eu-shipping/tests/github-updater-test.php`
- Create: `vibeshift-eu-shipping/includes/class-vibeshift-github-updater.php`

**Interfaces:**
- Consumes: constants `WC_EORI_VAT_VERSION` (string) and `WC_EORI_VAT_PLUGIN_BASENAME` (string) — defined by the test harness here, by the bootstrap in Task 2.
- Produces: class `Vibeshift_GitHub_Updater` with public static methods `init()`, `filter_update( $update, $plugin_data, $plugin_file, $locales )`, `plugins_api( $result, $action, $args )`, `after_upgrade( $upgrader, $options )`, `get_latest_release()`, `plugin_row_meta( $plugin_meta, $plugin_file )`, `maybe_admin_notice()`. Option name `vibeshift_update_status`, transient `vibeshift_github_release`.

- [ ] **Step 1: Write the failing test**

Create `vibeshift-eu-shipping/tests/github-updater-test.php`:

```php
<?php
/**
 * Standalone tests for the GitHub Releases updater.
 *
 * Stubs the WordPress functions the updater touches and feeds it canned
 * GitHub API payloads. No network access.
 *
 * Run: php tests/github-updater-test.php
 *
 * @package vibeshift-eu-shipping
 */

// Block direct web access while still allowing the CLI test runner to bootstrap.
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WC_EORI_VAT_VERSION' ) ) {
	define( 'WC_EORI_VAT_VERSION', '1.1.2' );
}
if ( ! defined( 'WC_EORI_VAT_PLUGIN_BASENAME' ) ) {
	define( 'WC_EORI_VAT_PLUGIN_BASENAME', 'vibeshift-eu-shipping/vibeshift-eu-shipping.php' );
}

// ---- WordPress stubs -------------------------------------------------------

$GLOBALS['vst_transients'] = array();
$GLOBALS['vst_options']    = array();
$GLOBALS['vst_http_queue'] = array();
$GLOBALS['vst_http_calls'] = 0;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $message;
		public function __construct( $code = '', $message = '' ) {
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {}
function add_action( $tag, $callback, $priority = 10, $args = 1 ) {}
function get_transient( $key ) {
	return isset( $GLOBALS['vst_transients'][ $key ] ) ? $GLOBALS['vst_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['vst_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['vst_transients'][ $key ] );
	return true;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['vst_options'][ $key ] = $value;
	return true;
}
function get_option( $key, $default = false ) {
	return isset( $GLOBALS['vst_options'][ $key ] ) ? $GLOBALS['vst_options'][ $key ] : $default;
}
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['vst_http_calls']++;
	if ( empty( $GLOBALS['vst_http_queue'] ) ) {
		return new WP_Error( 'no_mock', 'No mocked response queued for ' . $url );
	}
	return array_shift( $GLOBALS['vst_http_queue'] );
}
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['code'] ) ? $response['code'] : 0;
}
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}
function __( $text, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function esc_html( $text ) {
	return $text;
}
function esc_attr( $text ) {
	return $text;
}
function wp_kses_post( $text ) {
	return $text;
}
function wpautop( $text ) {
	return $text;
}
function human_time_diff( $from, $to = 0 ) {
	return '1 min';
}
function current_user_can( $cap ) {
	return true;
}

require dirname( __DIR__ ) . '/includes/class-vibeshift-github-updater.php';

// ---- helpers ---------------------------------------------------------------

$vst_failures = 0;

function vst_check( $label, $condition ) {
	global $vst_failures;
	if ( $condition ) {
		echo "PASS  {$label}\n";
	} else {
		$vst_failures++;
		echo "FAIL  {$label}\n";
	}
}

function vst_reset() {
	$GLOBALS['vst_transients'] = array();
	$GLOBALS['vst_options']    = array();
	$GLOBALS['vst_http_queue'] = array();
	$GLOBALS['vst_http_calls'] = 0;
}

function vst_release( $tag, $assets, $extra = array() ) {
	$body = array_merge(
		array(
			'tag_name'     => $tag,
			'draft'        => false,
			'prerelease'   => false,
			'body'         => 'Release notes.',
			'published_at' => '2026-07-20T00:00:00Z',
			'assets'       => $assets,
		),
		$extra
	);
	return array(
		'code' => 200,
		'body' => json_encode( $body ),
	);
}

function vst_asset( $name ) {
	return array(
		'name'                 => $name,
		'browser_download_url' => 'https://github.com/VibeCodeRacing/VibeShift-EU/releases/download/x/' . $name,
	);
}

function vst_status() {
	return get_option( Vibeshift_GitHub_Updater::STATUS_OPTION );
}

// ---- tests -----------------------------------------------------------------

// 1. Versioned asset preferred over unversioned.
vst_reset();
$GLOBALS['vst_http_queue'][] = vst_release(
	'v1.2.0',
	array( vst_asset( 'vibeshift-eu-shipping.zip' ), vst_asset( 'vibeshift-eu-shipping-1.2.0.zip' ) )
);
$release = Vibeshift_GitHub_Updater::get_latest_release();
vst_check( 'prefers versioned asset name', false !== strpos( $release['package'], 'vibeshift-eu-shipping-1.2.0.zip' ) );
vst_check( 'parses version from tag', '1.2.0' === $release['version'] );
$status = vst_status();
vst_check( 'records ok status', 'ok' === $status['result'] && '1.2.0' === $status['version'] );

// 2. Falls back to unversioned asset name (current releases use it).
vst_reset();
$GLOBALS['vst_http_queue'][] = vst_release( 'v1.2.0', array( vst_asset( 'vibeshift-eu-shipping.zip' ) ) );
$release = Vibeshift_GitHub_Updater::get_latest_release();
vst_check( 'accepts unversioned asset name', false !== strpos( $release['package'], 'vibeshift-eu-shipping.zip' ) );

// 3. Successful check is cached: second call makes no HTTP request.
$calls_before = $GLOBALS['vst_http_calls'];
$again        = Vibeshift_GitHub_Updater::get_latest_release();
vst_check( 'second call served from cache', $GLOBALS['vst_http_calls'] === $calls_before && '1.2.0' === $again['version'] );

// 4. HTTP 404 -> empty result, http_404 status, nothing cached.
vst_reset();
$GLOBALS['vst_http_queue'][] = array( 'code' => 404, 'body' => '{"message":"Not Found"}' );
$release = Vibeshift_GitHub_Updater::get_latest_release();
$status  = vst_status();
vst_check( '404 returns empty', array() === $release );
vst_check( '404 recorded as http_404', 'http_404' === $status['result'] );
vst_check( '404 not cached', empty( $GLOBALS['vst_transients'] ) );

// 5. Network error -> empty result, network_error status.
vst_reset();
$release = Vibeshift_GitHub_Updater::get_latest_release(); // empty queue => WP_Error
$status  = vst_status();
vst_check( 'network error returns empty', array() === $release );
vst_check( 'network error recorded', 'network_error' === $status['result'] );

// 6. Prerelease skipped.
vst_reset();
$GLOBALS['vst_http_queue'][] = vst_release( 'v9.9.9', array( vst_asset( 'vibeshift-eu-shipping.zip' ) ), array( 'prerelease' => true ) );
$release = Vibeshift_GitHub_Updater::get_latest_release();
$status  = vst_status();
vst_check( 'prerelease skipped', array() === $release && 'no_release' === $status['result'] );

// 7. Non-semver tag skipped.
vst_reset();
$GLOBALS['vst_http_queue'][] = vst_release( 'latest-build', array( vst_asset( 'vibeshift-eu-shipping.zip' ) ) );
$release = Vibeshift_GitHub_Updater::get_latest_release();
$status  = vst_status();
vst_check( 'bad tag skipped', array() === $release && 'bad_tag' === $status['result'] );

// 8. Release without a zip asset skipped.
vst_reset();
$GLOBALS['vst_http_queue'][] = vst_release( 'v1.2.0', array() );
$release = Vibeshift_GitHub_Updater::get_latest_release();
$status  = vst_status();
vst_check( 'missing asset skipped', array() === $release && 'no_asset' === $status['result'] );

// 9. filter_update offers a newer release for this plugin.
vst_reset();
$GLOBALS['vst_http_queue'][] = vst_release( 'v1.2.0', array( vst_asset( 'vibeshift-eu-shipping-1.2.0.zip' ) ) );
$update = Vibeshift_GitHub_Updater::filter_update( false, array( 'Version' => '1.1.2' ), WC_EORI_VAT_PLUGIN_BASENAME, array() );
vst_check( 'offers newer version', is_array( $update ) && '1.2.0' === $update['version'] );
vst_check( 'offer targets the release asset', false !== strpos( $update['package'], 'vibeshift-eu-shipping-1.2.0.zip' ) );
vst_check( 'offer uses plugin slug', 'vibeshift-eu-shipping' === $update['slug'] );

// 10. filter_update declines when installed version is equal.
$update = Vibeshift_GitHub_Updater::filter_update( false, array( 'Version' => '1.2.0' ), WC_EORI_VAT_PLUGIN_BASENAME, array() );
vst_check( 'no offer for equal version', false === $update );

// 11. filter_update ignores other plugins.
$update = Vibeshift_GitHub_Updater::filter_update( false, array( 'Version' => '0.0.1' ), 'other-plugin/other-plugin.php', array() );
vst_check( 'ignores other plugin basenames', false === $update );

// ---- summary ---------------------------------------------------------------

echo $vst_failures ? "\n{$vst_failures} FAILED\n" : "\nALL PASS\n";
exit( $vst_failures ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/macmini-2024/Code/Dev_Web/VibeShift-EU && php vibeshift-eu-shipping/tests/github-updater-test.php`
Expected: PHP fatal error — `class-vibeshift-github-updater.php` does not exist yet.

- [ ] **Step 3: Write the updater class**

Create `vibeshift-eu-shipping/includes/class-vibeshift-github-updater.php`:

```php
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
			'author'        => '<a href="https://vibecoderacing.com">Vibe Code Racing</a>',
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vibeshift-eu-shipping/tests/github-updater-test.php`
Expected: every line `PASS`, final line `ALL PASS`, exit code 0.

- [ ] **Step 5: Lint both files**

Run: `php -l vibeshift-eu-shipping/includes/class-vibeshift-github-updater.php && php -l vibeshift-eu-shipping/tests/github-updater-test.php`
Expected: `No syntax errors detected` twice.

- [ ] **Step 6: Commit**

```bash
git add vibeshift-eu-shipping/includes/class-vibeshift-github-updater.php vibeshift-eu-shipping/tests/github-updater-test.php
git commit -m "Add GitHub Releases updater class with stub-based tests"
```

---

### Task 2: Bootstrap integration and version bump to 1.2.0

**Files:**
- Modify: `vibeshift-eu-shipping/vibeshift-eu-shipping.php` (header block lines 1–19, constants block lines 25–27, and the require near the end)

**Interfaces:**
- Consumes: `Vibeshift_GitHub_Updater::init()` from Task 1.
- Produces: constants `WC_EORI_VAT_PLUGIN_BASENAME` and `WC_EORI_VAT_VERSION` = `'1.2.0'`; `Update URI` header WordPress uses to route the `update_plugins_github.com` filter.

- [ ] **Step 1: Add the Update URI header and bump the header version**

In the plugin header comment, change:

```php
 * Version: 1.1.2
```

to:

```php
 * Version: 1.2.0
 * Update URI: https://github.com/VibeCodeRacing/VibeShift-EU
```

- [ ] **Step 2: Bump the version constant and add the basename constant**

Change:

```php
define( 'WC_EORI_VAT_VERSION', '1.1.2' );
define( 'WC_EORI_VAT_FILE', __FILE__ );
define( 'WC_EORI_VAT_ABSPATH', __DIR__ . '/' );
```

to:

```php
define( 'WC_EORI_VAT_VERSION', '1.2.0' );
define( 'WC_EORI_VAT_FILE', __FILE__ );
define( 'WC_EORI_VAT_ABSPATH', __DIR__ . '/' );
define( 'WC_EORI_VAT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
```

- [ ] **Step 3: Load and init the updater outside the WooCommerce gating**

Immediately after the constants block added in Step 2 (before the `WC_EORI_VAT_Number_Init` class definition), add:

```php
// GitHub Releases updater: must run even when WooCommerce dependency
// checks fail, so updates can deliver fixes to a broken install.
require_once __DIR__ . '/includes/class-vibeshift-github-updater.php';
Vibeshift_GitHub_Updater::init();
```

- [ ] **Step 4: Lint and run both test suites**

Run: `php -l vibeshift-eu-shipping/vibeshift-eu-shipping.php && php vibeshift-eu-shipping/tests/github-updater-test.php && php vibeshift-eu-shipping/tests/merged-plugin-test.php`
Expected: no syntax errors; both suites end with their all-pass summary and exit 0.

- [ ] **Step 5: Commit**

```bash
git add vibeshift-eu-shipping/vibeshift-eu-shipping.php
git commit -m "Wire GitHub updater into bootstrap; bump version to 1.2.0"
```

---

### Task 3: Release metadata — readme.txt, README.md, CHANGELOG.md

**Files:**
- Modify: `vibeshift-eu-shipping/readme.txt` (line 7 `Stable tag`, changelog section at line 37)
- Modify: `README.md` (line 6 version)
- Modify: `CHANGELOG.md` (new entry above `## 1.1.2 - 2026-07-13`)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: version metadata the Task 4 workflow verifies against the tag (`Stable tag: 1.2.0`).

- [ ] **Step 1: Update readme.txt stable tag and changelog**

Change `Stable tag: 1.1.2` to `Stable tag: 1.2.0`. Under `== Changelog ==`, above the `= 1.1.2 =` entry, add:

```
= 1.2.0 =
* Add: automatic updates from GitHub Releases. The plugin now discovers new versions published on the public VibeCodeRacing/VibeShift-EU repository and offers them on the WordPress Plugins and Updates screens (one-click update and the per-plugin auto-update toggle both work). An update-source status line on the Plugins row shows the last check result.
```

- [ ] **Step 2: Update README.md version line**

Change `- **Version:** 1.1.2` to `- **Version:** 1.2.0`.

- [ ] **Step 3: Add CHANGELOG.md entry**

Above `## 1.1.2 - 2026-07-13`, add:

```markdown
## 1.2.0 - 2026-07-20

- Added automatic updates from GitHub Releases: a new `Update URI` header plus an in-plugin updater (`includes/class-vibeshift-github-updater.php`) let WordPress discover release zips published on the public repo. Release checks are cached for 12 hours; a status line on the Plugins screen shows the last check result, with an admin notice when checks fail.
- Added a tag-triggered GitHub Actions release workflow that verifies version consistency (plugin header, `WC_EORI_VAT_VERSION`, readme stable tag) and builds/attaches the installable zip, replacing the hand-built release asset.
```

- [ ] **Step 4: Commit**

```bash
git add vibeshift-eu-shipping/readme.txt README.md CHANGELOG.md
git commit -m "Release metadata for 1.2.0 (readme stable tag, README, changelog)"
```

---

### Task 4: Release workflow and repo-root zip cleanup

**Files:**
- Create: `.github/workflows/release.yml`
- Create: `.gitignore`
- Delete: `vibeshift-eu-shipping.zip` (repo root)

**Interfaces:**
- Consumes: version locations from Tasks 2–3 (plugin header `Version:`, `WC_EORI_VAT_VERSION`, readme.txt `Stable tag`).
- Produces: on tag push, a GitHub Release with asset `vibeshift-eu-shipping-{version}.zip` whose root folder is `vibeshift-eu-shipping/` — the layout `Vibeshift_GitHub_Updater::find_zip_asset()` prefers.

- [ ] **Step 1: Write the workflow**

Create `.github/workflows/release.yml`:

```yaml
# Build a clean installable ZIP and attach it to a GitHub Release when a version tag is pushed.
#
# Tags accepted: v1.2.0 or 1.2.0 (semver). The tag (without leading v) must match:
#   - vibeshift-eu-shipping/vibeshift-eu-shipping.php header Version
#   - define( 'WC_EORI_VAT_VERSION', ... )
#   - vibeshift-eu-shipping/readme.txt Stable tag
#
# Asset name: vibeshift-eu-shipping-{version}.zip with root folder vibeshift-eu-shipping/
# (WordPress upload layout). The in-plugin GitHub updater
# (vibeshift-eu-shipping/includes/class-vibeshift-github-updater.php) looks for this asset.

name: Release

on:
  push:
    tags:
      - "v[0-9]+.[0-9]+.[0-9]+"
      - "[0-9]+.[0-9]+.[0-9]+"

permissions:
  contents: write

jobs:
  release:
    name: Build ZIP and publish GitHub Release
    runs-on: ubuntu-latest

    steps:
      - name: Check out repository
        uses: actions/checkout@v4

      - name: Resolve version from tag
        id: ver
        run: |
          RAW="${GITHUB_REF_NAME}"
          VERSION="${RAW#v}"
          if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
            echo "Tag must be semver (optional leading v): got ${RAW}"
            exit 1
          fi
          echo "version=${VERSION}" >> "$GITHUB_OUTPUT"
          echo "tag=${RAW}" >> "$GITHUB_OUTPUT"
          echo "Resolved version: ${VERSION}"

      - name: Verify version numbers match in tree
        run: |
          set -euo pipefail
          VERSION="${{ steps.ver.outputs.version }}"

          HEADER=$(grep -E '^\s*\* Version:' vibeshift-eu-shipping/vibeshift-eu-shipping.php | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)
          DEFINE=$(grep -E "define\(\s*'WC_EORI_VAT_VERSION'" vibeshift-eu-shipping/vibeshift-eu-shipping.php | head -1 | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" || true)
          STABLE=$(grep -E '^Stable tag:' vibeshift-eu-shipping/readme.txt | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)

          echo "tag version:         ${VERSION}"
          echo "header Version:      ${HEADER}"
          echo "WC_EORI_VAT_VERSION: ${DEFINE}"
          echo "Stable tag:          ${STABLE}"

          fail=0
          for pair in "header:${HEADER}" "define:${DEFINE}" "stable:${STABLE}"; do
            name=${pair%%:*}
            val=${pair#*:}
            if [ "$val" != "$VERSION" ]; then
              echo "Mismatch: ${name}=${val} expected ${VERSION}"
              fail=1
            fi
          done
          if [ "$fail" -ne 0 ]; then
            exit 1
          fi

      - name: Build distribution ZIP
        run: |
          set -euo pipefail
          VERSION="${{ steps.ver.outputs.version }}"
          STAGE="${RUNNER_TEMP}/vibeshift-dist"
          OUT="${GITHUB_WORKSPACE}/vibeshift-eu-shipping-${VERSION}.zip"

          rm -rf "${STAGE}"
          mkdir -p "${STAGE}"

          # The plugin lives in the vibeshift-eu-shipping/ subdirectory; ship it
          # minus dev-only tests/.
          rsync -a --exclude='tests' vibeshift-eu-shipping/ "${STAGE}/vibeshift-eu-shipping/"

          # Sanity: bootstrap file and updater present, tests excluded.
          test -f "${STAGE}/vibeshift-eu-shipping/vibeshift-eu-shipping.php"
          test -f "${STAGE}/vibeshift-eu-shipping/includes/class-vibeshift-github-updater.php"
          test ! -e "${STAGE}/vibeshift-eu-shipping/tests"

          (cd "${STAGE}" && zip -rq "${OUT}" vibeshift-eu-shipping)

          echo "Built $(ls -lh "${OUT}" | awk '{print $5}') → ${OUT}"
          unzip -l "${OUT}" | head -25

      - name: Create GitHub Release with asset
        uses: softprops/action-gh-release@v2
        with:
          tag_name: ${{ steps.ver.outputs.tag }}
          name: VibeShift EU Shipping ${{ steps.ver.outputs.version }}
          generate_release_notes: true
          files: vibeshift-eu-shipping-${{ steps.ver.outputs.version }}.zip
          fail_on_unmatched_files: true
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

- [ ] **Step 2: Remove the hand-built zip and ignore future ones**

```bash
git rm vibeshift-eu-shipping.zip
printf '*.zip\n.DS_Store\n' > .gitignore
```

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/release.yml .gitignore
git commit -m "Add tag-triggered release workflow; drop hand-built zip from repo"
```

---

### Task 5: Local verification (no commit)

**Files:** none changed — verification only.

**Interfaces:**
- Consumes: everything from Tasks 1–4.

- [ ] **Step 1: Run the full test suite and lint every touched PHP file**

Run:
```bash
php vibeshift-eu-shipping/tests/github-updater-test.php
php vibeshift-eu-shipping/tests/merged-plugin-test.php
php -l vibeshift-eu-shipping/vibeshift-eu-shipping.php
php -l vibeshift-eu-shipping/includes/class-vibeshift-github-updater.php
```
Expected: both suites all-pass; no syntax errors.

- [ ] **Step 2: Build the zip locally exactly as the workflow does**

```bash
STAGE=$(mktemp -d)
rsync -a --exclude='tests' vibeshift-eu-shipping/ "${STAGE}/vibeshift-eu-shipping/"
test -f "${STAGE}/vibeshift-eu-shipping/includes/class-vibeshift-github-updater.php" && echo updater-present
test ! -e "${STAGE}/vibeshift-eu-shipping/tests" && echo tests-excluded
(cd "${STAGE}" && zip -rq /tmp/vibeshift-eu-shipping-1.2.0.zip vibeshift-eu-shipping)
unzip -l /tmp/vibeshift-eu-shipping-1.2.0.zip | head -15
rm -rf "${STAGE}"
```
Expected: `updater-present`, `tests-excluded`, and a zip listing whose every path starts with `vibeshift-eu-shipping/`.

- [ ] **Step 3: Confirm the header parses the way WordPress will read it**

Run: `grep -A1 "Version: 1.2.0" vibeshift-eu-shipping/vibeshift-eu-shipping.php | grep "Update URI"`
Expected: the `Update URI: https://github.com/VibeCodeRacing/VibeShift-EU` line.

---

### Task 6: Push, tag v1.2.0, verify the published release

**Files:** none — rollout.

**Interfaces:**
- Consumes: all previous commits on `main`.
- Produces: public GitHub Release `v1.2.0` with asset `vibeshift-eu-shipping-1.2.0.zip`.

- [ ] **Step 1: Push main**

```bash
git push origin main
```

- [ ] **Step 2: Tag and push the tag**

```bash
git tag v1.2.0
git push origin v1.2.0
```

- [ ] **Step 3: Watch the workflow run to completion**

Run: `gh run watch --repo VibeCodeRacing/VibeShift-EU --exit-status` (select the Release run if prompted; or `gh run list --repo VibeCodeRacing/VibeShift-EU --limit 3` first).
Expected: the Release workflow concludes `success`.

- [ ] **Step 4: Verify the release and asset**

Run: `gh release view v1.2.0 --repo VibeCodeRacing/VibeShift-EU --json tagName,assets --jq '{tag: .tagName, assets: [.assets[].name]}'`
Expected: `{"tag":"v1.2.0","assets":["vibeshift-eu-shipping-1.2.0.zip"]}`.

- [ ] **Step 5: Verify the live API answers the updater's exact query**

Run: `curl -s -H "Accept: application/vnd.github+json" https://api.github.com/repos/VibeCodeRacing/VibeShift-EU/releases/latest | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['tag_name'], [a['name'] for a in d['assets']])"`
Expected: `v1.2.0 ['vibeshift-eu-shipping-1.2.0.zip']` — exactly what `get_latest_release()` will parse on live sites.
