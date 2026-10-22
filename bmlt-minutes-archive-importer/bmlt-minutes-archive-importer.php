<?php
/**
 * Plugin Name: BMLT Minutes Archive Importer
 * Description: Companion importer for BMLT Minutes. Import historical minutes individually or in bulk by committee and meeting date.
 * Plugin URI: https://github.com/gutterwall/bmlt-minutes-archive-importer
 * Version: 1.3.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: bmlt-minutes
 * Author: Richard Schellenberg
 * License: GPL-2.0-or-later
 * Text Domain: bmlt-minutes-archive-importer
 *
 * @version 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BMLT_Minutes_Archive_Importer {
    public const VERSION = '1.3.0';
    private const OPTION  = 'bmai_meta_mapping';
    private const BULK_RESULT_PREFIX = 'bmai_bulk_result_';
    private const NOTICE_PREFIX = 'bmai_notice_';
    private const FIRST_YEAR = 1950;
    // The document types BMLT Minutes accepts (BMLT_Minutes::ALLOWED_EXTENSIONS).
    private const DOC_EXTENSIONS = [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'rtf', 'csv' ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ], 99 );
        add_action( 'admin_post_bmai_import', [ $this, 'handle_import' ] );
        add_action( 'admin_post_bmai_bulk_import', [ $this, 'handle_bulk_import' ] );
        add_action( 'admin_post_bmai_save_mapping', [ $this, 'save_mapping' ] );
        // A request over post_max_size loses its "action" field too, so it arrives here rather than at our handlers.
        add_action( 'admin_post', [ $this, 'handle_dropped_request' ] );
        add_action( 'admin_notices', [ $this, 'dependency_notice' ] );
    }

    public function dependency_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! $this->detect_post_type() ) {
            echo '<div class="notice notice-warning"><p><strong>BMLT Minutes Archive Importer:</strong> BMLT Minutes does not appear to be active. Activate BMLT Minutes before importing archives.</p></div>';
        }
    }

    public function admin_menu(): void {
        $post_type = $this->detect_post_type();
        $parent    = $post_type ? 'edit.php?post_type=' . $post_type : 'tools.php';
        $cap       = $post_type ? $this->edit_capability( $post_type ) : 'manage_options';

        add_submenu_page(
            $parent,
            'Import Minutes Archive',
            'Archive Import',
            $cap,
            'bmlt-minutes-archive-importer',
            [ $this, 'render_page' ]
        );
    }

    private function edit_capability( string $post_type ): string {
        $obj = get_post_type_object( $post_type );
        return ( $obj && isset( $obj->cap->edit_posts ) ) ? $obj->cap->edit_posts : 'edit_posts';
    }

    private function detect_post_type(): ?string {
        $preferred = [ 'bmlt_minute', 'bmlt_minutes', 'meeting_minute', 'meeting_minutes' ];
        foreach ( $preferred as $name ) {
            if ( post_type_exists( $name ) ) {
                return $name;
            }
        }

        foreach ( get_post_types( [], 'objects' ) as $name => $obj ) {
            $label = strtolower( (string) ( $obj->labels->name ?? '' ) );
            $sing  = strtolower( (string) ( $obj->labels->singular_name ?? '' ) );
            $cap   = (string) ( $obj->cap->edit_posts ?? '' );
            if ( str_contains( $cap, 'bmlt_minute' ) || str_contains( strtolower( $name ), 'bmlt_minut' ) || str_contains( $label, 'meeting minute' ) || str_contains( $sing, 'meeting minute' ) ) {
                return $name;
            }
        }
        return null;
    }

    private function detect_taxonomy( string $post_type ): ?string {
        $preferred = [ 'bmlt_committee', 'bmlt_committees', 'committee', 'committees' ];
        $attached  = get_object_taxonomies( $post_type, 'objects' );

        foreach ( $preferred as $name ) {
            if ( isset( $attached[ $name ] ) ) {
                return $name;
            }
        }
        foreach ( $attached as $name => $obj ) {
            $label = strtolower( (string) ( $obj->labels->name ?? '' ) );
            $sing  = strtolower( (string) ( $obj->labels->singular_name ?? '' ) );
            if ( str_contains( strtolower( $name ), 'committee' ) || str_contains( $label, 'committee' ) || str_contains( $sing, 'committee' ) ) {
                return $name;
            }
        }
        return null;
    }

    private function candidate_meta_keys( string $kind ): array {
        $sets = [
            'date' => [
                '_bmlt_minutes_date', '_bmlt_minutes_meeting_date', 'bmlt_minutes_meeting_date',
                '_bmlt_meeting_date', 'bmlt_meeting_date', '_meeting_date', 'meeting_date',
            ],
            'file' => [
                '_bmlt_minutes_attachment_id', '_bmlt_minutes_file_id', 'bmlt_minutes_file_id', '_bmlt_file_id', 'bmlt_file_id',
                '_minutes_file_id', 'minutes_file_id', '_document_id', 'document_id', '_file_id', 'file_id',
            ],
            'url' => [
                '_bmlt_minutes_url', '_bmlt_minutes_external_url', 'bmlt_minutes_external_url', '_bmlt_external_url', 'bmlt_external_url',
                '_minutes_external_url', 'minutes_external_url', '_external_url', 'external_url', '_document_url', 'document_url',
            ],
        ];
        return $sets[ $kind ] ?? [];
    }

    private function discover_meta_keys( string $post_type ): array {
        $found = [];
        if ( function_exists( 'get_registered_meta_keys' ) ) {
            $registered = get_registered_meta_keys( 'post', $post_type );
            foreach ( array_keys( $registered ) as $key ) {
                $found[ $key ] = true;
            }
        }
        $posts = get_posts( [
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => 10,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );
        foreach ( $posts as $post_id ) {
            foreach ( array_keys( get_post_meta( $post_id ) ) as $key ) {
                $found[ $key ] = true;
            }
        }
        return array_keys( $found );
    }

    private function score_meta_key( string $key, string $kind ): int {
        $s = strtolower( $key );
        $score = 0;
        if ( str_contains( $s, 'bmlt' ) ) $score += 4;
        if ( str_contains( $s, 'minute' ) ) $score += 3;
        if ( 'date' === $kind ) {
            if ( str_contains( $s, 'meeting' ) ) $score += 6;
            if ( str_contains( $s, 'date' ) ) $score += 8;
        } elseif ( 'file' === $kind ) {
            if ( str_contains( $s, 'file' ) ) $score += 8;
            if ( str_contains( $s, 'document' ) ) $score += 5;
            if ( str_contains( $s, 'id' ) ) $score += 2;
            if ( str_contains( $s, 'url' ) || str_contains( $s, 'link' ) ) $score -= 6;
        } elseif ( 'url' === $kind ) {
            if ( str_contains( $s, 'external' ) ) $score += 6;
            if ( str_contains( $s, 'url' ) || str_contains( $s, 'link' ) ) $score += 8;
            if ( str_contains( $s, 'document' ) ) $score += 3;
        }
        return $score;
    }

    private function resolve_mapping( string $post_type ): array {
        $saved = get_option( self::OPTION, [] );
        $saved = is_array( $saved ) ? $saved : [];
        $discovered = $this->discover_meta_keys( $post_type );
        $result = [ 'date' => '', 'file' => '', 'url' => '' ];
        // BMLT Minutes names its own keys; use them when it is the plugin providing the post type.
        $known = [];
        if ( class_exists( 'BMLT_Minutes' ) && defined( 'BMLT_Minutes::CPT' ) && BMLT_Minutes::CPT === $post_type ) {
            $known = [
                'date' => defined( 'BMLT_Minutes::META_DATE' ) ? (string) BMLT_Minutes::META_DATE : '',
                'file' => defined( 'BMLT_Minutes::META_ATTACHMENT' ) ? (string) BMLT_Minutes::META_ATTACHMENT : '',
                'url'  => defined( 'BMLT_Minutes::META_URL' ) ? (string) BMLT_Minutes::META_URL : '',
            ];
        }

        foreach ( array_keys( $result ) as $kind ) {
            $saved_key = self::clean_meta_key( (string) ( $saved[ $kind ] ?? '' ) );
            if ( '' !== $saved_key ) {
                $result[ $kind ] = $saved_key;
                continue;
            }
            if ( ! empty( $known[ $kind ] ) ) {
                $result[ $kind ] = $known[ $kind ];
                continue;
            }
            $best = '';
            $best_score = -999;
            foreach ( $discovered as $key ) {
                $score = $this->score_meta_key( $key, $kind );
                if ( $score > $best_score ) {
                    $best_score = $score;
                    $best = $key;
                }
            }
            if ( $best && $best_score >= 7 ) {
                $result[ $kind ] = $best;
            } else {
                $candidates = $this->candidate_meta_keys( $kind );
                $result[ $kind ] = $candidates[0] ?? '';
            }
        }
        return $result;
    }

    /**
     * A meta key as typed on the mapping form: letters, digits, underscore and hyphen (letter case kept).
     */
    private static function clean_meta_key( string $value ): string {
        return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( $value ) );
    }

    public function render_page(): void {
        $post_type = $this->detect_post_type();
        if ( ! $post_type ) {
            echo '<div class="wrap"><h1>Import Minutes Archive</h1>';
            $this->render_result_notice();
            echo '<div class="notice notice-error"><p>BMLT Minutes must be active before this importer can be used.</p></div></div>';
            return;
        }
        if ( ! current_user_can( $this->edit_capability( $post_type ) ) ) {
            wp_die( esc_html__( 'You do not have permission to import minutes.', 'bmlt-minutes-archive-importer' ) );
        }

        $taxonomy = $this->detect_taxonomy( $post_type );
        $terms = $taxonomy ? get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] ) : [];
        $mapping = $this->resolve_mapping( $post_type );
        $discovered = $this->discover_meta_keys( $post_type );
        $year = (int) current_time( 'Y' );
        $month = (int) current_time( 'n' );
        $day = (int) current_time( 'j' );
        $tab = isset( $_GET['tab'] ) && 'bulk' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'bulk' : 'single'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses the tab to display; nothing is changed.

        echo '<div class="wrap"><h1>Import Minutes Archive</h1>';
        $this->render_result_notice();

        if ( ! $taxonomy || is_wp_error( $terms ) ) {
            echo '<div class="notice notice-error"><p>Could not locate the Committee taxonomy used by BMLT Minutes.</p></div></div>';
            return;
        }
        if ( empty( $terms ) ) {
            echo '<div class="notice notice-warning"><p>No committees are configured in BMLT Minutes yet. Add committees under Minutes → Committees first.</p></div></div>';
            return;
        }

        $base = add_query_arg( [ 'page' => 'bmlt-minutes-archive-importer' ], $this->admin_page_url() );
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px">';
        echo '<a class="nav-tab ' . ( 'single' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base ) . '">Single Import</a>';
        echo '<a class="nav-tab ' . ( 'bulk' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'tab', 'bulk', $base ) ) . '">Bulk Import</a>';
        echo '</nav>';

        if ( 'bulk' === $tab ) {
            $this->render_bulk_form( $terms, $taxonomy, $year, $month, $day );
        } else {
            $this->render_single_form( $terms, $taxonomy, $year, $month, $day );
        }

        echo '<details style="margin-top:24px;max-width:1000px"><summary><strong>Compatibility / advanced mapping</strong></summary>';
        echo '<p>The importer automatically detects BMLT Minutes metadata. Only change these fields if your installed BMLT Minutes version uses customized metadata keys.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="background:#fff;border:1px solid #c3c4c7;padding:16px">';
        wp_nonce_field( 'bmai_save_mapping', 'bmai_mapping_nonce' );
        echo '<input type="hidden" name="action" value="bmai_save_mapping">';
        foreach ( [ 'date' => 'Meeting date meta key', 'file' => 'File attachment ID meta key', 'url' => 'External URL meta key' ] as $kind => $label ) {
            echo '<p><label><strong>' . esc_html( $label ) . '</strong><br><input type="text" class="regular-text" name="mapping[' . esc_attr( $kind ) . ']" value="' . esc_attr( $mapping[ $kind ] ) . '" list="bmai-meta-keys"></label></p>';
        }
        echo '<datalist id="bmai-meta-keys">';
        foreach ( $discovered as $key ) echo '<option value="' . esc_attr( $key ) . '">';
        echo '</datalist>';
        submit_button( 'Save Mapping', 'secondary' );
        echo '<p class="description">Detected post type: <code>' . esc_html( $post_type ) . '</code> &nbsp; Committee taxonomy: <code>' . esc_html( $taxonomy ) . '</code></p>';
        echo '</form></details></div>';
    }

    private function render_single_form( array $terms, string $taxonomy, int $year, int $month, int $day ): void {
        echo '<p>Import one historical minutes document into the existing BMLT Minutes archive.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" style="max-width:850px;background:#fff;border:1px solid #c3c4c7;padding:20px;">';
        wp_nonce_field( 'bmai_import', 'bmai_nonce' );
        echo '<input type="hidden" name="action" value="bmai_import">';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="bmai_committee">Committee</label></th><td><select name="committee" id="bmai_committee" required><option value="">— Select Committee —</option>';
        $this->render_term_options( $terms, $taxonomy );
        echo '</select><p class="description">Uses the committees already configured in BMLT Minutes.</p></td></tr>';
        echo '<tr><th scope="row">Meeting date</th><td>' . $this->date_selects_html( '', $year, $month, $day ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- date_selects_html() escapes every value it builds.
        echo '<tr><th scope="row"><label for="bmai_file">Archive document</label></th><td><input type="file" name="archive_file" id="bmai_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.rtf,.csv"><p class="description">Upload the old minutes file. It will be added to the WordPress Media Library.</p></td></tr>';
        echo '<tr><th scope="row"><label for="bmai_url">External link</label></th><td><input type="url" class="regular-text" name="external_url" id="bmai_url" placeholder="https://"><p class="description">Optional alternative to a file upload, for Google Docs, Dropbox, OneDrive, etc.</p></td></tr>';
        echo '<tr><th scope="row"><label for="bmai_title">Title</label></th><td><input type="text" class="regular-text" name="title" id="bmai_title" placeholder="Leave blank to generate automatically"><p class="description">Default: Committee Name — Month Day, Year</p></td></tr>';
        echo '<tr><th scope="row"><label for="bmai_status">Status</label></th><td><select name="post_status" id="bmai_status"><option value="publish">Published</option><option value="draft">Draft</option></select></td></tr>';
        echo '<tr><th scope="row">Duplicates</th><td><label><input type="checkbox" name="allow_duplicate" value="1"> Allow import even if this committee already has minutes on the selected date</label></td></tr>';
        echo '</table>';
        submit_button( 'Import Minutes' );
        echo '</form>';
    }

    private function render_bulk_form( array $terms, string $taxonomy, int $year, int $month, int $day ): void {
        $max_files = max( 1, (int) ini_get( 'max_file_uploads' ) );
        echo '<p>Select multiple archive files, then assign the committee and meeting date for each document before importing. Files can belong to different committees and different years.</p>';
        echo '<form id="bmai-bulk-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" style="max-width:1200px;background:#fff;border:1px solid #c3c4c7;padding:20px;">';
        wp_nonce_field( 'bmai_bulk_import', 'bmai_bulk_nonce' );
        echo '<input type="hidden" name="action" value="bmai_bulk_import">';
        echo '<p><label for="bmai_bulk_files"><strong>Archive documents</strong></label><br><input type="file" id="bmai_bulk_files" name="archive_files[]" multiple required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.rtf,.csv"></p>';
        echo '<p class="description">Your server currently permits up to <strong>' . esc_html( (string) $max_files ) . '</strong> uploaded files per request. After choosing files, the review table will appear below.</p>';
        echo '<div id="bmai-bulk-tools" style="display:none;margin:18px 0;padding:12px;background:#f6f7f7;border:1px solid #dcdcde">';
        echo '<strong>Apply to all rows:</strong> ';
        echo '<select id="bmai-apply-committee"><option value="">— Committee —</option>';
        $this->render_term_options( $terms, $taxonomy );
        echo '</select> ';
        echo $this->date_selects_html( 'apply_', $year, $month, $day, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- date_selects_html() escapes every value it builds.
        echo ' <button type="button" class="button" id="bmai-apply-all">Apply committee &amp; date</button>';
        echo '</div>';
        echo '<div id="bmai-bulk-empty" style="padding:18px;border:1px dashed #c3c4c7;color:#646970">Choose two or more files to build the bulk import table.</div>';
        echo '<div id="bmai-bulk-table-wrap" style="display:none;overflow:auto"><table class="widefat striped" id="bmai-bulk-table"><thead><tr><th style="width:55px">Import</th><th>File</th><th>Committee</th><th>Month</th><th>Day</th><th>Year</th><th>Title (optional)</th></tr></thead><tbody></tbody></table></div>';
        echo '<p style="margin-top:16px"><label><strong>Imported status:</strong> <select name="post_status"><option value="publish">Published</option><option value="draft">Draft</option></select></label></p>';
        echo '<p><label><input type="checkbox" name="allow_duplicate" value="1"> Allow committee/date duplicates for this batch</label></p>';
        echo '<p><button type="submit" class="button button-primary" id="bmai-bulk-submit" disabled>Import Selected Files</button></p>';
        echo '</form>';

        $committee_options = $this->term_options_html( $terms );
        $month_options = $this->month_options_html( $month );
        $day_options = $this->number_options_html( 1, 31, $day );
        $year_options = $this->year_options_html( $year );
        ?>
        <script>
        (function(){
            const input = document.getElementById('bmai_bulk_files');
            const tbody = document.querySelector('#bmai-bulk-table tbody');
            const wrap = document.getElementById('bmai-bulk-table-wrap');
            const empty = document.getElementById('bmai-bulk-empty');
            const tools = document.getElementById('bmai-bulk-tools');
            const submit = document.getElementById('bmai-bulk-submit');
            const committeeOptions = <?php echo wp_json_encode( $committee_options ); ?>;
            const monthOptions = <?php echo wp_json_encode( $month_options ); ?>;
            const dayOptions = <?php echo wp_json_encode( $day_options ); ?>;
            const yearOptions = <?php echo wp_json_encode( $year_options ); ?>;

            function esc(s){return String(s).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));}
            function buildRows(){
                tbody.innerHTML = '';
                const files = Array.from(input.files || []);
                if (!files.length) {
                    wrap.style.display='none'; tools.style.display='none'; empty.style.display='block'; submit.disabled=true; return;
                }
                files.forEach((file, i) => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<td><input type="checkbox" name="rows['+i+'][include]" value="1" checked aria-label="Import '+esc(file.name)+'"></td>'+
                        '<td><strong>'+esc(file.name)+'</strong><input type="hidden" name="rows['+i+'][filename]" value="'+esc(file.name)+'"><br><small>'+Math.max(1,Math.round(file.size/1024))+' KB</small></td>'+
                        '<td><select name="rows['+i+'][committee]" required><option value="">— Select —</option>'+committeeOptions+'</select></td>'+
                        '<td><select name="rows['+i+'][month]" required>'+monthOptions+'</select></td>'+
                        '<td><select name="rows['+i+'][day]" required>'+dayOptions+'</select></td>'+
                        '<td><select name="rows['+i+'][year]" required>'+yearOptions+'</select></td>'+
                        '<td><input type="text" class="regular-text" name="rows['+i+'][title]" placeholder="Auto: Date-Committee-Filename"></td>';
                    tbody.appendChild(tr);
                });
                wrap.style.display='block'; tools.style.display='block'; empty.style.display='none'; submit.disabled=false;
            }
            input.addEventListener('change', buildRows);
            document.getElementById('bmai-apply-all').addEventListener('click', function(){
                const c = document.getElementById('bmai-apply-committee').value;
                const m = document.querySelector('[name="apply_month"]').value;
                const d = document.querySelector('[name="apply_day"]').value;
                const y = document.querySelector('[name="apply_year"]').value;
                tbody.querySelectorAll('tr').forEach(tr => {
                    if(c) tr.querySelector('select[name$="[committee]"]').value = c;
                    tr.querySelector('select[name$="[month]"]').value = m;
                    tr.querySelector('select[name$="[day]"]').value = d;
                    tr.querySelector('select[name$="[year]"]').value = y;
                });
            });
        })();
        </script>
        <?php
    }

    private function date_selects_html( string $prefix, int $year, int $month, int $day, bool $labels = true ): string {
        $style = 'display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap';
        $html = '<span style="' . esc_attr( $style ) . '">';
        $html .= '<select name="' . esc_attr( $prefix . 'month' ) . '" required aria-label="Month">' . $this->month_options_html( $month ) . '</select>';
        $html .= '<select name="' . esc_attr( $prefix . 'day' ) . '" required aria-label="Day">' . $this->number_options_html( 1, 31, $day ) . '</select>';
        $html .= '<select name="' . esc_attr( $prefix . 'year' ) . '" required aria-label="Year">' . $this->year_options_html( $year ) . '</select>';
        $html .= '</span>';
        return $html;
    }

    private function month_options_html( int $selected ): string {
        $html = '';
        for ( $m = 1; $m <= 12; $m++ ) {
            $html .= '<option value="' . esc_attr( (string) $m ) . '" ' . selected( $m, $selected, false ) . '>' . esc_html( date_i18n( 'F', mktime( 0, 0, 0, $m, 1 ) ) ) . '</option>';
        }
        return $html;
    }

    private function number_options_html( int $start, int $end, int $selected ): string {
        $html = '';
        for ( $n = $start; $n <= $end; $n++ ) {
            $html .= '<option value="' . esc_attr( (string) $n ) . '" ' . selected( $n, $selected, false ) . '>' . esc_html( (string) $n ) . '</option>';
        }
        return $html;
    }

    private function year_options_html( int $selected ): string {
        $current = (int) current_time( 'Y' );
        $html = '';
        for ( $y = $current; $y >= self::FIRST_YEAR; $y-- ) {
            $html .= '<option value="' . esc_attr( (string) $y ) . '" ' . selected( $y, $selected, false ) . '>' . esc_html( (string) $y ) . '</option>';
        }
        return $html;
    }

    private function term_options_html( array $terms ): string {
        $by_parent = [];
        foreach ( $terms as $term ) $by_parent[ (int) $term->parent ][] = $term;
        $html = '';
        $walker = function( int $parent, int $depth ) use ( &$walker, &$html, $by_parent ) {
            if ( empty( $by_parent[ $parent ] ) ) return;
            usort( $by_parent[ $parent ], fn( $a, $b ) => strcasecmp( $a->name, $b->name ) );
            foreach ( $by_parent[ $parent ] as $term ) {
                $html .= '<option value="' . esc_attr( $term->term_id ) . '">' . esc_html( str_repeat( '— ', $depth ) . $term->name ) . '</option>';
                $walker( (int) $term->term_id, $depth + 1 );
            }
        };
        $walker( 0, 0 );
        return $html;
    }

    private function render_term_options( array $terms, string $taxonomy ): void {
        echo $this->term_options_html( $terms ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function render_result_notice(): void {
        // The message is kept server-side for the user who caused it, so a link cannot put words on this page.
        if ( ! empty( $_GET['bmai_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag only; the notice itself is stored server-side for this user.
            $key = self::NOTICE_PREFIX . get_current_user_id();
            $notice = get_transient( $key );
            delete_transient( $key );
            if ( is_array( $notice ) && isset( $notice['result'], $notice['message'] ) ) {
                $result = (string) $notice['result'];
                $class = 'success' === $result ? 'notice-success' : ( 'warning' === $result ? 'notice-warning' : 'notice-error' );
                echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
            }
        }

        if ( isset( $_GET['bmai_bulk_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag only; the report is stored server-side for this user.
            $key = self::BULK_RESULT_PREFIX . get_current_user_id();
            $report = get_transient( $key );
            delete_transient( $key );
            if ( is_array( $report ) ) {
                $ok = (int) ( $report['success'] ?? 0 );
                $failed = (int) ( $report['failed'] ?? 0 );
                $skipped = (int) ( $report['skipped'] ?? 0 );
                $class = $failed ? ( $ok ? 'notice-warning' : 'notice-error' ) : 'notice-success';
                echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p><strong>Bulk import complete:</strong> ' . esc_html( "$ok imported, $failed failed, $skipped skipped." ) . '</p>';
                if ( ! empty( $report['messages'] ) ) {
                    echo '<details style="margin:8px 0 12px"><summary>View batch details</summary><ul style="list-style:disc;padding-left:22px">';
                    foreach ( array_slice( $report['messages'], 0, 100 ) as $msg ) echo '<li>' . esc_html( $msg ) . '</li>';
                    echo '</ul></details>';
                }
                echo '</div>';
            }
        }
    }

    public function save_mapping(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'bmai_save_mapping', 'bmai_mapping_nonce' );
        $mapping = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value goes through clean_meta_key() below.
        $clean = [];
        foreach ( [ 'date', 'file', 'url' ] as $kind ) {
            $clean[ $kind ] = isset( $mapping[ $kind ] ) && is_scalar( $mapping[ $kind ] ) ? self::clean_meta_key( (string) $mapping[ $kind ] ) : '';
        }
        update_option( self::OPTION, $clean, false );
        $this->redirect_result( 'success', 'Compatibility mapping saved.' );
    }

    /**
     * A request bigger than post_max_size arrives with empty $_POST and $_FILES (and so without its nonce).
     */
    private function reject_oversized_request(): void {
        $length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ( empty( $_POST ) && empty( $_FILES ) && $length > 0 && is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only checks that the body was dropped (too large), before the nonce can be read; nothing is changed.
            $this->redirect_result( 'error', sprintf( 'The upload (%s) was larger than this server accepts in one request (post_max_size %s). Import fewer or smaller files at a time.', size_format( $length ), (string) ini_get( 'post_max_size' ) ) );
        }
    }

    /**
     * admin-post.php without an action: if it was our form, explain why nothing was received.
     */
    public function handle_dropped_request(): void {
        $referer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with our page name.
        if ( str_contains( $referer, 'page=bmlt-minutes-archive-importer' ) ) {
            $this->reject_oversized_request();
        }
    }

    public function handle_import(): void {
        $this->reject_oversized_request();
        check_admin_referer( 'bmai_import', 'bmai_nonce' );
        $context = $this->import_context_or_die();
        $committee_id = isset( $_POST['committee'] ) ? absint( $_POST['committee'] ) : 0;
        $day   = isset( $_POST['day'] ) ? absint( $_POST['day'] ) : 0;
        $month = isset( $_POST['month'] ) ? absint( $_POST['month'] ) : 0;
        $year  = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : 0;
        $status = isset( $_POST['post_status'] ) && 'draft' === $_POST['post_status'] ? 'draft' : 'publish';
        $external_url = isset( $_POST['external_url'] ) ? esc_url_raw( wp_unslash( $_POST['external_url'] ) ) : '';
        $allow_duplicate = ! empty( $_POST['allow_duplicate'] );
        $custom_title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $has_file = isset( $_FILES['archive_file']['error'] ) && is_scalar( $_FILES['archive_file']['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES['archive_file']['error'];

        if ( ! $has_file && empty( $external_url ) ) $this->redirect_result( 'error', 'Choose an archive document or enter an external link.' );
        if ( $has_file && ! empty( $external_url ) ) $this->redirect_result( 'error', 'Use either an uploaded document or an external link, not both.' );

        $result = $this->import_item( $context, [
            'committee_id' => $committee_id,
            'day' => $day, 'month' => $month, 'year' => $year,
            'status' => $status,
            'title' => $custom_title,
            'external_url' => $external_url,
            'file' => $has_file ? $_FILES['archive_file'] : null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The upload goes to media_handle_upload(), which validates it.
            'allow_duplicate' => $allow_duplicate,
        ] );

        if ( is_wp_error( $result ) ) {
            $code = $result->get_error_code();
            $this->redirect_result( 'duplicate' === $code ? 'warning' : 'error', $result->get_error_message() );
        }
        if ( 'publish' === $status && 'publish' !== get_post_status( $result ) ) {
            $this->redirect_result( 'warning', 'Minutes imported as a draft: your account may not publish minutes.' );
        }
        $this->redirect_result( 'success', 'Minutes imported successfully. You can edit the imported record from the Minutes screen.' );
    }

    public function handle_bulk_import(): void {
        $this->reject_oversized_request();
        check_admin_referer( 'bmai_bulk_import', 'bmai_bulk_nonce' );
        $context = $this->import_context_or_die();
        $rows = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized where used (absint, sanitize_text_field, sanitize_file_name).
        $files = $this->normalize_uploaded_files( $_FILES['archive_files'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Uploads go to media_handle_upload(), which validates them.
        $status = isset( $_POST['post_status'] ) && 'draft' === $_POST['post_status'] ? 'draft' : 'publish';
        $allow_duplicate = ! empty( $_POST['allow_duplicate'] );
        $report = [ 'success' => 0, 'failed' => 0, 'skipped' => 0, 'messages' => [] ];

        if ( empty( $files ) ) {
            $report['failed']++;
            $report['messages'][] = 'No archive files were received. Your server upload limits may have been exceeded.';
        }
        // PHP drops files beyond max_file_uploads without an error: report every included row that has no file.
        foreach ( $rows as $i => $row ) {
            if ( is_array( $row ) && ! empty( $row['include'] ) && ! isset( $files[ $i ] ) && ! empty( $files ) ) {
                $report['failed']++;
                $name = sanitize_file_name( (string) ( is_scalar( $row['filename'] ?? null ) ? $row['filename'] : 'File ' . ( (int) $i + 1 ) ) );
                $report['messages'][] = $name . ': not received by the server (it accepts ' . (int) ini_get( 'max_file_uploads' ) . ' files per request).';
            }
        }

        foreach ( $files as $i => $file ) {
            $row = isset( $rows[ $i ] ) && is_array( $rows[ $i ] ) ? $rows[ $i ] : [];
            $filename = sanitize_file_name( (string) ( $file['name'] ?? ( $row['filename'] ?? 'File ' . ( $i + 1 ) ) ) );
            if ( empty( $row['include'] ) ) {
                $report['skipped']++;
                $report['messages'][] = $filename . ': skipped by user.';
                continue;
            }
            if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
                $report['failed']++;
                $report['messages'][] = $filename . ': upload error (' . $this->upload_error_message( (int) $file['error'] ) . ').';
                continue;
            }

            $result = $this->import_item( $context, [
                'committee_id' => absint( $row['committee'] ?? 0 ),
                'day' => absint( $row['day'] ?? 0 ),
                'month' => absint( $row['month'] ?? 0 ),
                'year' => absint( $row['year'] ?? 0 ),
                'status' => $status,
                'title' => is_scalar( $row['title'] ?? '' ) ? sanitize_text_field( (string) ( $row['title'] ?? '' ) ) : '',
                'external_url' => '',
                'file' => $file,
                'source_filename' => $filename,
                'bulk_import' => true,
                'allow_duplicate' => $allow_duplicate,
            ] );

            if ( is_wp_error( $result ) ) {
                $report['failed']++;
                $report['messages'][] = $filename . ': ' . $result->get_error_message();
            } else {
                $report['success']++;
                $report['messages'][] = $filename . ( 'publish' === $status && 'publish' !== get_post_status( $result ) ? ': imported as a draft (your account may not publish minutes).' : ': imported successfully.' );
            }
        }

        set_transient( self::BULK_RESULT_PREFIX . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
        $url = add_query_arg( [
            'page' => 'bmlt-minutes-archive-importer',
            'tab' => 'bulk',
            'bmai_bulk_result' => 1,
        ], $this->admin_page_url() );
        wp_safe_redirect( $url );
        exit;
    }

    private function import_context_or_die(): array {
        $post_type = $this->detect_post_type();
        if ( ! $post_type ) $this->redirect_result( 'error', 'BMLT Minutes is not active or its post type could not be detected.' );
        if ( ! current_user_can( $this->edit_capability( $post_type ) ) ) wp_die( 'Not allowed.' );
        $taxonomy = $this->detect_taxonomy( $post_type );
        if ( ! $taxonomy ) $this->redirect_result( 'error', 'Could not detect the BMLT Minutes Committee taxonomy.' );
        $obj = get_post_type_object( $post_type );
        $publish_cap = ( $obj && isset( $obj->cap->publish_posts ) ) ? $obj->cap->publish_posts : 'publish_posts';
        return [
            'post_type' => $post_type,
            'taxonomy' => $taxonomy,
            'mapping' => $this->resolve_mapping( $post_type ),
            'can_publish' => current_user_can( $publish_cap ),
            'can_upload' => current_user_can( 'upload_files' ),
        ];
    }

    /**
     * @return int|WP_Error The minutes post ID.
     */
    private function import_item( array $context, array $item ) {
        $post_type = $context['post_type'];
        $taxonomy = $context['taxonomy'];
        $mapping = $context['mapping'];
        $committee_id = absint( $item['committee_id'] ?? 0 );
        $day = absint( $item['day'] ?? 0 );
        $month = absint( $item['month'] ?? 0 );
        $year = absint( $item['year'] ?? 0 );

        if ( ! $committee_id || ! term_exists( $committee_id, $taxonomy ) ) return new WP_Error( 'invalid_committee', 'Please select a valid committee.' );
        // A user BMLT Minutes limits to some committees may import only into those.
        if ( class_exists( 'BMLT_Minutes' ) && is_callable( [ 'BMLT_Minutes', 'committee_scope' ] ) ) { // Older BMLT Minutes versions have no committee scopes.
            $scope = BMLT_Minutes::committee_scope( get_current_user_id() );
            if ( null !== $scope && ! in_array( $committee_id, array_map( 'intval', $scope ), true ) ) {
                return new WP_Error( 'committee_scope', 'You can only import minutes for your own committees.' );
            }
        }
        if ( $year < self::FIRST_YEAR || $year > (int) current_time( 'Y' ) || ! checkdate( $month, $day, $year ) ) return new WP_Error( 'invalid_date', 'Please select a valid meeting date.' );
        $date = sprintf( '%04d-%02d-%02d', $year, $month, $day );
        if ( is_array( $item['file'] ?? null ) && empty( $context['can_upload'] ) ) {
            return new WP_Error( 'upload_cap', 'Your account may not upload files. Use an external link instead.' );
        }

        if ( empty( $item['allow_duplicate'] ) && $this->duplicate_exists( $post_type, $taxonomy, $committee_id, $date, $mapping['date'] ) ) {
            return new WP_Error( 'duplicate', 'Minutes already exist for that committee and meeting date.' );
        }

        $term = get_term( $committee_id, $taxonomy );
        if ( is_wp_error( $term ) ) return new WP_Error( 'term_load', 'Could not load the selected committee.' );
        $custom_title = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
        if ( $custom_title ) {
            $title = $custom_title;
        } elseif ( ! empty( $item['bulk_import'] ) ) {
            $source_filename = sanitize_file_name( (string) ( $item['source_filename'] ?? '' ) );
            $filename_no_ext = pathinfo( $source_filename, PATHINFO_FILENAME );
            $filename_no_ext = sanitize_text_field( $filename_no_ext );
            $title = sprintf( '%s-%s-%s', $date, $term->name, $filename_no_ext ?: 'Minutes' );
        } else {
            $title = sprintf( '%s — %s', $term->name, date_i18n( 'F j, Y', strtotime( $date . ' 12:00:00' ) ) );
        }
        $status = ( isset( $item['status'] ) && 'draft' === $item['status'] ) ? 'draft' : 'publish';
        if ( 'publish' === $status && array_key_exists( 'can_publish', $context ) && ! $context['can_publish'] ) {
            $status = 'draft';
        }
        // Noon on the meeting day, unless that is still ahead (today before noon): a post dated in the future
        // would be scheduled instead of published.
        $post_date = $date . ' 12:00:00';
        $now = current_time( 'mysql' );
        if ( $post_date > $now ) {
            $post_date = $now;
        }

        $post_id = wp_insert_post( [
            'post_type'   => $post_type,
            'post_title'  => $title,
            'post_status' => $status,
            'post_author' => get_current_user_id(),
            'post_date'   => $post_date,
        ], true );
        if ( is_wp_error( $post_id ) ) return new WP_Error( 'insert', 'Could not create minutes record: ' . $post_id->get_error_message() );

        $assigned = wp_set_object_terms( $post_id, [ $committee_id ], $taxonomy, false );
        if ( is_wp_error( $assigned ) ) {
            wp_delete_post( $post_id, true );
            return new WP_Error( 'term_assign', 'Could not assign the committee: ' . $assigned->get_error_message() );
        }
        if ( ! has_term( $committee_id, $taxonomy, $post_id ) ) {
            // Another plugin removed it (BMLT Minutes does for committees outside the user's scope).
            wp_delete_post( $post_id, true );
            return new WP_Error( 'term_assign', 'The committee could not be assigned to the imported minutes.' );
        }

        update_post_meta( $post_id, $mapping['date'], $date );
        $file = $item['file'] ?? null;
        $external_url = esc_url_raw( (string) ( $item['external_url'] ?? '' ) );
        if ( is_array( $file ) ) {
            $attachment_id = $this->upload_specific_file( $post_id, $file );
            if ( is_wp_error( $attachment_id ) ) {
                wp_delete_post( $post_id, true );
                return new WP_Error( 'upload', 'Upload failed: ' . $attachment_id->get_error_message() );
            }
            update_post_meta( $post_id, $mapping['file'], (int) $attachment_id );
        } elseif ( $external_url ) {
            update_post_meta( $post_id, $mapping['url'], $external_url );
        } else {
            wp_delete_post( $post_id, true );
            return new WP_Error( 'source', 'No archive document or external URL was supplied.' );
        }

        do_action( 'bmai_after_import', $post_id, [
            'date' => $date,
            'committee_id' => $committee_id,
            'taxonomy' => $taxonomy,
        ] );
        return $post_id;
    }

    private function duplicate_exists( string $post_type, string $taxonomy, int $committee_id, string $date, string $date_key ): bool {
        $query = new WP_Query( [
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'tax_query'      => [ [ 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => [ $committee_id ] ] ],
            'meta_query'     => [ [ 'key' => $date_key, 'value' => $date, 'compare' => '=' ] ],
            'no_found_rows'  => true,
        ] );
        return $query->have_posts();
    }

    /**
     * @return int|WP_Error The attachment ID.
     */
    private function upload_specific_file( int $post_id, array $file ) {
        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        // Only the document types BMLT Minutes accepts, whatever the browser's file picker allowed.
        $mimes = [];
        foreach ( get_allowed_mime_types() as $exts => $mime ) {
            $allowed = array_intersect( explode( '|', $exts ), self::DOC_EXTENSIONS );
            if ( $allowed ) {
                $mimes[ implode( '|', $allowed ) ] = $mime;
            }
        }
        /**
         * Overrides passed to media_handle_upload() for archive documents.
         *
         * @param array $overrides wp_handle_upload() overrides.
         * @param array $file      The uploaded file.
         */
        $overrides = apply_filters( 'bmai_upload_overrides', [ 'test_form' => false, 'mimes' => $mimes ], $file );
        /**
         * Short-circuits the upload: return an attachment ID or a WP_Error to use instead of media_handle_upload().
         *
         * @param int|WP_Error|null $result    null to upload as usual.
         * @param array             $file      The uploaded file.
         * @param int               $post_id   The minutes record the document belongs to.
         * @param array             $overrides wp_handle_upload() overrides.
         */
        $pre = apply_filters( 'bmai_pre_upload', null, $file, $post_id, $overrides );
        if ( null !== $pre ) {
            return $pre;
        }
        $key = 'bmai_archive_upload_' . wp_generate_password( 8, false, false );
        $_FILES[ $key ] = $file;
        $result = media_handle_upload( $key, $post_id, [], $overrides );
        unset( $_FILES[ $key ] );
        return $result;
    }

    private function normalize_uploaded_files( array $files ): array {
        if ( empty( $files['name'] ) || ! is_array( $files['name'] ) ) return [];
        $normalized = [];
        foreach ( $files['name'] as $i => $name ) {
            $normalized[ $i ] = [
                'name' => $name,
                'full_path' => $files['full_path'][ $i ] ?? $name,
                'type' => $files['type'][ $i ] ?? '',
                'tmp_name' => $files['tmp_name'][ $i ] ?? '',
                'error' => $files['error'][ $i ] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][ $i ] ?? 0,
            ];
        }
        return $normalized;
    }

    private function upload_error_message( int $code ): string {
        return match ( $code ) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file exceeds upload size limit',
            UPLOAD_ERR_PARTIAL => 'file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'no file received',
            UPLOAD_ERR_NO_TMP_DIR => 'temporary upload folder is missing',
            UPLOAD_ERR_CANT_WRITE => 'server could not write the file',
            UPLOAD_ERR_EXTENSION => 'a PHP extension stopped the upload',
            default => 'unknown upload error',
        };
    }

    private function redirect_result( string $result, string $message ): void {
        set_transient( self::NOTICE_PREFIX . get_current_user_id(), [ 'result' => $result, 'message' => $message ], 5 * MINUTE_IN_SECONDS );
        $url = add_query_arg( [
            'page'        => 'bmlt-minutes-archive-importer',
            'bmai_result' => 1,
        ], $this->admin_page_url() );
        wp_safe_redirect( $url );
        exit;
    }

    private function admin_page_url(): string {
        $post_type = $this->detect_post_type();
        if ( $post_type ) return admin_url( 'edit.php?post_type=' . $post_type );
        return admin_url( 'tools.php' );
    }
}

new BMLT_Minutes_Archive_Importer();
