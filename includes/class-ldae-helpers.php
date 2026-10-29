<?php
/**
 * Shared helpers: explanation storage, sanitisation, and LearnDash / ProQuiz data access.
 *
 * LearnDash data model (relevant parts):
 * - sfwd-quiz post      -> meta "quiz_pro_id" / setting "quiz_pro" = ProQuiz quiz ID.
 * - sfwd-question post  -> meta "question_pro_id" = row in the ProQuiz question table.
 * - ProQuiz question    -> "answer_data" = serialized array of WpProQuiz_Model_AnswerTypes.
 * - Quiz Builder order  -> quiz meta "ld_quiz_questions" = array( question_post_id => question_pro_id ).
 *
 * Explanations are stored on the sfwd-question post (native post meta) keyed by
 * the answer's index in answer_data, which is the same index LearnDash prints in
 * the frontend `data-pos` attribute of each answer <li>.
 *
 * @package LDAE
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared helper methods.
 */
final class LDAE_Helpers {

	/**
	 * Answer types that support explanations.
	 *
	 * @return string[]
	 */
	public static function supported_types() {
		return (array) apply_filters( 'ldae_supported_answer_types', array( 'single' ) );
	}

	/**
	 * Limited inline HTML allowed inside an explanation.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		return (array) apply_filters(
			'ldae_allowed_explanation_html',
			array(
				'a'      => array(
					'href'   => true,
					'title'  => true,
					'target' => true,
					'rel'    => true,
				),
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'u'      => array(),
				'br'     => array(),
				'code'   => array(),
				'sub'    => array(),
				'sup'    => array(),
			)
		);
	}

	/**
	 * Sanitise one explanation string.
	 *
	 * @param mixed $text Raw (already unslashed) value.
	 * @return string
	 */
	public static function sanitize_explanation( $text ) {
		if ( ! is_scalar( $text ) ) {
			return '';
		}
		$text = self::to_utf8( (string) $text );
		return trim( wp_kses( $text, self::allowed_html() ) );
	}

	/**
	 * Convert legacy Windows-1252 text (common in Excel CSV exports) to UTF-8.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function to_utf8( $value ) {
		$value = (string) $value;
		if ( '' === $value || ! function_exists( 'mb_check_encoding' ) || mb_check_encoding( $value, 'UTF-8' ) ) {
			return $value;
		}
		return (string) mb_convert_encoding( $value, 'UTF-8', 'Windows-1252' );
	}

	/**
	 * Normalise text for comparisons (titles, answer matching).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize_text( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( self::to_utf8( (string) $text ) ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	// Explanation storage.

	/**
	 * Get explanations for a question.
	 *
	 * @param int $question_post_id sfwd-question post ID.
	 * @return array<int,string> answer_index => explanation.
	 */
	public static function get_explanations( $question_post_id ) {
		return self::normalize_stored( get_post_meta( (int) $question_post_id, LDAE_META_KEY, true ) );
	}

