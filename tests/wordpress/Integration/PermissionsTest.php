<?php
/**
 * Who may import what: the importer must not let anyone do more than BMLT Minutes itself allows.
 */

namespace BmaiTest;

class PermissionsTest extends TestCase {

	public function importers() {
		return array(
			'administrator'   => array( 'admin' ),
			'editor'          => array( 'editor' ),
			'Minutes Manager' => array( 'manager' ),
		);
	}

	/**
	 * @dataProvider importers
	 */
	public function test_roles_with_minutes_access_can_import( $who ) {
		wp_set_current_user( self::$$who );
		$to = $this->import_single( array(), $this->upload( 'minutes.pdf' ) );
		$this->assertSame( 'notice-success', $this->notice( $to )[0] );
		$this->assertSame( 'publish', $this->minutes()[0]->post_status );
	}

	public function outsiders() {
		return array(
			'author'     => array( 'author' ),
			'subscriber' => array( 'subscriber' ),
		);
	}

	/**
	 * @dataProvider outsiders
	 */
	public function test_users_without_minutes_access_cannot_import( $who ) {
		wp_set_current_user( self::$$who );
		foreach ( array( 'single', 'bulk' ) as $form ) {
			try {
				if ( 'single' === $form ) {
					$this->import_single( array(), $this->upload( 'minutes.pdf' ) );
				} else {
					$this->import_bulk( array( array( 'include' => '1', 'committee' => self::$committees['Policy'], 'month' => 1, 'day' => 2, 'year' => 2010 ) ), array( $this->upload( 'minutes.pdf' ) ) );
				}
				$this->fail( "$form import allowed" );
			} catch ( \WPDieException $e ) {
				$this->assertSame( 'Not allowed.', $e->getMessage() );
			}
		}
		$this->assertSame( array(), $this->minutes() );
		$this->expectException( \WPDieException::class );
		$this->page();
	}

	public function test_committee_limited_manager_imports_only_into_their_committees() {
		wp_set_current_user( self::$hi_manager );
		// Their committee and its sub-committee.
		$this->import_single( array( 'committee' => self::$committees['Hospitals & Institutions'] ), $this->upload( 'minutes.pdf' ) );
		$this->import_single( array( 'committee' => self::$committees['H&I Jail Panel'] ), $this->upload( 'minutes.pdf' ) );
		$this->assertCount( 2, $this->minutes() );
		// Another committee: 1.2.0 published it with no committee at all (BMLT Minutes stripped it) and said "success".
		$to = $this->import_single( array( 'committee' => self::$committees['Policy'] ), $this->upload( 'minutes.pdf' ) );
		$this->assertSame( array( 'notice-error', 'You can only import minutes for your own committees.' ), $this->notice( $to ) );
		$this->import_bulk( array( array( 'include' => '1', 'committee' => self::$committees['Policy'], 'month' => 1, 'day' => 2, 'year' => 2010 ) ), array( $this->upload( 'minutes.pdf' ) ) );
		$this->assertCount( 2, $this->minutes() );
		foreach ( $this->minutes() as $post ) {
			$this->assertNotEmpty( $this->committees_of( $post->ID ), 'every imported record has its committee' );
		}
	}

	public function test_committee_limited_manager_sees_only_their_committees() {
		wp_set_current_user( self::$hi_manager );
		$html = $this->page();
		preg_match_all( '#<select name="committee".*?</select>#s', $html, $m );
		$this->assertStringContainsString( 'Hospitals &amp; Institutions', $m[0][0] );
		$this->assertStringContainsString( 'H&amp;I Jail Panel', $m[0][0] );
		$this->assertStringNotContainsString( 'Policy', $m[0][0] );
	}

	public function test_without_publish_permission_minutes_are_imported_as_drafts() {
		wp_set_current_user( self::$clerk );
		$to = $this->import_single( array( 'external_url' => 'https://example.org/minutes.pdf' ) );
		$this->assertSame( array( 'notice-warning', 'Minutes imported as a draft: your account may not publish minutes.' ), $this->notice( $to ) );
		$this->assertSame( 'draft', $this->minutes()[0]->post_status );
		$this->assertFalse( current_user_can( 'publish_post', $this->minutes()[0]->ID ) );
	}

	public function test_without_upload_permission_only_links_can_be_imported() {
		wp_set_current_user( self::$clerk );
		$this->assertFalse( current_user_can( 'upload_files' ) );
		$to = $this->import_single( array(), $this->upload( 'minutes.pdf' ) );
		$this->assertSame( array( 'notice-error', 'Your account may not upload files. Use an external link instead.' ), $this->notice( $to ) );
		$this->assertSame( array(), get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any' ) ) );
		$this->assertSame( array(), $this->minutes() );
	}

	public function test_only_administrators_change_the_mapping() {
		wp_set_current_user( self::$editor );
		try {
			$this->submit( 'bmai_save_mapping', array( 'bmai_mapping_nonce' => wp_create_nonce( 'bmai_save_mapping' ), 'mapping' => array( 'date' => 'x' ) ) );
			$this->fail( 'saved' );
		} catch ( \WPDieException $e ) {
			$this->assertFalse( get_option( 'bmai_meta_mapping' ) );
		}
		wp_set_current_user( self::$admin );
		$this->expectException( \WPDieException::class );
		$this->submit( 'bmai_save_mapping', array( 'bmai_mapping_nonce' => 'forged', 'mapping' => array( 'date' => 'x' ) ) );
	}

	public function test_bulk_nonce_is_required() {
		$this->expectException( \WPDieException::class );
		$this->import_bulk( array(), array( $this->upload( 'minutes.pdf' ) ), array( 'bmai_bulk_nonce' => 'forged' ) );
	}
}
