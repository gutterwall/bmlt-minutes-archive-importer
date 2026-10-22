<?php
/**
 * WordPress integration tests for BMLT Minutes Archive Importer, with the real BMLT Minutes plugin it imports into
 * (bin/install-bmlt-minutes.sh puts it in tests/dependencies/).
 *
 *   WP_TESTS_DIR=/path/to/tests-lib vendor/bin/phpunit -c tests/wordpress/phpunit.xml.dist
 *   (WP_MULTISITE=1 runs the same tests on a multisite network.)
 *
 * BMAI_PLUGIN_FILE tests another copy of the importer (for example the previous release, to see tests fail).
 */

$bmai_root  = dirname( __DIR__, 2 );
$bmai_tests = getenv( 'WP_TESTS_DIR' );
if ( ! $bmai_tests || ! file_exists( $bmai_tests . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Set WP_TESTS_DIR to a WordPress test library (bin/install-wp-tests.sh).\n" );
	exit( 1 );
}
$bmai_minutes = $bmai_root . '/tests/dependencies/bmlt-minutes/minutes.php';
if ( ! file_exists( $bmai_minutes ) ) {
	fwrite( STDERR, "BMLT Minutes is missing: run bin/install-bmlt-minutes.sh.\n" );
	exit( 1 );
}
define( 'BMAI_TEST_MINUTES_FILE', $bmai_minutes );
define( 'BMAI_TEST_PLUGIN_FILE', getenv( 'BMAI_PLUGIN_FILE' ) ? getenv( 'BMAI_PLUGIN_FILE' ) : $bmai_root . '/bmlt-minutes-archive-importer/bmlt-minutes-archive-importer.php' );
define( 'BMAI_TEST_FIXTURES', __DIR__ . '/fixtures' );

require_once $bmai_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $bmai_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require BMAI_TEST_MINUTES_FILE;
		require BMAI_TEST_PLUGIN_FILE;
	}
);
// As on activation: roles and capabilities, the default committees.
tests_add_filter(
	'init',
	static function () {
		BMLT_Minutes::activate();
	},
	20
);

require $bmai_tests . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
