<?php
/**
 * Plugin Name:       Quiz Answers Extender & Bulk Import for LearnDash
 * Description:       Show an explanation for every answer option of LearnDash "Single Choice" questions on the quiz results "View Questions" review, and bulk import quiz questions from a CSV file.
 * Version:           1.0.4
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Saqib K.
 * Author URI:        https://www.skfreelancers.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       quiz-answers-extender-bulk-import-for-learndash
 *
 * @package LDAE
 */

defined( 'ABSPATH' ) || exit;

define( 'LDAE_VERSION', '1.0.4' );
define( 'LDAE_FILE', __FILE__ );
define( 'LDAE_PATH', plugin_dir_path( __FILE__ ) );
define( 'LDAE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Post meta key (on sfwd-question posts) holding explanations.
 * Stored as array( answer_index => explanation ), where answer_index is the
 * position of the answer inside the ProQuiz answer_data array.
 */
define( 'LDAE_META_KEY', '_ldae_answer_explanations' );

/**
 * Option name prefix for explanations of ProQuiz questions that have no
 * sfwd-question post (option name = prefix + ProQuiz question ID).
 */
define( 'LDAE_PRO_OPTION_PREFIX', 'ldae_pro_explanations_' );

/*
 * LearnDash is a commercial plugin that is not hosted on WordPress.org, so the
 * "Requires Plugins" header cannot be used (WordPress.org only allows dependencies that
 * are hosted there). An activation check gives the same protection instead.
 */
register_activation_hook( __FILE__, 'ldae_activation_check' );

/**
 * Whether LearnDash LMS is active.
 *
 * @return bool
 */
function ldae_is_learndash_active() {
	if ( defined( 'LEARNDASH_VERSION' ) || class_exists( 'SFWD_LMS' ) ) {
		return true;
	}
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$learndash = 'sfwd-lms/sfwd_lms.php';
	return is_plugin_active( $learndash ) || ( is_multisite() && is_plugin_active_for_network( $learndash ) );
}

/**
 * Refuse activation when LearnDash LMS is not active.
 */
function ldae_activation_check() {
	if ( ldae_is_learndash_active() ) {
		return;
	}
	wp_die(
		'<p>' . esc_html__( 'Quiz Answers Extender & Bulk Import for LearnDash could not be activated because it requires LearnDash LMS.', 'quiz-answers-extender-bulk-import-for-learndash' ) . '</p>' .
		'<p>' . esc_html__( 'Please install and activate LearnDash LMS first, then activate this plugin again.', 'quiz-answers-extender-bulk-import-for-learndash' ) . '</p>',
		esc_html__( 'LearnDash LMS required', 'quiz-answers-extender-bulk-import-for-learndash' ),
		array( 'back_link' => true )
	);
}

require_once LDAE_PATH . 'includes/class-ldae-helpers.php';
require_once LDAE_PATH . 'includes/class-ldae-admin-editor.php';
require_once LDAE_PATH . 'includes/class-ldae-frontend.php';
require_once LDAE_PATH . 'includes/class-ldae-importer.php';

/**
 * Boot the plugin once all plugins are loaded (LearnDash defines LEARNDASH_VERSION).
 */
function ldae_bootstrap() {
	if ( ! defined( 'LEARNDASH_VERSION' ) ) {
		add_action( 'admin_notices', 'ldae_missing_learndash_notice' );
		return;
	}

	LDAE_Admin_Editor::init();
	LDAE_Frontend::init();
	LDAE_Importer::init();
}
add_action( 'plugins_loaded', 'ldae_bootstrap', 20 );

/**
 * Admin notice when LearnDash is not active.
 */
function ldae_missing_learndash_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'Quiz Answers Extender & Bulk Import for LearnDash requires LearnDash LMS to be installed and active.', 'quiz-answers-extender-bulk-import-for-learndash' ) .
		'</p></div>';
}
