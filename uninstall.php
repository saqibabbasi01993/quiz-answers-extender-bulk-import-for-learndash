<?php
/**
 * Uninstall: remove data that only this plugin uses.
 *
 * Answer explanations (post meta on sfwd-question posts) are kept by default so that
 * content written by authors is not lost if the plugin is reinstalled. To delete them
 * as well, add this line to wp-config.php before uninstalling:
 *
 *     define( 'LDAE_DELETE_EXPLANATIONS_ON_UNINSTALL', true );
 *
 * @package LDAE
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_metadata( 'user', 0, '_ldae_completed_quizzes', '', true );

if ( defined( 'LDAE_DELETE_EXPLANATIONS_ON_UNINSTALL' ) && LDAE_DELETE_EXPLANATIONS_ON_UNINSTALL ) {
	delete_metadata( 'post', 0, '_ldae_answer_explanations', '', true );

	// Explanations of ProQuiz questions without a question post.
	global $wpdb;
	$ldae_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'ldae_pro_explanations_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $ldae_names as $ldae_name ) {
		delete_option( $ldae_name );
	}
}

// Import reports are short-lived transients (15 minutes); remove any left behind.
global $wpdb;
$ldae_like = $wpdb->esc_like( '_transient_ldae_import_' ) . '%';
$ldae_keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $ldae_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $ldae_keys as $ldae_key ) {
	delete_transient( substr( $ldae_key, strlen( '_transient_' ) ) );
}
