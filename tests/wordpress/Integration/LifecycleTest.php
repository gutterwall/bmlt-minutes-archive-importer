<?php
/**
 * Uninstall removes the importer's settings and pending notices and keeps the imported minutes.
 */

namespace BmaiTest;

class LifecycleTest extends TestCase {

	private function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'bmlt-minutes-archive-importer/bmlt-minutes-archive-importer.php' );
		}
		include dirname( BMAI_TEST_PLUGIN_FILE ) . '/uninstall.php';
	}

	private function rows() {
		global $wpdb;
		wp_cache_flush();
		return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%bmai%' ORDER BY option_name" );
	}

	public function test_uninstall_keeps_minutes_and_removes_settings() {
		update_option( 'bmai_meta_mapping', array( 'date' => 'x', 'file' => '', 'url' => '' ) );
		$this->import_single( array(), null );
		$this->import_bulk( array(), array() );
		$this->import_single( array(), $this->upload( 'minutes.pdf' ) );
		$this->assertCount( 5, $this->rows(), 'mapping, two pending notices, one pending report (with timeouts)' );
		$this->uninstall();
		$this->assertSame( array(), $this->rows() );
		$this->assertCount( 1, $this->minutes() );
		$this->assertNotEmpty( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any' ) ) );
	}

	public function test_uninstall_on_a_network() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'multisite only (WP_MULTISITE=1)' );
		}
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		update_option( 'bmai_meta_mapping', array( 'date' => 'x' ) );
		restore_current_blog();
		update_option( 'bmai_meta_mapping', array( 'date' => 'y' ) );
		$this->uninstall();
		$this->assertSame( array(), $this->rows() );
		switch_to_blog( $site );
		$this->assertFalse( get_option( 'bmai_meta_mapping' ) );
		restore_current_blog();
	}
}
