<?php
/**
 * Frontend: shows explanations under each answer in the results "View Questions" review.
 *
 * Explanations are NOT printed into the quiz markup (that would let students read them
 * in the page source before answering). They are fetched over AJAX when "View Questions"
 * is clicked, and the server only returns them once the user has completed the quiz.
 *
 * @package LDAE
 */

defined( 'ABSPATH' ) || exit;

/**
 * Frontend: explanations on the quiz results review.
 */
final class LDAE_Frontend {

	const NONCE          = 'ldae_front';
	const COMPLETED_META = '_ldae_completed_quizzes';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_ldae_get_quiz_explanations', array( __CLASS__, 'ajax_get_quiz_explanations' ) );
		add_action( 'wp_ajax_nopriv_ldae_get_quiz_explanations', array( __CLASS__, 'ajax_get_quiz_explanations' ) );
		add_action( 'learndash_quiz_completed', array( __CLASS__, 'mark_completed' ), 10, 2 );
	}

	/**
	 * Enqueue frontend assets on pages that can render a quiz.
	 */
	public static function enqueue() {
		if ( ! self::should_enqueue() ) {
			return;
		}
		wp_enqueue_style( 'ldae-frontend', LDAE_URL . 'assets/css/frontend.css', array(), LDAE_VERSION );
		wp_enqueue_script( 'ldae-frontend', LDAE_URL . 'assets/js/frontend.js', array( 'jquery' ), LDAE_VERSION, true );
		wp_localize_script(
			'ldae-frontend',
			'LDAE_Front',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'label'   => __( 'Explanation', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'loading' => __( 'Loading...', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'states'  => array(
						'correct'   => __( 'Why this is correct', 'quiz-answers-extender-bulk-import-for-learndash' ),
						'incorrect' => __( 'Why your answer is incorrect', 'quiz-answers-extender-bulk-import-for-learndash' ),
						'neutral'   => __( 'Explanation', 'quiz-answers-extender-bulk-import-for-learndash' ),
					),
				),
			)
		);
	}

	/**
	 * Only load assets where a quiz can be rendered.
	 *
	 * @return bool
	 */
	private static function should_enqueue() {
		$enqueue = false;
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				$ld_types = array( 'sfwd-quiz', 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic' );
				$enqueue  = in_array( $post->post_type, $ld_types, true )
					|| has_shortcode( $post->post_content, 'ld_quiz' )
					|| ( function_exists( 'has_block' ) && has_block( 'learndash/ld-quiz', $post ) );
			}
		}
		return (bool) apply_filters( 'ldae_enqueue_frontend', $enqueue );
	}

	/**
	 * Remember that a logged-in user completed a quiz (LearnDash fires this after saving results).
	 *
	 * @param array        $quizdata Quiz result data.
	 * @param WP_User|null $user     User.
	 */
	public static function mark_completed( $quizdata, $user = null ) {
		$user_id = ( $user instanceof WP_User ) ? (int) $user->ID : get_current_user_id();
		if ( ! $user_id || ! is_array( $quizdata ) || empty( $quizdata['quiz'] ) ) {
			return;
		}
		$quiz    = $quizdata['quiz'];
		$quiz_id = ( $quiz instanceof WP_Post ) ? (int) $quiz->ID : absint( $quiz );
		if ( ! $quiz_id ) {
			return;
		}
		$done = get_user_meta( $user_id, self::COMPLETED_META, true );
		$done = is_array( $done ) ? $done : array();

		$done[ $quiz_id ] = time();
		update_user_meta( $user_id, self::COMPLETED_META, $done );
	}

	/**
	 * AJAX: explanations for every supported question in a quiz.
	 */
	public static function ajax_get_quiz_explanations() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'code' => 'bad_nonce' ), 403 );
		}

		$quiz_post_id    = isset( $_POST['quiz_post_id'] ) ? absint( wp_unslash( $_POST['quiz_post_id'] ) ) : 0;
		$quiz_pro_id_raw = isset( $_POST['quiz_pro_id'] ) ? absint( wp_unslash( $_POST['quiz_pro_id'] ) ) : 0;

		if ( ! $quiz_post_id && $quiz_pro_id_raw ) {
			$quiz_post_id = LDAE_Helpers::get_quiz_post_id_by_pro_id( $quiz_pro_id_raw );
		}
		if ( ! $quiz_post_id || 'sfwd-quiz' !== get_post_type( $quiz_post_id ) ) {
			wp_send_json_error( array( 'code' => 'invalid_quiz' ), 400 );
		}
		if ( 'publish' !== get_post_status( $quiz_post_id ) && ! current_user_can( 'edit_post', $quiz_post_id ) ) {
			wp_send_json_error( array( 'code' => 'invalid_quiz' ), 400 );
		}
		if ( ! self::can_view( $quiz_post_id ) ) {
			// JS retries a few times: LearnDash may still be saving the attempt.
			wp_send_json_error( array( 'code' => 'not_completed' ), 403 );
		}

		$supported = LDAE_Helpers::supported_types();
		$allowed   = LDAE_Helpers::allowed_html();
		$questions = array();

		foreach ( LDAE_Helpers::get_quiz_question_ids( $quiz_post_id ) as $question_id ) {
			$explanations = LDAE_Helpers::get_explanations_by_pro( LDAE_Helpers::get_question_pro_id( $question_id ), $question_id );
			if ( empty( $explanations ) ) {
				continue;
			}
			if ( ! in_array( LDAE_Helpers::get_answer_type( $question_id ), $supported, true ) ) {
				continue;
			}
			$rendered = array();
			foreach ( $explanations as $index => $text ) {
				if ( '' !== $text ) {
					$rendered[ (string) $index ] = wp_kses( $text, $allowed ); // Escape late.
				}
			}
			if ( $rendered ) {
				$questions[] = array(
					'question_post_id' => (int) $question_id,
					'question_pro_id'  => LDAE_Helpers::get_question_pro_id( $question_id ),
					'explanations'     => $rendered,
				);
			}
		}

		// ProQuiz questions without an sfwd-question post (e.g. created in the legacy editor).
		$quiz_pro_id = LDAE_Helpers::get_quiz_pro_id( $quiz_post_id );
		$quiz_pro_id = $quiz_pro_id ? $quiz_pro_id : $quiz_pro_id_raw;
		$linked      = array_flip( array_filter( array_map( 'absint', LDAE_Helpers::get_quiz_question_map( $quiz_post_id ) ) ) );
		foreach ( $questions as $q ) {
			$linked[ $q['question_pro_id'] ] = true;
		}
		$unlinked = array();
		foreach ( LDAE_Helpers::get_pro_question_ids( $quiz_pro_id ) as $pro_id ) {
			if ( ! isset( $linked[ $pro_id ] ) ) {
				$unlinked[ $pro_id ] = LDAE_Helpers::pro_option_name( $pro_id );
			}
		}
		if ( $unlinked && function_exists( 'wp_prime_option_caches' ) ) {
			wp_prime_option_caches( array_values( $unlinked ) ); // One query instead of one per question.
		}
		foreach ( array_keys( $unlinked ) as $pro_id ) {
			$explanations = LDAE_Helpers::get_unlinked_explanations( $pro_id ); // Also recovers after an ID change.
			if ( ! $explanations ) {
				continue;
			}
			$question = LDAE_Helpers::get_pro_question( $pro_id );
			if ( ! $question || ! in_array( (string) $question->getAnswerType(), $supported, true ) ) {
				continue;
			}
			$rendered = array();
			foreach ( $explanations as $index => $text ) {
				if ( '' !== $text ) {
					$rendered[ (string) $index ] = wp_kses( $text, $allowed );
				}
			}
			if ( $rendered ) {
				$questions[] = array(
					'question_post_id' => 0,
					'question_pro_id'  => (int) $pro_id,
					'explanations'     => $rendered,
				);
			}
		}

		wp_send_json_success( array( 'questions' => $questions ) );
	}

	/**
	 * Whether the current visitor may see the explanations of a quiz.
	 *
	 * @param int $quiz_post_id Quiz post ID.
	 * @return bool
	 */
	private static function can_view( $quiz_post_id ) {
		$user_id = get_current_user_id();

		if ( current_user_can( 'edit_post', $quiz_post_id ) ) {
			$allowed = true;
		} elseif ( $user_id ) {
			$allowed = self::user_completed_quiz( $user_id, $quiz_post_id );
		} else {
			$allowed = self::guest_allowed( $quiz_post_id );
		}

		return (bool) apply_filters( 'ldae_user_can_view_explanations', $allowed, $quiz_post_id, $user_id );
	}

	/**
	 * Whether a user has completed a quiz at least once.
	 *
	 * @param int $user_id      User ID.
	 * @param int $quiz_post_id Quiz post ID.
	 * @return bool
	 */
	private static function user_completed_quiz( $user_id, $quiz_post_id ) {
		$done = get_user_meta( $user_id, self::COMPLETED_META, true );
		if ( is_array( $done ) && isset( $done[ $quiz_post_id ] ) ) {
			return true;
		}
		// LearnDash's own attempt history.
		$attempts = get_user_meta( $user_id, '_sfwd-quizzes', true );
		if ( is_array( $attempts ) ) {
			foreach ( $attempts as $attempt ) {
				if ( is_array( $attempt ) && isset( $attempt['quiz'] ) && is_scalar( $attempt['quiz'] ) && (int) $attempt['quiz'] === (int) $quiz_post_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * LearnDash does not store guest attempts, so guests are allowed only for quizzes
	 * that are not in a course, or in an "open" course. Filterable.
	 *
	 * @param int $quiz_post_id Quiz post ID.
	 * @return bool
	 */
	private static function guest_allowed( $quiz_post_id ) {
		$allowed = true;
		if ( function_exists( 'learndash_get_course_id' ) ) {
			$course_id = (int) learndash_get_course_id( $quiz_post_id );
			if ( $course_id && function_exists( 'learndash_get_setting' ) ) {
				$allowed = ( 'open' === learndash_get_setting( $course_id, 'course_price_type' ) );
			}
		}
		return (bool) apply_filters( 'ldae_allow_guest_explanations', $allowed, $quiz_post_id );
	}
}
