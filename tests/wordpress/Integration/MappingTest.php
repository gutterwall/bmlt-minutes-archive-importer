<?php
/**
 * Which meta keys the importer writes: BMLT Minutes' own, detected ones for a customized minutes plugin, or the
 * administrator's mapping.
 */

namespace BmaiTest;

class MappingTest extends TestCase {

	private function mapping_fields( $html ) {
		preg_match_all( '#name="mapping\[(\w+)\]" value="([^"]*)"#', $html, $m );
		return array_combine( $m[1], $m[2] );
	}

	public function test_bmlt_minutes_keys_are_used() {
		$this->assertSame( array( 'date' => '_bmlt_minutes_date', 'file' => '_bmlt_minutes_attachment_id', 'url' => '_bmlt_minutes_url' ), $this->mapping_fields( $this->page() ) );
		$this->assertStringContainsString( 'Detected post type: <code>bmlt_minutes</code> &nbsp; Committee taxonomy: <code>bmlt_committee</code>', $this->page() );
	}

	public function test_detection_is_not_misled_by_other_meta_on_minutes() {
		// Meta that other plugins leave on minutes posts.
		$id = self::factory()->post->create( array( 'post_type' => self::CPT ) );
		foreach ( array( '_bmlt_minutes_document_url_cache', '_bmlt_minutes_file_size', '_thumbnail_id', 'bmlt_meeting_date_note' ) as $key ) {
			update_post_meta( $id, $key, '1' );
		}
		$this->assertSame( array( 'date' => '_bmlt_minutes_date', 'file' => '_bmlt_minutes_attachment_id', 'url' => '_bmlt_minutes_url' ), $this->mapping_fields( $this->page() ) );
	}

	public function test_saved_mapping_is_used_as_typed() {
		$to = $this->submit(
			'bmai_save_mapping',
			array(
				'bmai_mapping_nonce' => wp_create_nonce( 'bmai_save_mapping' ),
				'mapping'            => array( 'date' => ' Meeting_Date-v2 ', 'file' => 'file"><script>', 'url' => array( 'x' ) ),
			)
		);
		$this->assertSame( array( 'notice-success', 'Compatibility mapping saved.' ), $this->notice( $to ) );
		$this->assertSame( array( 'date' => 'Meeting_Date-v2', 'file' => 'filescript', 'url' => '' ), get_option( 'bmai_meta_mapping' ) );
		$this->import_single( array( 'external_url' => 'https://example.org/a.pdf' ) );
		$post = $this->minutes()[0];
		// Letter case kept (1.2.0 saved "Meeting_Date-v2" but wrote "meeting_date-v2").
		$this->assertSame( '2019-03-14', get_post_meta( $post->ID, 'Meeting_Date-v2', true ) );
		$this->assertSame( 'https://example.org/a.pdf', get_post_meta( $post->ID, '_bmlt_minutes_url', true ), 'an empty field falls back to detection' );
	}

	public function test_customized_minutes_plugin_is_detected() {
		global $wp_post_types;
		$saved = $wp_post_types[ self::CPT ];
		unregister_post_type( self::CPT );
		register_post_type( 'meeting_minutes', array( 'labels' => array( 'name' => 'Meeting Minutes' ), 'public' => true ) );
		register_taxonomy( 'committees', 'meeting_minutes', array( 'hierarchical' => true ) );
		foreach ( array( 'minutes_meeting_date', 'minutes_document_file', 'minutes_external_link' ) as $key ) {
			register_post_meta( 'meeting_minutes', $key, array( 'single' => true, 'type' => 'string' ) );
		}
		$term = wp_insert_term( 'Steering', 'committees' );
		try {
			$this->assertSame( array( 'date' => 'minutes_meeting_date', 'file' => 'minutes_document_file', 'url' => 'minutes_external_link' ), $this->mapping_fields( $this->page() ) );
			$this->assertStringContainsString( 'Detected post type: <code>meeting_minutes</code> &nbsp; Committee taxonomy: <code>committees</code>', $this->page() );
			$to = $this->import_single( array( 'committee' => $term['term_id'] ), $this->upload( 'minutes.pdf' ) );
			$this->assertSame( 'notice-success', $this->notice( $to )[0] );
			$post = get_posts( array( 'post_type' => 'meeting_minutes', 'post_status' => 'any' ) )[0];
			$this->assertSame( '2019-03-14', get_post_meta( $post->ID, 'minutes_meeting_date', true ) );
			$this->assertGreaterThan( 0, (int) get_post_meta( $post->ID, 'minutes_document_file', true ) );
		} finally {
			unregister_taxonomy( 'committees' );
			unregister_post_type( 'meeting_minutes' );
			$wp_post_types[ self::CPT ] = $saved;
		}
	}

	public function test_minutes_plugin_without_a_committee_taxonomy() {
		global $wp_post_types;
		$saved = $wp_post_types[ self::CPT ];
		unregister_post_type( self::CPT );
		register_post_type( 'meeting_minutes', array( 'labels' => array( 'name' => 'Meeting Minutes' ) ) );
		try {
			$this->assertStringContainsString( 'Could not locate the Committee taxonomy used by BMLT Minutes.', $this->page() );
			$this->assertSame( array( 'notice-error', 'Could not detect the BMLT Minutes Committee taxonomy.' ), $this->notice( $this->import_single( array( 'external_url' => 'https://example.org/a.pdf' ) ) ) );
		} finally {
			unregister_post_type( 'meeting_minutes' );
			$wp_post_types[ self::CPT ] = $saved;
		}
	}
}
