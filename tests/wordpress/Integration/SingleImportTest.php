<?php
/**
 * Single Import: one document or link becomes one BMLT Minutes record that BMLT Minutes itself shows.
 */

namespace BmaiTest;

class SingleImportTest extends TestCase {

	public function test_document_becomes_a_minutes_record_bmlt_minutes_shows() {
		$to = $this->import_single( array(), $this->upload( 'minutes.pdf', 'March 2019 ASC Minutes.pdf' ) );
		$this->assertSame( array( 'notice-success', 'Minutes imported successfully. You can edit the imported record from the Minutes screen.' ), $this->notice( $to ) );
		$this->assertStringContainsString( 'edit.php?post_type=bmlt_minutes&page=bmlt-minutes-archive-importer', $to );

		$posts = $this->minutes();
		$this->assertCount( 1, $posts );
		$post = $posts[0];
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'Area Service Committee — March 14, 2019', $post->post_title );
		$this->assertSame( '2019-03-14 12:00:00', $post->post_date );
		$this->assertSame( self::$admin, (int) $post->post_author );
		$this->assertSame( array( 'Area Service Committee' ), $this->committees_of( $post->ID ) );
		// The keys BMLT Minutes reads.
		$this->assertSame( '2019-03-14', get_post_meta( $post->ID, '_bmlt_minutes_date', true ) );
		$attachment = (int) get_post_meta( $post->ID, '_bmlt_minutes_attachment_id', true );
		$this->assertSame( $post->ID, wp_get_post_parent_id( $attachment ) );
		$this->assertSame( 'application/pdf', get_post_mime_type( $attachment ) );
		$this->assertFileEquals( BMAI_TEST_FIXTURES . '/minutes.pdf', get_attached_file( $attachment ) );
		list( $url, $label, $type ) = \BMLT_Minutes::resolve_document( $post->ID );
		$this->assertSame( wp_get_attachment_url( $attachment ), $url );
		$this->assertSame( 'March-2019-ASC-Minutes.pdf', $label );
		$this->assertSame( 'pdf', $type );
		// And the [bmlt_minutes] list on the public site.
		wp_set_current_user( 0 );
		$list = do_shortcode( '[bmlt_minutes]' );
		$this->assertStringContainsString( 'Area Service Committee — March 14, 2019', html_entity_decode( $list ) );
		$this->assertStringContainsString( esc_url( $url ), $list );
	}

	public function test_external_link_instead_of_a_document() {
		$link = 'https://docs.google.com/document/d/abc123/edit';
		$to   = $this->import_single( array( 'external_url' => $link, 'committee' => self::$committees['Public Relations'], 'year' => 2001, 'month' => 12, 'day' => 31 ) );
		$this->assertSame( 'notice-success', $this->notice( $to )[0] );
		$post = $this->minutes()[0];
		$this->assertSame( $link, get_post_meta( $post->ID, '_bmlt_minutes_url', true ) );
		$this->assertSame( '', get_post_meta( $post->ID, '_bmlt_minutes_attachment_id', true ) );
		$this->assertSame( array( $link, 'docs.google.com', 'google' ), \BMLT_Minutes::resolve_document( $post->ID ) );
		$this->assertSame( 'Public Relations — December 31, 2001', $post->post_title );
	}

	public function test_title_status_and_after_import_hook() {
		$seen = array();
		add_action(
			'bmai_after_import',
			static function ( $id, $info ) use ( &$seen ) {
				$seen[] = array( $id, $info['date'] );
			},
			10,
			2
		);
		$this->import_single( array( 'title' => ' <b>Spring</b> Assembly ', 'post_status' => 'draft' ), $this->upload( 'minutes.txt' ) );
		$post = $this->minutes()[0];
		$this->assertSame( 'Spring Assembly', $post->post_title );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( array( array( $post->ID, '2019-03-14' ) ), $seen );
	}

	public function sources() {
		return array(
			'neither'  => array( null, '', 'Choose an archive document or enter an external link.' ),
			'both'     => array( 'minutes.pdf', 'https://example.org/m.pdf', 'Use either an uploaded document or an external link, not both.' ),
		);
	}

	/**
	 * @dataProvider sources
	 */
	public function test_one_source_is_required( $fixture, $link, $message ) {
		$to = $this->import_single( array( 'external_url' => $link ), $fixture ? $this->upload( $fixture ) : null );
		$this->assertSame( array( 'notice-error', $message ), $this->notice( $to ) );
		$this->assertSame( array(), $this->minutes() );
	}

	public function bad_fields() {
		return array(
			'no committee'           => array( array( 'committee' => 0 ), 'Please select a valid committee.' ),
			'unknown committee'      => array( array( 'committee' => 999999 ), 'Please select a valid committee.' ),
			'term of other taxonomy' => array( array( 'committee' => 1 ), 'Please select a valid committee.' ),
			'31 April'               => array( array( 'month' => 4, 'day' => 31 ), 'Please select a valid meeting date.' ),
			'29 February 2019'       => array( array( 'month' => 2, 'day' => 29 ), 'Please select a valid meeting date.' ),
			'month 13'               => array( array( 'month' => 13 ), 'Please select a valid meeting date.' ),
			'before 1950'            => array( array( 'year' => 1949 ), 'Please select a valid meeting date.' ),
			'next year'              => array( array( 'year' => (int) gmdate( 'Y' ) + 1 ), 'Please select a valid meeting date.' ),
			'year 1'                 => array( array( 'year' => 1 ), 'Please select a valid meeting date.' ),
		);
	}

	/**
	 * @dataProvider bad_fields
	 */
	public function test_invalid_committee_or_date_is_refused( $fields, $message ) {
		$to = $this->import_single( $fields, $this->upload( 'minutes.pdf' ) );
		$this->assertSame( array( 'notice-error', $message ), $this->notice( $to ) );
		$this->assertSame( array(), $this->minutes() );
		$this->assertSame( array(), get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any' ) ), 'nothing uploaded' );
	}

	public function test_leap_day_is_a_valid_date() {
		$this->import_single( array( 'month' => 2, 'day' => 29, 'year' => 2020 ), $this->upload( 'minutes.pdf' ) );
		$this->assertSame( '2020-02-29', get_post_meta( $this->minutes()[0]->ID, '_bmlt_minutes_date', true ) );
	}

	public function test_meeting_later_this_year_is_published_not_scheduled() {
		// Noon on the meeting day is ahead of now (as it is for today's meeting imported in the morning): WordPress
		// would schedule the post instead of publishing it.
		$year = (int) current_time( 'Y' );
		$to   = $this->import_single( array( 'year' => $year, 'month' => 12, 'day' => 31 ), $this->upload( 'minutes.pdf' ) );
		$this->assertSame( 'notice-success', $this->notice( $to )[0] );
		$post = $this->minutes()[0];
		$this->assertSame( 'publish', $post->post_status );
		$this->assertLessThanOrEqual( current_time( 'mysql' ), $post->post_date );
		$this->assertSame( $year . '-12-31', get_post_meta( $post->ID, '_bmlt_minutes_date', true ), 'the meeting date itself is unchanged' );
	}

	public function test_duplicate_committee_and_date_is_refused_unless_allowed() {
		$this->import_single( array( 'post_status' => 'draft' ), $this->upload( 'minutes.pdf' ) );
		$to = $this->import_single( array(), $this->upload( 'minutes.txt' ) );
		$this->assertSame( array( 'notice-warning', 'Minutes already exist for that committee and meeting date.' ), $this->notice( $to ) );
		$this->assertCount( 1, $this->minutes(), 'a draft counts as existing minutes' );
		// Another committee on the same day, or the same committee another day: not duplicates.
		$this->import_single( array( 'committee' => self::$committees['Policy'] ), $this->upload( 'minutes.txt' ) );
		$this->import_single( array( 'day' => 15 ), $this->upload( 'minutes.txt' ) );
		$this->assertCount( 3, $this->minutes() );
		$to = $this->import_single( array( 'allow_duplicate' => '1' ), $this->upload( 'minutes.txt' ) );
		$this->assertSame( 'notice-success', $this->notice( $to )[0] );
		$this->assertCount( 4, $this->minutes() );
	}

	public function file_types() {
		return array(
			'PDF' => array( 'minutes.pdf', 'minutes.pdf', true ),
			'TXT' => array( 'minutes.txt', 'minutes.txt', true ),
			'CSV' => array( 'motions.csv', 'motions.csv', true ),
			'RTF' => array( 'minutes.rtf', 'minutes.rtf', true ),
			// Not documents BMLT Minutes accepts, whatever the file picker was told.
			'JPEG'             => array( 'photo.jpg', 'photo.jpg', false ),
			'HTML'             => array( 'page.html', 'page.html', false ),
			'PHP'              => array( 'not-minutes.php', 'not-minutes.php', false ),
			'PHP named .pdf'   => array( 'not-minutes.php', 'minutes.pdf', false ),
			'JPEG named .pdf'  => array( 'photo.jpg', 'minutes.pdf', false ),
		);
	}

	/**
	 * @dataProvider file_types
	 */
	public function test_only_minutes_document_types_are_stored( $fixture, $name, $accepted ) {
		$to     = $this->import_single( array(), $this->upload( $fixture, $name ) );
		$notice = $this->notice( $to );
		if ( $accepted ) {
			$this->assertSame( 'notice-success', $notice[0], $notice[1] );
			$this->assertCount( 1, $this->minutes() );
			return;
		}
		$this->assertSame( 'notice-error', $notice[0] );
		$this->assertStringStartsWith( 'Upload failed: ', $notice[1] );
		$this->assertSame( array(), $this->minutes(), 'no minutes record left behind' );
		$this->assertSame( array(), get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any' ) ) );
		$uploads = wp_upload_dir();
		$this->assertSame( array(), glob( $uploads['path'] . '/*' . pathinfo( $name, PATHINFO_FILENAME ) . '*' ) );
	}

	public function test_bmlt_minutes_upload_size_limit_applies() {
		update_option( 'bmlt_minutes_max_upload_mb', 1 );
		// The browser posts from the importer's page under Minutes.
		$_SERVER['HTTP_REFERER'] = admin_url( 'edit.php?post_type=bmlt_minutes&page=bmlt-minutes-archive-importer' );
		$to                      = $this->import_single( array(), $this->upload( 'minutes.pdf', 'big.pdf', UPLOAD_ERR_OK, 2 * 1024 * 1024 ) );
		$this->assertSame( array( 'notice-error', 'Upload failed: File is too large. Minutes uploads are capped at 1 MB.' ), $this->notice( $to ) );
		$this->assertSame( array(), $this->minutes() );
	}

	public function test_upload_error_from_php() {
		$to = $this->import_single( array(), $this->upload( 'minutes.pdf', 'huge.pdf', UPLOAD_ERR_INI_SIZE ) );
		$notice = $this->notice( $to );
		$this->assertSame( 'notice-error', $notice[0] );
		$this->assertStringContainsString( 'upload_max_filesize', $notice[1] );
		$this->assertSame( array(), $this->minutes() );
	}

	public function test_nonce_is_required() {
		$this->expectException( \WPDieException::class );
		$this->import_single( array( 'bmai_nonce' => 'forged' ), $this->upload( 'minutes.pdf' ) );
	}

	public function test_results_cannot_be_spoofed_through_the_link() {
		// 1.2.0 showed any text from the address: a link could put a fake message on the page.
		$html = $this->page( array( 'page' => 'bmlt-minutes-archive-importer', 'bmai_result' => 'success', 'bmai_message' => 'Your site is compromised, call 555-0100' ) );
		$this->assertStringNotContainsString( '555-0100', $html );
		// A notice is shown once, to the user it belongs to.
		$to = $this->import_single( array(), null );
		wp_set_current_user( self::$editor );
		$this->assertNull( $this->notice( $to ) );
		wp_set_current_user( self::$admin );
		$this->assertNotNull( $this->notice( $to ) );
		$this->assertNull( $this->notice( $to ) );
	}

	public function test_request_larger_than_post_max_size() {
		// PHP drops the body of a request larger than post_max_size: the nonce, and the "action" field too, so
		// admin-post.php runs the action-less "admin_post" hook.
		$_SERVER['CONTENT_LENGTH'] = 300 * 1024 * 1024;
		// Not from the importer's page: not ours, nothing happens.
		$_SERVER['HTTP_REFERER'] = admin_url( 'edit.php?post_type=page' );
		do_action( 'admin_post' );
		$_SERVER['HTTP_REFERER'] = admin_url( 'edit.php?post_type=bmlt_minutes&page=bmlt-minutes-archive-importer&tab=bulk' );
		try {
			do_action( 'admin_post' );
			$this->fail( 'no redirect' );
		} catch ( Redirected $r ) {
			$notice = $this->notice( $r->location );
		}
		$this->assertSame( 'notice-error', $notice[0] );
		$this->assertStringContainsString( 'larger than this server accepts in one request', $notice[1] );
	}
}