	/**
	 * Normalise stored explanations to array( answer_index => string ).
	 *
	 * @param mixed $raw Stored value.
	 * @return array<int,string>
	 */
	private static function normalize_stored( $raw ) {
		if ( is_array( $raw ) && isset( $raw['explanations'] ) ) {
			$raw = $raw['explanations']; // Option format with fingerprint.
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $index => $text ) {
			if ( is_numeric( $index ) && $index >= 0 && is_scalar( $text ) ) {
				$out[ (int) $index ] = (string) $text;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Sanitise explanations for storage.
	 *
	 * @param array $explanations answer_index => raw explanation (unslashed).
	 * @return array<int,string> Empty array when every explanation is empty.
	 */
	private static function clean_explanations( array $explanations ) {
		$clean   = array();
		$has_any = false;
		foreach ( $explanations as $index => $text ) {
			if ( ! is_numeric( $index ) || $index < 0 ) {
				continue;
			}
			$value                 = self::sanitize_explanation( $text );
			$clean[ (int) $index ] = $value;
			if ( '' !== $value ) {
				$has_any = true;
			}
		}
		if ( ! $has_any ) {
			return array();
		}
		ksort( $clean );
		return $clean;
	}

	/**
	 * Save (replace) explanations for a question.
	 *
	 * @param int   $question_post_id sfwd-question post ID.
	 * @param array $explanations     answer_index => raw explanation (unslashed).
	 */
	public static function save_explanations( $question_post_id, array $explanations ) {
		$clean = self::clean_explanations( $explanations );
		if ( ! $clean ) {
			delete_post_meta( (int) $question_post_id, LDAE_META_KEY );
			return;
		}
		// update_post_meta() unslashes its input, so slash first to preserve backslashes.
		update_post_meta( (int) $question_post_id, LDAE_META_KEY, wp_slash( $clean ) );
	}

	/**
	 * Option name used for a ProQuiz question that has no sfwd-question post
	 * (e.g. questions created in LearnDash's legacy question editor).
	 *
	 * @param int $pro_id ProQuiz question ID.
	 * @return string
	 */
	public static function pro_option_name( $pro_id ) {
		return LDAE_PRO_OPTION_PREFIX . absint( $pro_id );
	}

	/**
	 * Explanations for a ProQuiz question, wherever they are stored.
	 *
	 * Linked questions use post meta. Unlinked questions use a non-autoloaded option,
	 * which is moved into post meta automatically once the question gets a post.
	 *
	 * @param int $pro_id  ProQuiz question ID.
	 * @param int $post_id Linked sfwd-question post ID if already known (0 = look it up).
	 * @return array<int,string>
	 */
	public static function get_explanations_by_pro( $pro_id, $post_id = 0 ) {
		$pro_id = absint( $pro_id );
		if ( ! $pro_id ) {
			return $post_id ? self::get_explanations( $post_id ) : array();
		}
		$post_id = $post_id ? (int) $post_id : self::get_question_post_id_by_pro_id( $pro_id );

		if ( ! $post_id ) {
			return self::get_unlinked_explanations( $pro_id );
		}

		$option = get_option( self::pro_option_name( $pro_id ), null );
		if ( null !== $option ) {
			// The question has been linked since: migrate, without overwriting post data.
			if ( ! self::get_explanations( $post_id ) ) {
				self::save_explanations( $post_id, self::normalize_stored( $option ) );
			}
			delete_option( self::pro_option_name( $pro_id ) );
			self::$rows = null;
		}

		$explanations = self::get_explanations( $post_id );
		if ( ! $explanations ) {
			$recovered = self::recover_orphan( $pro_id );
			if ( $recovered ) {
				self::save_explanations( $post_id, $recovered );
				delete_option( self::pro_option_name( $pro_id ) );
				self::$rows   = null;
				$explanations = self::get_explanations( $post_id );
			}
		}
		return $explanations;
	}

	/**
	 * Explanations of a ProQuiz question that has no sfwd-question post.
	 *
	 * @param int $pro_id ProQuiz question ID.
	 * @return array<int,string>
	 */
	public static function get_unlinked_explanations( $pro_id ) {
		$option = get_option( self::pro_option_name( $pro_id ), null );
		if ( null !== $option ) {
			return self::normalize_stored( $option );
		}
		return self::recover_orphan( $pro_id );
	}

	/**
	 * Identity of a ProQuiz question that survives LearnDash re-creating it under a new ID:
	 * its quiz, title and set of answers.
	 *
	 * @param int $pro_id ProQuiz question ID.
	 * @return array|null
	 */
	public static function fingerprint( $pro_id ) {
		$question = self::get_pro_question( $pro_id );
		if ( ! $question ) {
			return null;
		}
		$answers = array();
		foreach ( self::get_answers_by_pro( $pro_id ) as $answer ) {
			$answers[] = self::normalize_text( $answer['text'] );
		}
		sort( $answers );
		return array(
			'quiz'    => absint( $question->getQuizId() ),
			'title'   => self::normalize_text( (string) $question->getTitle() ),
			'answers' => md5( (string) wp_json_encode( $answers ) ),
		);
	}

	/**
	 * Find explanations stored for a ProQuiz question ID that no longer exists, whose
	 * fingerprint matches this question, and move them here. Covers LearnDash saving a
	 * question under a new ID outside of this plugin's own save handling.
	 *
	 * @param int $pro_id ProQuiz question ID.
	 * @return array<int,string> Recovered explanations, or empty array.
	 */
	private static function recover_orphan( $pro_id ) {
		$rows = self::stored_rows();
		unset( $rows[ absint( $pro_id ) ] );
		if ( ! $rows ) {
			return array();
		}
		$fingerprint = self::fingerprint( $pro_id );
		if ( ! $fingerprint ) {
			return array();
		}
		foreach ( $rows as $old_id => $value ) {
			// Same quiz, title and answers, and the old question really is gone.
			if ( $fingerprint !== $value['fp'] || self::get_pro_question( $old_id ) ) {
				continue;
			}
			$explanations = self::normalize_stored( $value );
			$value['fp']  = $fingerprint;
			update_option( self::pro_option_name( $pro_id ), $value, false );
			delete_option( self::pro_option_name( $old_id ) );
			self::$rows = null;
			return $explanations;
		}
		return array();
	}

	/**
	 * Request cache of stored_rows(). Only this plugin writes these rows, and every
	 * write resets the cache.
	 *
	 * @var array|null
	 */
	private static $rows = null;

	/**
	 * All stored entries that carry a fingerprint, newest ProQuiz ID first.
	 *
	 * @return array<int,array> ProQuiz ID => stored value.
	 */
	private static function stored_rows() {
		global $wpdb;
		if ( null !== self::$rows ) {
			return self::$rows;
		}
		self::$rows = array();
		$like       = $wpdb->esc_like( LDAE_PRO_OPTION_PREFIX ) . '%';
		$results    = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $results as $row ) {
			$id    = absint( substr( $row->option_name, strlen( LDAE_PRO_OPTION_PREFIX ) ) );
			$value = maybe_unserialize( $row->option_value );
			if ( $id && is_array( $value ) && ! empty( $value['fp'] ) ) {
				self::$rows[ $id ] = $value;
			}
		}
		krsort( self::$rows );
		return self::$rows;
	}

	/**
	 * Drop the entry of a ProQuiz question ID that LearnDash replaced with a new ID.
	 *
	 * @param int $old_id Old (deleted) ProQuiz question ID.
	 */
	public static function forget_pro( $old_id ) {
		delete_option( self::pro_option_name( $old_id ) );
		self::$rows = null;
	}

	/**
	 * Save explanations for a ProQuiz question (post meta if linked, option otherwise).
	 *
	 * @param int   $pro_id       ProQuiz question ID.
	 * @param array $explanations answer_index => raw explanation (unslashed).
	 */
	public static function save_explanations_by_pro( $pro_id, array $explanations ) {
		$pro_id = absint( $pro_id );
		if ( ! $pro_id ) {
			return;
		}
		self::$rows = null;
		$post_id    = self::get_question_post_id_by_pro_id( $pro_id );
		if ( $post_id ) {
			self::save_explanations( $post_id, $explanations );
			delete_option( self::pro_option_name( $pro_id ) );
			return;
		}
		$clean = self::clean_explanations( $explanations );
		if ( $clean ) {
			$value = array(
				'explanations' => $clean,
				'fp'           => self::fingerprint( $pro_id ),
			);
			update_option( self::pro_option_name( $pro_id ), $value, false );
		} else {
			delete_option( self::pro_option_name( $pro_id ) );
		}
	}

	/**
	 * Map explanations submitted from the editor (in DOM order) onto the answers
	 * LearnDash actually stored, so indices always match answer_data.
	 *
	 * @param array $submitted List of array( 'answer' => text, 'explanation' => text ), empty answers removed.
	 * @param array $stored    Output of self::get_answers().
	 * @return array<int,string>
	 */
	public static function map_submitted_explanations( array $submitted, array $stored ) {
		$out = array();

		// Could not read stored answers: trust DOM order.
		if ( empty( $stored ) ) {
			foreach ( array_values( $submitted ) as $i => $entry ) {
				$out[ $i ] = $entry['explanation'];
			}
			return $out;
		}

		// Normal case: same number of answers -> positional mapping.
		if ( count( $submitted ) === count( $stored ) ) {
			$submitted = array_values( $submitted );
			foreach ( array_values( $stored ) as $i => $answer ) {
				$out[ $answer['index'] ] = $submitted[ $i ]['explanation'];
			}
			return $out;
		}

		// Counts differ (LearnDash dropped/merged something): match on answer text.
		$used = array();
		foreach ( $stored as $answer ) {
			$needle = self::normalize_text( $answer['text'] );
			foreach ( $submitted as $si => $entry ) {
				if ( isset( $used[ $si ] ) ) {
					continue;
				}
				if ( self::normalize_text( $entry['answer'] ) === $needle ) {
					$out[ $answer['index'] ] = $entry['explanation'];
					$used[ $si ]             = true;
					break;
				}
			}
		}
		return $out;
	}

	// LearnDash / ProQuiz access.

	/**
	 * ProQuiz question ID linked to a question post.
	 *
	 * @param int $question_post_id Question post ID.
	 * @return int
	 */
	public static function get_question_pro_id( $question_post_id ) {
		return absint( get_post_meta( (int) $question_post_id, 'question_pro_id', true ) );
	}

	/**
	 * Load a ProQuiz question model.
	 *
	 * @param int $pro_id ProQuiz question ID.
	 * @return WpProQuiz_Model_Question|null
	 */
	public static function get_pro_question( $pro_id ) {
		$pro_id = absint( $pro_id );
		if ( ! $pro_id || ! class_exists( 'WpProQuiz_Model_QuestionMapper' ) ) {
			return null;
		}

		$mapper   = new WpProQuiz_Model_QuestionMapper();
		$question = null;

		if ( method_exists( $mapper, 'fetch' ) ) {
			$question = $mapper->fetch( $pro_id );
		}
		if ( ! ( $question instanceof WpProQuiz_Model_Question ) && method_exists( $mapper, 'fetchById' ) ) {
			$question = $mapper->fetchById( $pro_id );
		}

		return ( $question instanceof WpProQuiz_Model_Question && $question->getId() ) ? $question : null;
	}

	/**
	 * Answer type of a question ("single", "multiple", ...).
	 *
	 * @param int $question_post_id Question post ID.
	 * @return string
	 */
	public static function get_answer_type( $question_post_id ) {
		$question = self::get_pro_question( self::get_question_pro_id( $question_post_id ) );
		if ( $question ) {
			return (string) $question->getAnswerType();
		}
		return (string) get_post_meta( (int) $question_post_id, 'question_type', true );
	}

	/**
	 * Answers as stored by LearnDash.
	 *
	 * @param int $question_post_id Question post ID.
	 * @return array[] array( 'index' => int, 'text' => string (plain), 'correct' => bool )
	 */
	public static function get_answers( $question_post_id ) {
		return self::get_answers_by_pro( self::get_question_pro_id( $question_post_id ) );
	}

	/**
	 * Answers of a ProQuiz question.
	 *
	 * @param int $pro_id ProQuiz question ID.
	 * @return array[] Same shape as self::get_answers().
	 */
	public static function get_answers_by_pro( $pro_id ) {
		$question = self::get_pro_question( $pro_id );
		if ( ! $question ) {
			return array();
		}

		$data = $question->getAnswerData();
		if ( ! is_array( $data ) ) {
			return array();
		}

		$answers = array();
		foreach ( array_values( $data ) as $index => $answer ) {
			if ( ! ( $answer instanceof WpProQuiz_Model_AnswerTypes ) ) {
				continue; // Keep $index intact so positions still match data-pos.
			}
			$answers[] = array(
				'index'   => $index,
				'text'    => trim( wp_strip_all_tags( (string) $answer->getAnswer() ) ),
				'correct' => (bool) $answer->isCorrect(),
			);
		}
		return $answers;
	}

	/**
	 * ProQuiz quiz ID for a quiz post.
	 *
	 * @param int $quiz_post_id Quiz post ID.
	 * @return int
	 */
	public static function get_quiz_pro_id( $quiz_post_id ) {
		$pro_id = 0;
		if ( function_exists( 'learndash_get_setting' ) ) {
			$pro_id = absint( learndash_get_setting( (int) $quiz_post_id, 'quiz_pro' ) );
		}
		if ( ! $pro_id ) {
			$pro_id = absint( get_post_meta( (int) $quiz_post_id, 'quiz_pro_id', true ) );
		}
		return $pro_id;
	}

	/**
	 * Quiz post ID from a ProQuiz quiz ID (fallback when the frontend only exposes the pro ID).
	 *
	 * @param int $pro_id ProQuiz quiz ID.
	 * @return int
	 */
	public static function get_quiz_post_id_by_pro_id( $pro_id ) {
		$pro_id = absint( $pro_id );
		if ( ! $pro_id ) {
			return 0;
		}
		if ( function_exists( 'learndash_get_quiz_id_by_pro_quiz_id' ) ) {
			$id = absint( learndash_get_quiz_id_by_pro_quiz_id( $pro_id ) );
			if ( $id ) {
				return $id;
			}
		}
		$ids = get_posts(
			array(
				'post_type'      => 'sfwd-quiz',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => 'quiz_pro_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $pro_id,       // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Raw Quiz Builder map: array( question_post_id => question_pro_id ).
	 *
	 * @param int $quiz_post_id Quiz post ID.
	 * @return array<int,int>
	 */
	public static function get_quiz_question_map( $quiz_post_id ) {
		$map = array();

		if ( function_exists( 'learndash_get_quiz_questions' ) ) {
			$questions = learndash_get_quiz_questions( (int) $quiz_post_id );
			if ( is_array( $questions ) ) {
				$map = $questions;
			}
		}
		if ( empty( $map ) ) {
			$meta = get_post_meta( (int) $quiz_post_id, 'ld_quiz_questions', true );
			if ( is_array( $meta ) ) {
				$map = $meta;
			}
		}

		$clean = array();
		foreach ( $map as $post_id => $pro_id ) {
			if ( absint( $post_id ) ) {
				$clean[ absint( $post_id ) ] = absint( $pro_id );
			}
		}
		return $clean;
	}

	/**
	 * Question post IDs that belong to a quiz.
	 *
	 * @param int $quiz_post_id Quiz post ID.
	 * @return int[]
	 */
	public static function get_quiz_question_ids( $quiz_post_id ) {
		$ids = array_keys( self::get_quiz_question_map( $quiz_post_id ) );

		if ( empty( $ids ) ) {
			// Legacy (pre-builder) relationship.
			$ids = get_posts(
				array(
					'post_type'      => 'sfwd-question',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_key'       => 'quiz_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => (int) $quiz_post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
		}

		return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	}

	/**
	 * Question post ID for a ProQuiz question ID.
	 *
	 * @param int $pro_id ProQuiz question ID.
	 * @return int
	 */
	public static function get_question_post_id_by_pro_id( $pro_id ) {
		$pro_id = absint( $pro_id );
		if ( ! $pro_id ) {
			return 0;
		}
		if ( function_exists( 'learndash_get_question_post_by_pro_id' ) ) {
			$found = learndash_get_question_post_by_pro_id( $pro_id );
			$found = ( $found instanceof WP_Post ) ? $found->ID : absint( $found );
			if ( $found && 'sfwd-question' === get_post_type( $found ) ) {
				return (int) $found;
			}
		}
		$ids = get_posts(
			array(
				'post_type'      => 'sfwd-question',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_key'       => 'question_pro_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $pro_id,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Highest ProQuiz question ID (0 if none or the table is unknown).
	 *
	 * @return int
	 */
	public static function max_pro_question_id() {
		global $wpdb;
		$table = esc_sql( self::pro_question_table() );
		if ( '' === $table ) {
			return 0;
		}
		return absint( $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * ProQuiz questions of a quiz created after a given ID, newest first.
	 *
	 * @param int $quiz_pro_id ProQuiz quiz ID.
	 * @param int $after_id    Only IDs greater than this.
	 * @return int[]
	 */
	public static function pro_question_ids_after( $quiz_pro_id, $after_id ) {
		global $wpdb;
		$quiz_pro_id = absint( $quiz_pro_id );
		if ( ! $quiz_pro_id ) {
			return array();
		}
		$table = esc_sql( self::pro_question_table() );
		if ( '' === $table ) {
			return array();
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE quiz_id = %d AND id > %d ORDER BY id DESC", $quiz_pro_id, absint( $after_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * IDs of all ProQuiz questions of a ProQuiz quiz.
	 *
	 * @param int $quiz_pro_id ProQuiz quiz ID.
	 * @return int[]
	 */
	public static function get_pro_question_ids( $quiz_pro_id ) {
		global $wpdb;
		$quiz_pro_id = absint( $quiz_pro_id );
		if ( ! $quiz_pro_id ) {
			return array();
		}
		$table = esc_sql( self::pro_question_table() );
		if ( '' === $table ) {
			return array();
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE quiz_id = %d ORDER BY sort ASC, id ASC", $quiz_pro_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Name of the ProQuiz question table ('' if not found).
	 *
	 * @return string
	 */
	private static function pro_question_table() {
		global $wpdb;
		static $table = null;
		if ( null !== $table ) {
			return $table;
		}
		$table = '';
		if ( class_exists( 'LDLMS_DB' ) && method_exists( 'LDLMS_DB', 'get_table_name' ) ) {
			$table = (string) LDLMS_DB::get_table_name( 'quiz_question' );
		}
		if ( '' === $table ) {
			foreach ( array( $wpdb->prefix . 'learndash_pro_quiz_question', $wpdb->prefix . 'wp_pro_quiz_question' ) as $candidate ) {
				if ( $candidate === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $candidate ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table = $candidate;
					break;
				}
			}
		}
		$table = esc_sql( $table );
		return $table;
	}
}
