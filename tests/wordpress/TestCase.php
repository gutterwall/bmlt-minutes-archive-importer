<?php
/**
 * Base class: BMLT Minutes active with its default committees (plus a sub-committee), users of every kind, and
 * helpers that submit the importer's forms the way a browser does, uploads included.
 *
 * PHP's is_uploaded_file() is false for anything a test creates, so the importer's bmai_pre_upload hook hands each
 * upload to media_handle_sideload() with the importer's own overrides: the same WordPress handling (file type checks,
 * the "wp_handle_upload_prefilter" filters such as BMLT Minutes' size limit, the Media Library) except that one
 * check. Real uploads from a browser are covered by bin/e2e.sh.
 */

namespace BmaiTest;

/**
 * Thrown instead of redirecting (wp_redirect would exit).
 */
class Redirected extends \Exception {
	/** @var string */
	public $location;

	public function __construct( $location ) {
		parent::__construct( 'Redirect to ' . $location );
		$this->location = $location;
	}
}

abstract class TestCase extends \WP_UnitTestCase {

	const CPT = 'bmlt_minutes';
	const TAX = 'bmlt_committee';

	/** @var int */
	public static $admin;
	/** @var int */
	public static $editor;
	/** @var int Minutes Manager (BMLT Minutes' own role). */
	public static $manager;
	/** @var int Minutes Manager limited to Hospitals & Institutions (and its sub-committees). */
	public static $hi_manager;
	/** @var int A role that may edit minutes but not publish them or upload files. */
	public static $clerk;
	/** @var int */
	public static $author;
	/** @var int */
	public static $subscriber;

	/** @var int[] Committee term ids by name. */
	public static $committees = array();

	/** @var string[] Temporary upload copies made by this test. */
	private $tmp = array();

