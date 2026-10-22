<?php
/**
 * Bulk Import: many documents, each with its own committee, date and title; the batch report.
 */

namespace BmaiTest;

class BulkImportTest extends TestCase {

	private function row( $committee, $y, $m, $d, array $extra = array() ) {
		return $extra + array( 'include' => '1', 'committee' => self::$committees[ $committee ], 'year' => $y, 'month' => $m, 'day' => $d, 'title' => '' );
	}

	/**
	 * The report shown after the batch: [counts line, details].
	 */
	private function report( $location ) {
		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame( 'bulk', $query['tab'] );
		$html = $this->page( $query );
		$this->assertSame( 1, preg_match( '#<div class="notice (notice-[a-z]+) is-dismissible"><p><strong>Bulk import complete:</strong> (.*?)</p>#', $html, $m ), $html );
		preg_match_all( '#<li>(.*?)</li>#', $html, $items );
		return array( $m[1], $m[2], array_map( 'html_entity_decode', $items[1] ) );
	}

	public function test_batch_with_different_committees_dates_and_titles() {
		$to = $this->import_bulk(
			array(
				$this->row( 'Area Service Committee', 2018, 1, 7 ),
				$this->row( 'Hospitals & Institutions', 2019, 9, 18 ),
				$this->row( 'Policy', 2020, 2, 29, array( 'title' => 'Policy Workshop' ) ),
			),
			array( $this->upload( 'minutes.pdf', 'January ASC Minutes.pdf' ), $this->upload( 'minutes.txt', 'H&I Sep Minutes.txt' ), $this->upload( 'minutes.rtf' ) )
		);
		$this->assertSame(
			array(
				'notice-success',
				'3 imported, 0 failed, 0 skipped.',
				array( 'January-ASC-Minutes.pdf: imported successfully.', 'HI-Sep-Minutes.txt: imported successfully.', 'minutes.rtf: imported successfully.' ),
			),
			$this->report( $to )
		);
		$got = array();
		foreach ( array_reverse( $this->minutes() ) as $post ) {
			$got[] = array( html_entity_decode( $post->post_title ), $this->committees_of( $post->ID )[0], get_post_meta( $post->ID, '_bmlt_minutes_date', true ), $post->post_status );
			$this->assertNotEmpty( get_post_meta( $post->ID, '_bmlt_minutes_attachment_id', true ) );
		}
		$this->assertSame(
			array(
				// Default title: date-committee-file name without its extension.
				array( '2018-01-07-Area Service Committee-January-ASC-Minutes', 'Area Service Committee', '2018-01-07', 'publish' ),
				array( '2019-09-18-Hospitals & Institutions-HI-Sep-Minutes', 'Hospitals & Institutions', '2019-09-18', 'publish' ),
				array( 'Policy Workshop', 'Policy', '2020-02-29', 'publish' ),
			),
			$got
		);
		// Shown once.
		$this->assertStringNotContainsString( 'Bulk import complete', $this->page( array( 'tab' => 'bulk', 'bmai_bulk_result' => '1' ) ) );
	}

	public function test_failed_and_skipped_rows_do_not_stop_the_batch() {
		$to = $this->import_bulk(
			array(
				$this->row( 'Policy', 2019, 4, 31 ),
				$this->row( 'Policy', 2019, 4, 1, array( 'include' => '' ) ),
				$this->row( 'Outreach', 2019, 4, 2 ),
				$this->row( 'Outreach', 2019, 4, 3 ),
				array( 'include' => '1', 'committee' => '', 'year' => 2019, 'month' => 4, 'day' => 4 ),
				$this->row( 'Literature', 2019, 4, 5 ),
			),
			array(
				$this->upload( 'minutes.pdf', 'a.pdf' ),
				$this->upload( 'minutes.pdf', 'b.pdf' ),
				$this->upload( 'photo.jpg', 'c.jpg' ),
				$this->upload( 'minutes.pdf', 'd.pdf', UPLOAD_ERR_PARTIAL ),
				$this->upload( 'minutes.pdf', 'e.pdf' ),
				$this->upload( 'minutes.txt', 'f.txt' ),
			)
		);
		list( $class, $counts, $details ) = $this->report( $to );
		$this->assertSame( 'notice-warning', $class );
		$this->assertSame( '1 imported, 4 failed, 1 skipped.', $counts );
		$this->assertSame( 'a.pdf: Please select a valid meeting date.', $details[0] );
		$this->assertSame( 'b.pdf: skipped by user.', $details[1] );
		$this->assertStringStartsWith( 'c.jpg: Upload failed: ', $details[2] );
		$this->assertSame( 'd.pdf: upload error (file was only partially uploaded).', $details[3] );
		$this->assertSame( 'e.pdf: Please select a valid committee.', $details[4] );
		$this->assertSame( 'f.txt: imported successfully.', $details[5] );
		$this->assertCount( 1, $this->minutes() );
	}

