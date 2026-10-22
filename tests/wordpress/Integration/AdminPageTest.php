<?php
/**
 * The Archive Import screen: menu, tabs, committee lists, date lists, warnings.
 */

namespace BmaiTest;

class AdminPageTest extends TestCase {

	public function test_menu_under_minutes_for_minutes_users() {
		global $submenu, $_wp_submenu_nopriv;
		foreach ( array( self::$admin => true, self::$manager => true, self::$author => false ) as $user => $visible ) {
			$submenu = $_wp_submenu_nopriv = array();
			wp_set_current_user( $user );
			set_current_screen( 'dashboard' );
			do_action( 'admin_menu' );
			$entries = array_column( $submenu['edit.php?post_type=bmlt_minutes'] ?? array(), 2 );
			$this->assertSame( $visible, in_array( 'bmlt-minutes-archive-importer', $entries, true ), "user $user" );
		}
		set_current_screen( 'front' );
	}

	public function test_single_tab_lists_committees_as_a_tree_sorted_by_name() {
		wp_insert_term( 'Zebra <b>Ad Hoc</b>', self::TAX );
		$html = $this->page();
		$this->assertStringContainsString( 'nav-tab nav-tab-active" href="', $html );
		preg_match( '#<select name="committee".*?</select>#s', $html, $m );
		preg_match_all( '#<option value="(\d*)">(.*?)</option>#', $m[0], $options );
		$this->assertSame(
			array( '— Select Committee —', 'Activities', 'Area Service Committee', 'Hospitals &amp; Institutions', '— H&amp;I Jail Panel', 'Literature', 'Outreach', 'Policy', 'Public Relations', 'Regional Service Committee', 'Zebra Ad Hoc' ),
			$options[2]
		);
		$this->assertStringNotContainsString( '<b>Ad Hoc', $html );
		$this->assertStringContainsString( 'name="bmai_nonce"', $html );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
	}

	public function test_date_lists() {
		$html = $this->page();
		preg_match( '#<select name="year".*?</select>#s', $html, $years );
		preg_match_all( '#<option value="(\d+)"#', $years[0], $y );
		$this->assertSame( (string) current_time( 'Y' ), $y[1][0] );
		$this->assertSame( '1950', end( $y[1] ) );
		$this->assertStringContainsString( 'value="' . (int) current_time( 'Y' ) . '"  selected=\'selected\'', $years[0] );
		preg_match( '#<select name="month".*?</select>#s', $html, $months );
		$this->assertSame( 12, substr_count( $months[0], '<option' ) );
		$this->assertStringContainsString( '>February<', $months[0] );
	}

	public function test_bulk_tab() {
		$html = $this->page( array( 'tab' => 'bulk' ) );
		$this->assertStringContainsString( 'name="archive_files[]" multiple', $html );
		$this->assertStringContainsString( 'permits up to <strong>' . (int) ini_get( 'max_file_uploads' ) . '</strong>', $html );
		$this->assertStringContainsString( 'name="bmai_bulk_nonce"', $html );
		$this->assertStringContainsString( 'id="bmai-apply-all"', $html );
		// The row template's committee list is JSON inside the script, markup escaped.
		$this->assertStringContainsString( 'const committeeOptions = "<option value=\\"', $html );
	}

	public function test_no_committees_yet() {
		foreach ( self::$committees as $id ) {
			wp_delete_term( $id, self::TAX );
		}
		$this->assertStringContainsString( 'No committees are configured in BMLT Minutes yet.', $this->page() );
	}

	public function test_without_bmlt_minutes() {
		global $wp_post_types;
		$saved = $wp_post_types[ self::CPT ];
		unregister_post_type( self::CPT );
		try {
			$this->assertStringContainsString( 'BMLT Minutes must be active before this importer can be used.', $this->page() );
			ob_start();
			do_action( 'admin_notices' );
			$this->assertStringContainsString( 'BMLT Minutes does not appear to be active.', ob_get_clean() );
			$this->assertStringContainsString( 'BMLT Minutes is not active', $this->notice( $this->import_single( array(), $this->upload( 'minutes.pdf' ) ) )[1] );
		} finally {
			$wp_post_types[ self::CPT ] = $saved;
		}
		ob_start();
		do_action( 'admin_notices' );
		$this->assertStringNotContainsString( 'BMLT Minutes does not appear to be active.', ob_get_clean() );
	}
}
