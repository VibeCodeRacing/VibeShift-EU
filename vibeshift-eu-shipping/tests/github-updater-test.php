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
