<?php
/**
 * Admin integration:
 *  1. Question editor (sfwd-question): an "Answer Explanation" textarea is injected under
 *     every answer row of LearnDash's native ProQuiz answer editor. Values travel to the
 *     server in a single JSON hidden field and are saved after LearnDash has written the
 *     answers (wp_after_insert_post), then re-mapped onto LearnDash's stored answer order.
 *  2. Quiz Builder (sfwd-quiz): an "Answer explanations" panel is attached to every question
 *     row of the builder, loading/saving through nonce-protected AJAX.
 *
 * @package LDAE
 */

defined( 'ABSPATH' ) || exit;

/**
 * Question editor and Quiz Builder integration.
 */
final class LDAE_Admin_Editor {

	const NONCE_ACTION = 'ldae_save_question_explanations';
	const NONCE_FIELD  = 'ldae_question_nonce';
	const AJAX_NONCE   = 'ldae_builder';
	const PAYLOAD      = 'ldae_explanations_payload';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_sfwd-question', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'save_from_editor' ), 20, 2 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_proquiz_save' ) );

		add_action( 'wp_ajax_ldae_get_question_answers', array( __CLASS__, 'ajax_get_answers' ) );
		add_action( 'wp_ajax_ldae_save_question_explanations', array( __CLASS__, 'ajax_save_explanations' ) );
	}

	// Question editor.

	/**
	 * Small meta box that carries the nonce + JSON payload. The textareas themselves
	 * are injected next to each answer by JS.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'ldae-answer-explanations',
			__( 'Answer Explanations', 'quiz-answers-extender-bulk-import-for-learndash' ),
			array( __CLASS__, 'render_meta_box' ),
			'sfwd-question',
			'normal',
			'default'
		);
	}

	/**
	 * Carries the nonce + JSON payload used by the inline fields, and a server-rendered
	 * fallback editor (one field per saved answer) used only if inline injection fails.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" id="ldae-explanations-payload" name="' . esc_attr( self::PAYLOAD ) . '" value="" />';

		echo '<p id="ldae-inline-note" class="description" hidden>' . esc_html__( 'Edit the explanation directly under each answer in the Answers box above, then click Update.', 'quiz-answers-extender-bulk-import-for-learndash' ) . '</p>';

		$type    = LDAE_Helpers::get_answer_type( $post->ID );
		$answers = LDAE_Helpers::get_answers( $post->ID );
		$saved   = LDAE_Helpers::get_explanations( $post->ID );

		echo '<div id="ldae-fallback">';
		if ( ! in_array( $type, LDAE_Helpers::supported_types(), true ) ) {
			echo '<p class="description">' . esc_html__( 'Answer explanations are available for Single Choice questions.', 'quiz-answers-extender-bulk-import-for-learndash' ) . '</p>';
		} elseif ( ! $answers ) {
			echo '<p class="description">' . esc_html__( 'Add the answers and click Update once; an explanation field will then appear for every answer.', 'quiz-answers-extender-bulk-import-for-learndash' ) . '</p>';
		} else {
			foreach ( $answers as $n => $answer ) {
				$id = 'ldae-fallback-' . (int) $answer['index'];
				echo '<div class="ldae-fallback__row">';
				echo '<label for="' . esc_attr( $id ) . '"><strong>' . esc_html(
					/* translators: %d: answer number */
					sprintf( __( 'Answer %d', 'quiz-answers-extender-bulk-import-for-learndash' ), $n + 1 )
				) . ':</strong> ' . esc_html( '' !== $answer['text'] ? $answer['text'] : '—' );
				if ( $answer['correct'] ) {
					echo ' <span class="ldae-fallback__correct">&#10003; ' . esc_html__( 'correct', 'quiz-answers-extender-bulk-import-for-learndash' ) . '</span>';
				}
				echo '</label>';
				printf(
					'<textarea id="%1$s" name="ldae_explanations[%2$d]" rows="2" class="large-text">%3$s</textarea>',
					esc_attr( $id ),
					(int) $answer['index'],
					esc_textarea( isset( $saved[ $answer['index'] ] ) ? $saved[ $answer['index'] ] : '' )
				);
				echo '</div>';
			}
		}
		echo '</div>';
	}

	/**
	 * Enqueue the right script for the question editor or the quiz edit / builder screen.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( self::is_proquiz_question_screen() ) {
			$pro_id = self::proquiz_question_id();
			$type   = '';
			if ( $pro_id ) {
				$question = LDAE_Helpers::get_pro_question( $pro_id );
				$type     = $question ? (string) $question->getAnswerType() : '';
			}
			// Works whether or not the ProQuiz question is linked to an sfwd-question post.
			self::enqueue_editor_assets( LDAE_Helpers::get_explanations_by_pro( $pro_id ), $type );
			return;
		}

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		$post   = get_post();
		if ( ! $screen || ! $post ) {
			return;
		}

		$type = $screen->post_type ? $screen->post_type : $post->post_type;
		if ( 'sfwd-question' === $type ) {
			self::enqueue_question_editor( $post );
		} elseif ( 'sfwd-quiz' === $type ) {
			self::enqueue_quiz_builder( $post );
		}
	}

	/**
	 * Enqueue assets for the sfwd-question editor.
	 *
	 * @param WP_Post $post Question post.
	 */
	private static function enqueue_question_editor( $post ) {
		$saved = LDAE_Helpers::get_explanations_by_pro( LDAE_Helpers::get_question_pro_id( $post->ID ), $post->ID );
		self::enqueue_editor_assets( $saved, LDAE_Helpers::get_answer_type( $post->ID ) );
	}

	/**
	 * Shared by the WordPress question editor (post.php) and LearnDash's legacy
	 * question editor (admin.php?page=ldAdvQuiz&module=question&action=addEdit).
	 *
	 * @param array  $saved       Saved explanations (answer_index => text).
	 * @param string $answer_type Answer type if known.
	 */
	private static function enqueue_editor_assets( array $saved, $answer_type ) {
		wp_enqueue_style( 'ldae-admin', LDAE_URL . 'assets/css/admin.css', array(), LDAE_VERSION );
		wp_enqueue_script( 'ldae-question-editor', LDAE_URL . 'assets/js/admin-question-editor.js', array( 'jquery' ), LDAE_VERSION, true );

		// Explanations as a dense list (index => text) so JS can map row N -> list[N].
		$list = array();
		if ( $saved ) {
			$max = max( array_keys( $saved ) );
			for ( $i = 0; $i <= $max; $i++ ) {
				$list[] = isset( $saved[ $i ] ) ? $saved[ $i ] : '';
			}
		}

		wp_localize_script(
			'ldae-question-editor',
			'LDAE_Editor',
			array(
				'explanations'   => $list,
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'answerType'     => (string) $answer_type,
				'supportedTypes' => LDAE_Helpers::supported_types(),
				'i18n'           => array(
					'label'       => __( 'Answer Explanation', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'placeholder' => __( 'Why is this answer correct or incorrect? (shown on the results review)', 'quiz-answers-extender-bulk-import-for-learndash' ),
				),
			)
		);
	}

	// Legacy ProQuiz question editor (admin.php?page=ldAdvQuiz).

	/**
	 * Routing check only (no data is changed here).
	 *
	 * @return bool
	 */
	private static function is_proquiz_question_screen() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$module = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : '';
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:enable
		return is_admin() && 'ldadvquiz' === $page && 'question' === $module && 'addedit' === $action;
	}

	/**
	 * ProQuiz question ID from the legacy editor URL.
	 *
	 * @return int ProQuiz question ID from the URL (0 when adding a new question).
	 */
	private static function proquiz_question_id() {
		return isset( $_GET['questionId'] ) ? absint( wp_unslash( $_GET['questionId'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * When the legacy editor form is submitted, verify our nonce now and save at shutdown,
	 * i.e. after LearnDash has written the answers, so indices match the stored answers.
	 *
	 * LearnDash may store the edited question under a NEW ProQuiz ID (the old row is gone and
	 * its URL shows "Question not found"). The highest question ID is recorded before LearnDash
	 * saves, so the question it created in this quiz during the request can be identified and
	 * the explanations follow it. The URL is never changed.
	 */
	public static function maybe_handle_proquiz_save() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method || ! self::is_proquiz_question_screen() ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST[ self::PAYLOAD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		$payload = trim( (string) wp_unslash( $_POST[ self::PAYLOAD ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, sanitised per value.
		if ( '' === $payload ) {
			return;
		}

		$pro_id      = self::proquiz_question_id();
		$quiz_pro_id = isset( $_GET['quiz_id'] ) ? absint( wp_unslash( $_GET['quiz_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$max_before  = LDAE_Helpers::max_pro_question_id();

		/*
		 * LearnDash's legacy editor saves and re-renders the edit page in this same request.
		 * Save right away (positional: LearnDash stores answers in the submitted order, minus
		 * empty ones) so the re-rendered page already shows the new values...
		 */
		if ( $pro_id && self::can_edit_pro_question( $pro_id ) ) {
			self::save_payload_for_pro( $pro_id, $payload, true );
		}

		// ...then re-map against the answers LearnDash actually stored, once it is done.
		add_action(
			'shutdown',
			static function () use ( $payload, $pro_id, $quiz_pro_id, $max_before ) {
				$target = self::saved_question_id( $pro_id, $quiz_pro_id, $max_before );
				if ( $target && self::can_edit_pro_question( $target ) ) {
					self::save_payload_for_pro( $target, $payload, false );
				}
				if ( $pro_id && $target !== $pro_id && ! LDAE_Helpers::get_pro_question( $pro_id ) ) {
					LDAE_Helpers::forget_pro( $pro_id ); // LearnDash replaced it; nothing may stay on the dead ID.
				}
			}
		);
	}

	/**
	 * ProQuiz question ID that LearnDash's legacy editor actually saved in this request.
	 *
	 * @param int $pro_id      Question ID from the editor URL (0 = "add new question").
	 * @param int $quiz_pro_id ProQuiz quiz ID from the editor URL.
	 * @param int $max_before  Highest question ID before LearnDash saved.
	 * @return int 0 if it cannot be determined.
	 */
	private static function saved_question_id( $pro_id, $quiz_pro_id, $max_before ) {
		// Same ID kept (normal update).
		if ( $pro_id && LDAE_Helpers::get_pro_question( $pro_id ) ) {
			return (int) $pro_id;
		}
		// New question, or LearnDash re-created the edited one: the question it created in
		// this quiz during this request. Requires a known starting point.
		if ( $max_before || ! $pro_id ) {
			$created = LDAE_Helpers::pro_question_ids_after( $quiz_pro_id, $max_before );
			if ( $created ) {
				return (int) $created[0];
			}
		}
		return 0;
	}

	/**
	 * Whether the current user may edit a ProQuiz question.
	 *
	 * Linked questions use the post capability. Questions without a post (legacy editor)
	 * use LearnDash's ProQuiz capability, which is what grants access to that editor.
	 *
	 * @param int $pro_id ProQuiz question ID.
	 * @return bool
	 */
	private static function can_edit_pro_question( $pro_id ) {
		$post_id = LDAE_Helpers::get_question_post_id_by_pro_id( $pro_id );
		if ( $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		}
		return current_user_can( 'wpProQuiz_edit_quiz' ) || current_user_can( 'manage_options' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by LearnDash.
	}

	/**
	 * Enqueue assets for the Quiz Builder (sfwd-quiz editor).
	 *
	 * @param WP_Post $post Quiz post.
	 */
	private static function enqueue_quiz_builder( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$question_ids = LDAE_Helpers::get_quiz_question_ids( $post->ID );

		wp_enqueue_style( 'ldae-admin', LDAE_URL . 'assets/css/admin.css', array(), LDAE_VERSION );
		wp_enqueue_script( 'ldae-quiz-builder', LDAE_URL . 'assets/js/admin-quiz-builder.js', array( 'jquery' ), LDAE_VERSION, true );

		wp_localize_script(
			'ldae-quiz-builder',
			'LDAE_Builder',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::AJAX_NONCE ),
				'questionIds'    => array_map( 'intval', $question_ids ),
				'supportedTypes' => LDAE_Helpers::supported_types(),
				/**
				 * Containers searched for Quiz Builder question rows. Adjust if a LearnDash
				 * update renames the builder wrapper.
				 */
				'rootSelectors'  => (string) apply_filters(
					'ldae_builder_root_selectors',
					'#learndash_quiz_builder, #sfwd-quiz-builder, #learndash_builder_box_wrap, .learndash_builder_wrap, .learndash-builder, .ld-quiz-builder'
				),
				'fallbackRoot'   => '#wpbody-content',
				'i18n'           => array(
					'toggle'      => __( 'Answer explanations', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'save'        => __( 'Save explanations', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'reload'      => __( 'Reload answers', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'loading'     => __( 'Loading answers…', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'saving'      => __( 'Saving…', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'saved'       => __( 'Explanations saved.', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'error'       => __( 'Something went wrong. Please try again.', 'quiz-answers-extender-bulk-import-for-learndash' ),
					/* translators: %d: answer number */
					'answer'      => __( 'Answer %d', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'correct'     => __( 'correct', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'placeholder' => __( 'Explanation shown under this answer on the results review', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'notSingle'   => __( 'Explanations are available for Single Choice questions only.', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'noAnswers'   => __( 'This question has no saved answers yet. Save the quiz/question first, then click "Reload answers".', 'quiz-answers-extender-bulk-import-for-learndash' ),
					'savedNote'   => __( 'Answers below are the saved answers. If you just edited answers in the builder, save the quiz first and click "Reload answers".', 'quiz-answers-extender-bulk-import-for-learndash' ),
				),
			)
		);
	}

	/**
	 * Save explanations posted from the question editor.
	 *
	 * Runs on wp_after_insert_post, i.e. after LearnDash's own save_post handlers have
	 * written answer_data, so we can map onto the final, stored answer order. Works for
	 * the classic editor and for the block editor's meta-box save request.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save_from_editor( $post_id, $post ) {
		if ( ! ( $post instanceof WP_Post ) || 'sfwd-question' !== $post->post_type ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return; // Not a request from our editor (e.g. REST save, import, quick edit).
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// JSON payload from the inline fields; every value inside is sanitised below.
		$payload = isset( $_POST[ self::PAYLOAD ] ) ? trim( (string) wp_unslash( $_POST[ self::PAYLOAD ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $payload ) {
			// Fallback meta-box fields (only submitted when inline fields are not active).
			if ( isset( $_POST['ldae_explanations'] ) && is_array( $_POST['ldae_explanations'] ) ) {
				$raw   = wp_unslash( $_POST['ldae_explanations'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in save_explanations().
				$valid = wp_list_pluck( LDAE_Helpers::get_answers( $post_id ), 'index' );
				$clean = array();
				foreach ( $raw as $index => $text ) {
					if ( in_array( absint( $index ), $valid, true ) ) {
						$clean[ absint( $index ) ] = is_scalar( $text ) ? (string) $text : '';
					}
				}
				LDAE_Helpers::save_explanations( $post_id, $clean );
			}
			return; // Otherwise leave stored data untouched.
		}

		self::save_payload( $post_id, $payload );
	}

	/**
	 * Parse the inline-field JSON payload and save it mapped onto the stored answers.
	 *
	 * @param int    $post_id Question post ID.
	 * @param string $payload    JSON (unslashed).
	 * @param bool   $positional Map by submitted order instead of against stored answers.
	 */
	private static function save_payload( $post_id, $payload, $positional = false ) {
		$submitted = self::parse_payload( $payload );
		if ( null === $submitted ) {
			return;
		}
		$stored = $positional ? array() : LDAE_Helpers::get_answers( $post_id );
		LDAE_Helpers::save_explanations( $post_id, LDAE_Helpers::map_submitted_explanations( $submitted, $stored ) );
	}

	/**
	 * Same as save_payload(), for a ProQuiz question (linked to a post or not).
	 *
	 * @param int    $pro_id     ProQuiz question ID.
	 * @param string $payload    JSON (unslashed).
	 * @param bool   $positional Map by submitted order instead of against stored answers.
	 */
	private static function save_payload_for_pro( $pro_id, $payload, $positional = false ) {
		$submitted = self::parse_payload( $payload );
		if ( null === $submitted ) {
			return;
		}
		$stored = $positional ? array() : LDAE_Helpers::get_answers_by_pro( $pro_id );
		LDAE_Helpers::save_explanations_by_pro( $pro_id, LDAE_Helpers::map_submitted_explanations( $submitted, $stored ) );
	}

	/**
	 * Decode the inline-field JSON payload.
	 *
	 * @param string $payload JSON (unslashed).
	 * @return array|null List of array( 'answer' => string, 'explanation' => string ); null if invalid.
	 */
	private static function parse_payload( $payload ) {
		$entries = json_decode( $payload, true );
		if ( ! is_array( $entries ) ) {
			return null;
		}

		$submitted = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$answer = isset( $entry['answer'] ) && is_scalar( $entry['answer'] ) ? trim( wp_strip_all_tags( (string) $entry['answer'] ) ) : '';
			if ( '' === $answer ) {
				continue; // LearnDash discards empty answers; mirror that so indices stay aligned.
			}
			$submitted[] = array(
				'answer'      => $answer,
				'explanation' => isset( $entry['explanation'] ) && is_scalar( $entry['explanation'] ) ? (string) $entry['explanation'] : '',
			);
		}
		return $submitted;
	}

	// Quiz Builder AJAX.

	/**
	 * Validate the AJAX request and return the question ID.
	 *
	 * @return int
	 */
	private static function ajax_question_id() {
		if ( ! check_ajax_referer( self::AJAX_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'quiz-answers-extender-bulk-import-for-learndash' ) ), 403 );
		}
		$question_id = isset( $_POST['question_id'] ) ? absint( wp_unslash( $_POST['question_id'] ) ) : 0;
		if ( ! $question_id || 'sfwd-question' !== get_post_type( $question_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid question.', 'quiz-answers-extender-bulk-import-for-learndash' ) ), 400 );
		}
		if ( ! current_user_can( 'edit_post', $question_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this question.', 'quiz-answers-extender-bulk-import-for-learndash' ) ), 403 );
		}
		return $question_id;
	}

	/**
	 * AJAX: stored answers and explanations of one question.
	 */
	public static function ajax_get_answers() {
		$question_id = self::ajax_question_id();

		wp_send_json_success(
			array(
				'answer_type'  => LDAE_Helpers::get_answer_type( $question_id ),
				'answers'      => LDAE_Helpers::get_answers( $question_id ),
				'explanations' => (object) LDAE_Helpers::get_explanations_by_pro( LDAE_Helpers::get_question_pro_id( $question_id ), $question_id ),
			)
		);
	}

	/**
	 * AJAX: save the explanations of one question.
	 */
	public static function ajax_save_explanations() {
		$question_id = self::ajax_question_id();

		// Nonce verified in ajax_question_id(); each value is sanitised in LDAE_Helpers::save_explanations().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['explanations'] ) && is_array( $_POST['explanations'] ) ? wp_unslash( $_POST['explanations'] ) : array();

		$valid_indexes = wp_list_pluck( LDAE_Helpers::get_answers( $question_id ), 'index' );
		$clean         = array();
		foreach ( $raw as $index => $text ) {
			$index = absint( $index );
			if ( in_array( $index, $valid_indexes, true ) ) {
				$clean[ $index ] = is_scalar( $text ) ? (string) $text : '';
			}
		}

		LDAE_Helpers::save_explanations( $question_id, $clean );

		wp_send_json_success( array( 'explanations' => (object) LDAE_Helpers::get_explanations( $question_id ) ) );
	}
}
