<?php
/**
 * Removes the importer's own settings (on every site of a network). Imported minutes and their documents belong to
 * BMLT Minutes and the Media Library, and are kept.
 *
 * @version 1.3.1
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! function_exists( 'bmai_uninstall_site' ) ) {
    function bmai_uninstall_site(): void {
        global $wpdb;
        delete_option( 'bmai_meta_mapping' );
        // Notices and bulk reports waiting to be shown (bmai_notice_<user>, bmai_bulk_result_<user>).
        $names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_bmai_notice_' ) . '%',
                $wpdb->esc_like( '_transient_bmai_bulk_result_' ) . '%'
            )
        );
        foreach ( $names as $name ) {
            delete_transient( substr( $name, strlen( '_transient_' ) ) );
        }
    }
}

if ( is_multisite() ) {
    foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $bmai_site ) {
        switch_to_blog( (int) $bmai_site );
        bmai_uninstall_site();
        restore_current_blog();
    }
} else {
    bmai_uninstall_site();
}