	public static function wpSetUpBeforeClass( \WP_UnitTest_Factory $factory ) {
		// BMLT Minutes' activation: roles, capabilities and its default committees (the test framework deletes all
		// terms after each test class).
		\BMLT_Minutes::activate();
		self::$committees = array();
		foreach ( get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => false ) ) as $term ) {
			self::$committees[ html_entity_decode( $term->name ) ] = (int) $term->term_id;
		}
		$sub                                      = wp_insert_term( 'H&I Jail Panel', self::TAX, array( 'parent' => self::$committees['Hospitals & Institutions'] ) );
		self::$committees['H&I Jail Panel']       = (int) $sub['term_id'];
		self::$admin                              = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor                             = $factory->user->create( array( 'role' => 'editor' ) );
		self::$manager                            = $factory->user->create( array( 'role' => 'minutes_manager' ) );
		self::$hi_manager                         = $factory->user->create( array( 'role' => 'minutes_manager' ) );
		update_user_meta( self::$hi_manager, '_bmlt_minutes_committees', array( self::$committees['Hospitals & Institutions'] ) );
		add_role( 'minutes_clerk', 'Minutes Clerk', array( 'read' => true, 'edit_bmlt_minutes' => true, 'assign_terms' => true ) );
		self::$clerk      = $factory->user->create( array( 'role' => 'minutes_clerk' ) );
		self::$author     = $factory->user->create( array( 'role' => 'author' ) );
		self::$subscriber = $factory->user->create( array( 'role' => 'subscriber' ) );
		if ( is_multisite() ) {
			grant_super_admin( self::$admin );
		}
	}

	public static function wpTearDownAfterClass() {
		remove_role( 'minutes_clerk' );
	}

	public function set_up() {
		parent::set_up();
		add_filter( 'wp_redirect', array( __CLASS__, 'throw_redirect' ), 1 );
		add_filter( 'bmai_pre_upload', array( __CLASS__, 'sideload' ), 10, 4 );
		$_POST = $_GET = $_REQUEST = $_FILES = array();
		unset( $_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_REFERER'] );
		wp_set_current_user( self::$admin );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', array( __CLASS__, 'throw_redirect' ), 1 );
		remove_filter( 'bmai_pre_upload', array( __CLASS__, 'sideload' ), 10 );
		$_POST = $_GET = $_REQUEST = $_FILES = array();
		unset( $_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_REFERER'] );
		foreach ( $this->tmp as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		// Uploaded documents of this test.
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
			wp_delete_attachment( $id, true );
		}
		parent::tear_down();
	}

	/**
	 * The upload, through media_handle_sideload() (see the class comment).
	 */
	public static function sideload( $pre, $file, $post_id, $overrides ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$prefilter = static function ( $file ) {
			return apply_filters( 'wp_handle_upload_prefilter', $file );
		};
		// media_handle_sideload() takes no overrides of its own: the importer's go in through this filter.
		$with = static function ( $defaults ) use ( $overrides ) {
			return array_merge( $defaults, $overrides );
		};
		add_filter( 'wp_handle_sideload_prefilter', $prefilter );
		add_filter( 'wp_handle_sideload_overrides', $with );
		try {
			return media_handle_sideload( $file, $post_id );
		} finally {
			remove_filter( 'wp_handle_sideload_prefilter', $prefilter );
			remove_filter( 'wp_handle_sideload_overrides', $with );
		}
	}

	/**
	 * @throws Redirected Always.
	 */
	public static function throw_redirect( $location ) {
		throw new Redirected( $location );
	}

	/**
	 * A file as PHP puts it in $_FILES, from a fixture (copied, as PHP's temporary file would be).
	 *
	 * @param string   $fixture File in tests/wordpress/fixtures.
	 * @param string   $name    Name the browser sends (default: the fixture's).
	 * @param int      $error   UPLOAD_ERR_*.
	 * @param int|null $size    Size to report.
	 */
	protected function upload( $fixture, $name = null, $error = UPLOAD_ERR_OK, $size = null ) {
		$tmp = tempnam( sys_get_temp_dir(), 'bmai' );
		copy( BMAI_TEST_FIXTURES . '/' . $fixture, $tmp );
		$this->tmp[] = $tmp;
		return array(
			'name'      => null === $name ? $fixture : $name,
			'full_path' => null === $name ? $fixture : $name,
			'type'      => 'application/octet-stream',
			'tmp_name'  => UPLOAD_ERR_OK === $error ? $tmp : '',
			'error'     => $error,
			'size'      => null === $size ? filesize( $tmp ) : $size,
		);
	}

	/**
	 * $_FILES['archive_files'] for a multiple file input.
	 */
	protected function multi( array $files ) {
		$out = array( 'name' => array(), 'full_path' => array(), 'type' => array(), 'tmp_name' => array(), 'error' => array(), 'size' => array() );
		foreach ( $files as $i => $f ) {
			foreach ( $out as $k => $unused ) {
				$out[ $k ][ $i ] = $f[ $k ];
			}
		}
		return $out;
	}

	/**
	 * Posts a form to admin-post.php (action hook) as the current user; returns the redirect target.
	 *
	 * @param string $action admin-post action.
	 * @param array  $post   Fields.
	 * @param array  $files  $_FILES.
	 */
	protected function submit( $action, array $post, array $files = array() ) {
		$_POST                     = wp_slash( $post + array( 'action' => $action ) );
		$_REQUEST                  = $_POST;
		$_FILES                    = $files;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		try {
			do_action( 'admin_post_' . $action );
		} catch ( Redirected $r ) {
			return $r->location;
		} finally {
			$_POST = $_REQUEST = $_FILES = array();
		}
		$this->fail( "$action did not redirect" );
	}

	/**
	 * The single import form, submitted.
	 */
	protected function import_single( array $fields, $file = null ) {
		$fields += array(
			'bmai_nonce'  => wp_create_nonce( 'bmai_import' ),
			'committee'   => self::$committees['Area Service Committee'],
			'month'       => 3,
			'day'         => 14,
			'year'        => 2019,
			'post_status' => 'publish',
		);
		return $this->submit( 'bmai_import', $fields, null === $file ? array( 'archive_file' => array( 'name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0 ) ) : array( 'archive_file' => $file ) );
	}

	/**
	 * The bulk import form, submitted.
	 *
	 * @param array $rows  Rows as the review table posts them.
	 * @param array $files Uploads, same order.
	 * @param array $extra post_status, allow_duplicate.
	 */
	protected function import_bulk( array $rows, array $files, array $extra = array() ) {
		$fields = $extra + array(
			'bmai_bulk_nonce' => wp_create_nonce( 'bmai_bulk_import' ),
			'post_status'     => 'publish',
			'rows'            => $rows,
		);
		return $this->submit( 'bmai_bulk_import', $fields, $files ? array( 'archive_files' => $this->multi( $files ) ) : array() );
	}

	/**
	 * The importer's page as the current user (with the query string given).
	 */
	protected function page( array $get = array() ) {
		$_GET = $get;
		set_current_screen( 'bmlt_minutes_page_bmlt-minutes-archive-importer' );
		ob_start();
		try {
			$this->importer()->render_page();
		} finally {
			$html = ob_get_clean();
			$_GET = array();
			set_current_screen( 'front' );
		}
		return $html;
	}

	/**
	 * The importer instance (registered as an admin_post hook).
	 */
	protected function importer() {
		global $wp_filter;
		foreach ( $wp_filter['admin_post_bmai_import']->callbacks as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof \BMLT_Minutes_Archive_Importer ) {
					return $cb['function'][0];
				}
			}
		}
		$this->fail( 'importer not registered' );
	}

	/**
	 * The notice shown after a redirect (what the user reads on the next page).
	 */
	protected function notice( $location ) {
		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$html = $this->page( $query );
		if ( ! preg_match_all( '#<div class="notice ([a-z-]+) is-dismissible"><p>(.*?)</p>#s', $html, $m, PREG_SET_ORDER ) ) {
			return null;
		}
		return array( $m[0][1], html_entity_decode( wp_strip_all_tags( $m[0][2] ), ENT_QUOTES ) );
	}

	/**
	 * Minutes posts, newest id first.
	 *
	 * @return \WP_Post[]
	 */
	protected function minutes() {
		return get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'DESC' ) );
	}

	/**
	 * A minutes post's committee names.
	 */
	protected function committees_of( $post_id ) {
		return array_map( 'html_entity_decode', wp_get_object_terms( $post_id, self::TAX, array( 'fields' => 'names' ) ) );
	}
}