	public function test_all_failed_is_an_error() {
		$to = $this->import_bulk( array( $this->row( 'Policy', 1900, 1, 1 ) ), array( $this->upload( 'minutes.pdf' ) ) );
		$this->assertSame( 'notice-error', $this->report( $to )[0] );
	}

	public function test_files_beyond_the_server_limit_are_reported() {
		// Five rows in the table, but PHP (max_file_uploads) handed over only the first three files.
		$rows = array();
		foreach ( range( 1, 5 ) as $d ) {
			$rows[] = $this->row( 'Activities', 2017, 6, $d, array( 'filename' => "june-$d.pdf" ) );
		}
		$to = $this->import_bulk( $rows, array( $this->upload( 'minutes.pdf', 'june-1.pdf' ), $this->upload( 'minutes.pdf', 'june-2.pdf' ), $this->upload( 'minutes.pdf', 'june-3.pdf' ) ) );
		list( $class, $counts, $details ) = $this->report( $to );
		$this->assertSame( '3 imported, 2 failed, 0 skipped.', $counts );
		$limit = (int) ini_get( 'max_file_uploads' );
		$this->assertContains( "june-4.pdf: not received by the server (it accepts $limit files per request).", $details );
		$this->assertContains( "june-5.pdf: not received by the server (it accepts $limit files per request).", $details );
	}

	public function test_no_files_at_all() {
		$to = $this->import_bulk( array( $this->row( 'Policy', 2019, 1, 1 ) ), array() );
		$this->assertSame( array( 'notice-error', '0 imported, 1 failed, 0 skipped.', array( 'No archive files were received. Your server upload limits may have been exceeded.' ) ), $this->report( $to ) );
	}

	public function test_duplicates_in_the_archive_and_within_the_batch() {
		$this->import_single( array( 'committee' => self::$committees['Policy'], 'year' => 2016, 'month' => 5, 'day' => 5 ), $this->upload( 'minutes.pdf' ) );
		$rows  = array( $this->row( 'Policy', 2016, 5, 5 ), $this->row( 'Policy', 2016, 5, 6 ), $this->row( 'Policy', 2016, 5, 6 ) );
		$files = array( $this->upload( 'minutes.pdf', 'a.pdf' ), $this->upload( 'minutes.pdf', 'b.pdf' ), $this->upload( 'minutes.pdf', 'c.pdf' ) );
		list( , $counts, $details ) = $this->report( $this->import_bulk( $rows, $files ) );
		$this->assertSame( '1 imported, 2 failed, 0 skipped.', $counts );
		$this->assertSame( 'a.pdf: Minutes already exist for that committee and meeting date.', $details[0] );
		$this->assertSame( 'c.pdf: Minutes already exist for that committee and meeting date.', $details[2] );

		$files = array( $this->upload( 'minutes.pdf', 'a.pdf' ), $this->upload( 'minutes.pdf', 'b.pdf' ), $this->upload( 'minutes.pdf', 'c.pdf' ) );
		list( , $counts ) = $this->report( $this->import_bulk( $rows, $files, array( 'allow_duplicate' => '1' ) ) );
		$this->assertSame( '3 imported, 0 failed, 0 skipped.', $counts );
	}

	public function test_draft_batch_and_publish_permission() {
		$this->import_bulk( array( $this->row( 'Policy', 2015, 1, 1 ) ), array( $this->upload( 'minutes.pdf' ) ), array( 'post_status' => 'draft' ) );
		$this->assertSame( 'draft', $this->minutes()[0]->post_status );
		// Someone who may edit but not publish: drafts, and the report says so. (They may not upload either, so this
		// batch is refused; give them upload rights for it.)
		$clerk = get_user_by( 'id', self::$clerk );
		$clerk->add_cap( 'upload_files' );
		wp_set_current_user( self::$clerk );
		list( $class, , $details ) = $this->report( $this->import_bulk( array( $this->row( 'Policy', 2015, 1, 2 ) ), array( $this->upload( 'minutes.pdf' ) ) ) );
		$clerk->remove_cap( 'upload_files' );
		$this->assertSame( 'notice-success', $class );
		$this->assertSame( array( 'minutes.pdf: imported as a draft (your account may not publish minutes).' ), $details );
		$this->assertSame( 'draft', $this->minutes()[0]->post_status );
	}

	public function test_hostile_rows_do_not_break_the_batch() {
		$rows = array(
			array( 'include' => '1', 'committee' => array( 5 ), 'year' => array(), 'month' => '1', 'day' => '1', 'title' => array( 'x' ) ),
			$this->row( 'Policy', 2014, 3, 3, array( 'title' => '<script>alert(1)</script>Minutes' ) ),
		);
		$to = $this->import_bulk( $rows, array( $this->upload( 'minutes.pdf', '<img src=x onerror=alert(1)>.pdf' ), $this->upload( 'minutes.pdf' ) ) );
		$html = $this->page( array( 'tab' => 'bulk', 'bmai_bulk_result' => '1' ) );
		$this->assertStringContainsString( '1 imported, 1 failed, 0 skipped.', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertSame( 'Minutes', $this->minutes()[0]->post_title );
	}
}
