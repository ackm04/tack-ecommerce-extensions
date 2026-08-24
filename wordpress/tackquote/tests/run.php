<?php
/**
 * Regression tests for the plugin's admin entry point.
 *
 * Run: php tests/run.php   (no PHPUnit, no WordPress required)
 */

require __DIR__ . '/wp-stubs.php';

define( 'ABSPATH', '/' );
define( 'TACK_QUOTES_FILE', dirname( __DIR__ ) . '/tackquote.php' );
define( 'TACK_QUOTES_DIR', dirname( __DIR__ ) . '/' );
define( 'TACK_QUOTES_URL', 'https://shop.example/wp-content/plugins/tackquote/' );
define( 'TACK_QUOTES_VERSION', 'test' );

require TACK_QUOTES_DIR . 'includes/class-tack-settings.php';
require TACK_QUOTES_DIR . 'includes/class-tack-quotes.php';

$failures = 0;
function check( $label, $condition, $detail = '' ) {
	global $failures;
	if ( $condition ) {
		echo "  PASS  $label\n";
		return;
	}
	$failures++;
	echo "  FAIL  $label" . ( $detail ? "\n        $detail" : '' ) . "\n";
}

// Register the menu exactly as WordPress would on `admin_menu`.
$settings = new Tack_Settings();
$settings->add_menu();
$registered = $GLOBALS['TACK_REGISTERED_PAGES'];

check( 'the plugin registers exactly one admin page', 1 === count( $registered ),
	'registered: ' . implode( ', ', array_keys( $registered ) ) );

// Build the Plugins-screen "Settings" link exactly as WordPress would.
$reflection = new ReflectionClass( 'Tack_Quotes' );
$plugin     = $reflection->newInstanceWithoutConstructor();
$links      = $plugin->action_links( array() );

check( 'action_links returns a link', ! empty( $links ) && is_string( $links[0] ) );

preg_match( '/[?&]page=([^"\'&]+)/', $links[0], $m );
$linked = isset( $m[1] ) ? urldecode( $m[1] ) : '(none)';

/*
 * THE REGRESSION. The Settings link once carried a hardcoded 'tack-quotes'
 * while the menu registered 'tackquote-for-woocommerce'. Clicking Settings
 * landed on an unregistered page, and WordPress reported
 * "Sorry, you are not allowed to access this page." — which reads as a
 * capability bug and is not one. Derive the slug from the constant instead.
 */
check(
	'the Settings link points at a REGISTERED admin page',
	array_key_exists( $linked, $registered ),
	"link slug '$linked' is not among registered: " . implode( ', ', array_keys( $registered ) )
);

check(
	'the Settings link slug equals Tack_Settings::PAGE_SLUG',
	$linked === Tack_Settings::PAGE_SLUG,
	"link '$linked' vs constant '" . Tack_Settings::PAGE_SLUG . "'"
);

// The page holds the API key that authenticates the whole store to TackQuote.
check(
	'the admin page requires manage_options',
	'manage_options' === ( $registered[ Tack_Settings::PAGE_SLUG ] ?? null )
);

echo "\n-- quote-only (B2B catalog) mode --\n";
require __DIR__ . '/catalog-mode-test.php';

echo $failures ? "\n$failures failure(s)\n" : "\nAll checks passed\n";
exit( $failures ? 1 : 0 );
