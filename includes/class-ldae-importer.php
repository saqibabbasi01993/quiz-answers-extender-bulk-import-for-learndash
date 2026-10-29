<?php
/**
 * Bulk CSV importer (LearnDash LMS > Import Questions).
 *
 * For every valid row it:
 *  1. creates the ProQuiz question (WpProQuiz_Model_Question + answer models) via LearnDash's mapper,
 *  2. creates the sfwd-question post linked to it (question_pro_id, quiz_id, ld_quiz_{id}),
 *  3. appends it to the quiz's Quiz Builder list (ld_quiz_questions),
 *  4. stores the per-answer explanations.
 *
 * @package LDAE
 */

defined( 'ABSPATH' ) || exit;

/**
 * CSV question importer.
 */
final class LDAE_Importer {

	const PAGE_SLUG     = 'ldae-csv-import';
	const ACTION        = 'ldae_csv_import';
	const SAMPLE_ACTION = 'ldae_csv_sample';
	const MAX_BYTES     = 5242880; // 5 MB.
	const MAX_ROWS      = 1000;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 1001 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_' . self::SAMPLE_ACTION, array( __CLASS__, 'download_sample' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LDAE_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Capability required to use the importer.
	 *
	 * @return string
	 */
	public static function capability() {
		return (string) apply_filters( 'ldae_import_capability', 'manage_options' );
	}

	/**
	 * CSV column names, in order.
	 *
	 * @return string[]
	 */
	public static function columns() {
		return array( 'question_title', 'question_text', 'answer_1', 'answer_2', 'answer_3', 'answer_4', 'correct_answer', 'explanation_1', 'explanation_2', 'explanation_3', 'explanation_4' );
	}

	/**
	 * Admin page hook suffix of the importer screen.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Register the importer admin page.
	 */
	public static function register_page() {
		global $admin_page_hooks;
		$parent = isset( $admin_page_hooks['learndash-lms'] ) ? 'learndash-lms' : 'tools.php';

		self::$hook_suffix = (string) add_submenu_page(
			$parent,
			__( 'Import Quiz Questions (CSV)', 'quiz-answers-extender-bulk-import-for-learndash' ),
			__( 'Import Questions', 'quiz-answers-extender-bulk-import-for-learndash' ),
			self::capability(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Styles for the importer screen only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( '' !== self::$hook_suffix && self::$hook_suffix === $hook ) {
			wp_enqueue_style( 'ldae-admin', LDAE_URL . 'assets/css/admin.css', array(), LDAE_VERSION );
		}
	}

	/**
	 * Add an "Import Questions" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = menu_page_url( self::PAGE_SLUG, false );
		if ( $url && current_user_can( self::capability() ) ) {
			array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Import Questions', 'quiz-answers-extender-bulk-import-for-learndash' ) . '</a>' );
		}
		return $links;
	}

	// Page.

	/**
	 * Render the importer page.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'quiz-answers-extender-bulk-import-for-learndash' ) );
		}

		$quizzes = get_posts(
			array(
				'post_type'      => 'sfwd-quiz',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$report     = self::pull_report();
		$selected   = $report ? (int) $report['quiz_id'] : 0;
		$sample_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::SAMPLE_ACTION ), self::SAMPLE_ACTION );
		?>
		<div class="wrap ldae-import">
			<h1><?php esc_html_e( 'Import Quiz Questions (CSV)', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></h1>

			<?php
			if ( $report ) {
				self::render_report( $report );
			}
			?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION, 'ldae_import_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
					<tr>
						<th scope="row"><label for="ldae-quiz"><?php esc_html_e( 'Target quiz', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></label></th>
						<td>
							<select id="ldae-quiz" name="quiz_id" required>
								<option value=""><?php esc_html_e( '— Select a quiz —', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></option>
								<?php
								foreach ( $quizzes as $quiz ) {
									if ( ! current_user_can( 'edit_post', $quiz->ID ) ) {
										continue;
									}
									$status = '';
									if ( 'publish' !== $quiz->post_status ) {
										$obj    = get_post_status_object( $quiz->post_status );
										$status = $obj ? ' — ' . $obj->label : '';
									}
									$title = '' !== $quiz->post_title ? $quiz->post_title : __( '(no title)', 'quiz-answers-extender-bulk-import-for-learndash' );
									printf(
										'<option value="%1$d" %2$s>%3$s</option>',
										(int) $quiz->ID,
										selected( $selected, $quiz->ID, false ),
										esc_html( sprintf( '%s (#%d)%s', $title, $quiz->ID, $status ) )
									);
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ldae-csv"><?php esc_html_e( 'CSV file', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></label></th>
						<td>
							<input type="file" id="ldae-csv" name="ldae_csv" accept=".csv,text/csv" required />
							<p class="description">
								<?php
								printf(
									/* translators: %s: max file size */
									esc_html__( 'UTF-8 CSV, comma or semicolon separated, first row = column headers. Max %s.', 'quiz-answers-extender-bulk-import-for-learndash' ),
									esc_html( size_format( self::MAX_BYTES ) )
								);
								?>
								<a href="<?php echo esc_url( $sample_url ); ?>"><?php esc_html_e( 'Download a sample CSV', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></a>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Duplicates', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="skip_duplicates" value="1" checked /> <?php esc_html_e( 'Skip questions whose title already exists', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></label><br />
								<label><input type="radio" name="duplicate_scope" value="quiz" checked /> <?php esc_html_e( 'in the selected quiz (and earlier rows of this file)', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></label><br />
								<label><input type="radio" name="duplicate_scope" value="site" /> <?php esc_html_e( 'anywhere on the site', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ldae-points"><?php esc_html_e( 'Points per question', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></label></th>
						<td><input type="number" id="ldae-points" name="points" value="1" min="1" max="1000" class="small-text" /></td>
					</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Import questions', 'quiz-answers-extender-bulk-import-for-learndash' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'CSV columns', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></h2>
			<table class="widefat striped ldae-import__columns">
				<thead><tr><th><?php esc_html_e( 'Column', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></th><th><?php esc_html_e( 'Notes', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>question_title</code></td><td><?php esc_html_e( 'Question title (used for duplicate detection). Required unless question_text is set.', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></td></tr>
					<tr><td><code>question_text</code></td><td><?php esc_html_e( 'The question shown to students. Basic HTML allowed.', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></td></tr>
					<tr><td><code>answer_1</code> … <code>answer_4</code></td><td><?php esc_html_e( 'At least two answers. Empty answer columns are skipped.', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></td></tr>
					<tr><td><code>correct_answer</code></td><td><?php esc_html_e( 'Number 1–4 pointing to a non-empty answer column.', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></td></tr>
					<tr><td><code>explanation_1</code> … <code>explanation_4</code></td><td><?php esc_html_e( 'Optional explanation for the matching answer.', 'quiz-answers-extender-bulk-import-for-learndash' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the result of the last import.
	 *
	 * @param array $report Report.
	 */
	private static function render_report( array $report ) {
		if ( ! empty( $report['fatal'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $report['fatal'] ) . '</p></div>';
			return;
		}

		$created = count( $report['created'] );
		$skipped = count( $report['skipped'] );
		$errors  = count( $report['errors'] );
		$class   = $errors ? 'notice-warning' : 'notice-success';

		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html(
			sprintf(
				/* translators: 1: created, 2: skipped, 3: errors */
				__( 'Import finished: %1$d created, %2$d skipped as duplicates, %3$d rows with errors.', 'quiz-answers-extender-bulk-import-for-learndash' ),
				$created,
				$skipped,
				$errors
			)
		);
		if ( $created && $report['quiz_id'] ) {
			echo ' <a href="' . esc_url( get_edit_post_link( (int) $report['quiz_id'], 'raw' ) ) . '">' . esc_html__( 'Open the quiz', 'quiz-answers-extender-bulk-import-for-learndash' ) . '</a>';
		}
		echo '</p></div>';

		$sections = array(
			'created' => __( 'Created', 'quiz-answers-extender-bulk-import-for-learndash' ),
			'skipped' => __( 'Skipped (duplicates)', 'quiz-answers-extender-bulk-import-for-learndash' ),
			'errors'  => __( 'Errors', 'quiz-answers-extender-bulk-import-for-learndash' ),
		);
		foreach ( $sections as $key => $heading ) {
			if ( empty( $report[ $key ] ) ) {
				continue;
			}
			echo '<h3>' . esc_html( $heading ) . '</h3><table class="widefat striped ldae-import__report"><thead><tr><th>' .
				esc_html__( 'CSV row', 'quiz-answers-extender-bulk-import-for-learndash' ) . '</th><th>' . esc_html__( 'Details', 'quiz-answers-extender-bulk-import-for-learndash' ) . '</th></tr></thead><tbody>';
			foreach ( $report[ $key ] as $item ) {
				echo '<tr><td>' . (int) $item['row'] . '</td><td>';
				if ( 'created' === $key && ! empty( $item['post_id'] ) ) {
					echo '<a href="' . esc_url( get_edit_post_link( (int) $item['post_id'], 'raw' ) ) . '">' . esc_html( $item['message'] ) . '</a>';
				} else {
					echo esc_html( $item['message'] );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Read (once) the report stored for this user after the redirect.
	 *
	 * @return array|null
	 */
	private static function pull_report() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup of a random token bound to the current user.
		if ( empty( $_GET['ldae_result'] ) ) {
			return null;
		}
		$token  = sanitize_key( wp_unslash( $_GET['ldae_result'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stored = get_transient( 'ldae_import_' . $token );
		if ( ! is_array( $stored ) || get_current_user_id() !== (int) $stored['user'] ) {
			return null;
		}
		delete_transient( 'ldae_import_' . $token );
		return $stored['report'];
	}

	// Import handling.

	/**
	 * Handle the import form submission (admin-post.php).
	 */
	public static function handle_import() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'quiz-answers-extender-bulk-import-for-learndash' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION, 'ldae_import_nonce' );

		$quiz_id = isset( $_POST['quiz_id'] ) ? absint( wp_unslash( $_POST['quiz_id'] ) ) : 0;
		$skip    = ! empty( $_POST['skip_duplicates'] );
		$scope   = ( isset( $_POST['duplicate_scope'] ) && 'site' === sanitize_key( wp_unslash( $_POST['duplicate_scope'] ) ) ) ? 'site' : 'quiz';
		$points  = isset( $_POST['points'] ) ? absint( wp_unslash( $_POST['points'] ) ) : 1;
		$points  = min( 1000, max( 1, $points ) );
		$report  = array(
			'quiz_id' => $quiz_id,
			'created' => array(),
			'skipped' => array(),
			'errors'  => array(),
			'fatal'   => '',
		);

		$quiz_pro_id = 0;
		if ( ! $quiz_id || 'sfwd-quiz' !== get_post_type( $quiz_id ) ) {
			$report['fatal'] = __( 'Please select a valid quiz.', 'quiz-answers-extender-bulk-import-for-learndash' );
		} elseif ( ! current_user_can( 'edit_post', $quiz_id ) ) {
			$report['fatal'] = __( 'You are not allowed to edit the selected quiz.', 'quiz-answers-extender-bulk-import-for-learndash' );
		} elseif ( ! class_exists( 'WpProQuiz_Model_QuestionMapper' ) || ! class_exists( 'WpProQuiz_Model_Question' ) || ! class_exists( 'WpProQuiz_Model_AnswerTypes' ) ) {
			$report['fatal'] = __( 'LearnDash quiz engine (ProQuiz) classes are not available.', 'quiz-answers-extender-bulk-import-for-learndash' );
		} else {
			$quiz_pro_id = LDAE_Helpers::get_quiz_pro_id( $quiz_id );
			if ( ! $quiz_pro_id ) {
				$report['fatal'] = __( 'The selected quiz has no ProQuiz ID yet. Open the quiz, click Update once, then retry.', 'quiz-answers-extender-bulk-import-for-learndash' );
			}
		}

		if ( '' === $report['fatal'] ) {
			$error = '';
			$path  = self::validated_upload( $error );
			if ( ! $path ) {
				$report['fatal'] = $error;
			} else {
				$rows = self::parse_csv( $path, $error );
				if ( '' !== $error ) {
					$report['fatal'] = $error;
				} else {
					self::import_rows( $rows, $quiz_id, $quiz_pro_id, $skip, $scope, $points, $report );
				}
			}
		}

		$token = wp_generate_uuid4();
		set_transient(
			'ldae_import_' . $token,
			array(
				'user'   => get_current_user_id(),
				'report' => $report,
			),
			15 * MINUTE_IN_SECONDS
		);

		$back = wp_get_referer();
		$back = $back ? remove_query_arg( 'ldae_result', $back ) : admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		wp_safe_redirect( add_query_arg( 'ldae_result', $token, $back ) );
		exit;
	}

	/**
	 * Validate the uploaded CSV file.
	 *
	 * @param string $error Error out.
	 * @return string Temp path or ''.
	 */
	private static function validated_upload( &$error ) {
		$error = '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- nonce verified in handle_import(); fields validated individually.
		if ( empty( $_FILES['ldae_csv'] ) || ! is_array( $_FILES['ldae_csv'] ) ) {
			$error = __( 'Please choose a CSV file.', 'quiz-answers-extender-bulk-import-for-learndash' );
			return '';
		}
		$file = $_FILES['ldae_csv'];
		// phpcs:enable

		$code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $code ) {
			/* translators: %d: PHP upload error code */
			$error = sprintf( __( 'File upload failed (error code %d).', 'quiz-answers-extender-bulk-import-for-learndash' ), $code );
			return '';
		}
		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$error = __( 'Invalid upload.', 'quiz-answers-extender-bulk-import-for-learndash' );
			return '';
		}
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			$error = __( 'The file is empty or too large.', 'quiz-answers-extender-bulk-import-for-learndash' );
			return '';
		}
		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$error = __( 'Only .csv files are accepted.', 'quiz-answers-extender-bulk-import-for-learndash' );
			return '';
		}
		return $tmp;
	}

	/**
	 * Parse the CSV file into rows keyed by column name.
	 *
	 * @param string $path  File path.
	 * @param string $error Error out.
	 * @return array[] Rows as column => value.
	 */
	private static function parse_csv( $path, &$error ) {
		$error  = '';
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			$error = __( 'Could not read the uploaded file.', 'quiz-answers-extender-bulk-import-for-learndash' );
			return array();
		}

		$first = fgets( $handle );
		if ( false === $first || false !== strpos( $first, "\0" ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$error = __( 'The file is empty or not a text CSV.', 'quiz-answers-extender-bulk-import-for-learndash' );
			return array();
		}
		$first     = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $first ); // Strip UTF-8 BOM.
		$delimiter = ( substr_count( $first, ';' ) > substr_count( $first, ',' ) ) ? ';' : ',';

		$header = array();
		foreach ( str_getcsv( trim( $first ), $delimiter, '"', '' ) as $col ) {
			$header[] = sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( trim( (string) $col ) ) ) );
		}

		$missing = array_diff( array( 'question_title', 'question_text', 'answer_1', 'answer_2', 'correct_answer' ), $header );
		if ( in_array( 'question_title', $missing, true ) && ! in_array( 'question_text', $missing, true ) ) {
			$missing = array_diff( $missing, array( 'question_title' ) );
		}
		if ( in_array( 'question_text', $missing, true ) && ! in_array( 'question_title', $missing, true ) ) {
			$missing = array_diff( $missing, array( 'question_text' ) );
		}
		if ( $missing ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			/* translators: %s: column names */
			$error = sprintf( __( 'Missing required column(s): %s', 'quiz-answers-extender-bulk-import-for-learndash' ), implode( ', ', $missing ) );
			return array();
		}

		$rows = array();
		while ( true ) {
			$data = fgetcsv( $handle, 0, $delimiter, '"', '' );
			if ( false === $data ) {
				break;
			}
			if ( 1 === count( $data ) && ( null === $data[0] || '' === trim( (string) $data[0] ) ) ) {
				$rows[] = null; // Keep row numbering; blank line ignored later.
				continue;
			}
			$row = array();
			foreach ( $header as $i => $col ) {
				if ( '' !== $col ) {
					$row[ $col ] = isset( $data[ $i ] ) ? (string) $data[ $i ] : '';
				}
			}
			$rows[] = $row;
			if ( count( $rows ) > self::MAX_ROWS ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				/* translators: %d: max rows */
				$error = sprintf( __( 'Too many rows. Split the file into chunks of %d rows or fewer.', 'quiz-answers-extender-bulk-import-for-learndash' ), self::MAX_ROWS );
				return array();
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}

	/**
	 * Import parsed rows into a quiz.
	 *
	 * @param array  $rows        Parsed rows.
	 * @param int    $quiz_id     Quiz post ID.
	 * @param int    $quiz_pro_id ProQuiz quiz ID.
	 * @param bool   $skip        Skip duplicates.
	 * @param string $scope       'quiz' or 'site'.
	 * @param int    $points      Points per question.
	 * @param array  $report      Report (by reference).
	 */
	private static function import_rows( array $rows, $quiz_id, $quiz_pro_id, $skip, $scope, $points, array &$report ) {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$titles    = $skip ? self::existing_titles( $quiz_id, $scope ) : array();
		$sort      = count( LDAE_Helpers::get_quiz_question_ids( $quiz_id ) );
		$new_links = array();

		foreach ( $rows as $n => $row ) {
			$row_number = $n + 2; // Row 1 is the header.
			if ( null === $row ) {
				continue;
			}

			$error = '';
			$v     = self::validate_row( $row, $error );
			if ( '' !== $error ) {
				$report['errors'][] = array(
					'row'     => $row_number,
					'message' => $error,
				);
				continue;
			}

			$key = LDAE_Helpers::normalize_text( $v['title'] );
			if ( $skip && isset( $titles[ $key ] ) ) {
				$report['skipped'][] = array(
					'row'     => $row_number,
					'message' => $v['title'],
				);
				continue;
			}

			$created = self::create_question( $quiz_id, $quiz_pro_id, $v, $points, $sort, $error );
			if ( ! $created ) {
				$report['errors'][] = array(
					'row'     => $row_number,
					'message' => $v['title'] . ' — ' . $error,
				);
				continue;
			}

			++$sort;
			$titles[ $key ]                   = true;
			$new_links[ $created['post_id'] ] = $created['pro_id'];
			$report['created'][]              = array(
				'row'     => $row_number,
				'post_id' => $created['post_id'],
				'message' => $v['title'],
			);
		}

		if ( $new_links ) {
			self::attach_to_quiz( $quiz_id, $new_links );
		}
	}

	/**
	 * Normalised titles of existing questions, for duplicate detection.
	 *
	 * @param int    $quiz_id Quiz post ID.
	 * @param string $scope   'quiz' or 'site'.
	 * @return array<string,bool>
	 */
	private static function existing_titles( $quiz_id, $scope ) {
		global $wpdb;

		if ( 'site' === $scope ) {
			$raw = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')",
					'sfwd-question'
				)
			);
		} else {
			$raw = array();
			foreach ( LDAE_Helpers::get_quiz_question_ids( $quiz_id ) as $id ) {
				$raw[] = (string) get_post_field( 'post_title', $id, 'raw' );
			}
		}

		$titles = array();
		foreach ( (array) $raw as $title ) {
			$key = LDAE_Helpers::normalize_text( $title );
			if ( '' !== $key ) {
				$titles[ $key ] = true;
			}
		}
		return $titles;
	}

	/**
	 * Validate and sanitise one CSV row.
	 *
	 * @param array  $row   Column => value.
	 * @param string $error Error out.
	 * @return array
	 */
	private static function validate_row( array $row, &$error ) {
		$error = '';
		$get   = static function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) ? trim( LDAE_Helpers::to_utf8( $row[ $key ] ) ) : '';
		};

		$title = sanitize_text_field( $get( 'question_title' ) );
		$text  = wp_kses_post( $get( 'question_text' ) );

		if ( '' === $title && '' === trim( wp_strip_all_tags( $text ) ) ) {
			$error = __( 'Missing question_title and question_text.', 'quiz-answers-extender-bulk-import-for-learndash' );
			return array();
		}
		if ( '' === $title ) {
			$title = wp_trim_words( wp_strip_all_tags( $text ), 12, '…' );
		}
		if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
			$text = esc_html( $title );
		}

		$answers      = array();
		$explanations = array();
		$slot_map     = array(); // CSV slot (1-4) => answer index.
		for ( $i = 1; $i <= 4; $i++ ) {
			$answer = wp_kses_post( $get( 'answer_' . $i ) );
			if ( '' === trim( wp_strip_all_tags( $answer ) ) ) {
				continue;
			}
			$slot_map[ $i ] = count( $answers );
			$answers[]      = $answer;
			$explanations[] = LDAE_Helpers::sanitize_explanation( $get( 'explanation_' . $i ) );
		}
		if ( count( $answers ) < 2 ) {
			$error = __( 'At least two non-empty answers are required.', 'quiz-answers-extender-bulk-import-for-learndash' );
			return array();
		}

		$correct_raw = $get( 'correct_answer' );
		$slot        = is_numeric( $correct_raw ) ? (int) $correct_raw : 0;
		if ( $slot < 1 || $slot > 4 || (float) $correct_raw !== (float) $slot ) {
			$error = __( 'correct_answer must be a whole number from 1 to 4.', 'quiz-answers-extender-bulk-import-for-learndash' );
			return array();
		}
		if ( ! isset( $slot_map[ $slot ] ) ) {
			/* translators: %d: answer column number */
			$error = sprintf( __( 'correct_answer points to answer_%d, which is empty.', 'quiz-answers-extender-bulk-import-for-learndash' ), $slot );
			return array();
		}

		return array(
			'title'        => $title,
			'text'         => $text,
			'answers'      => $answers,
			'explanations' => $explanations,
			'correct'      => $slot_map[ $slot ],
		);
	}

	/**
	 * Create the ProQuiz question and its sfwd-question post.
	 *
	 * @param int    $quiz_id     Quiz post ID.
	 * @param int    $quiz_pro_id ProQuiz quiz ID.
	 * @param array  $v           Validated row.
	 * @param int    $points      Points.
	 * @param int    $sort        Sort position.
	 * @param string $error       Error out.
	 * @return array|false
	 */
	private static function create_question( $quiz_id, $quiz_pro_id, array $v, $points, $sort, &$error ) {
		$error = '';

		$answer_models = array();
		foreach ( $v['answers'] as $i => $text ) {
			$is_correct = ( $i === $v['correct'] );
			$model      = new WpProQuiz_Model_AnswerTypes();
			$model->setAnswer( $text );
			$model->setHtml( wp_strip_all_tags( $text ) !== $text );
			$model->setCorrect( $is_correct );
			$model->setPoints( $is_correct ? $points : 0 );
			$answer_models[] = $model;
		}

		$question = new WpProQuiz_Model_Question();
		$question->setQuizId( $quiz_pro_id );
		$question->setTitle( $v['title'] );
		$question->setQuestion( $v['text'] );
		$question->setAnswerType( 'single' );
		$question->setPoints( $points );
		$question->setSort( $sort );
		$question->setCorrectMsg( '' );
		$question->setIncorrectMsg( '' );
		$question->setAnswerData( $answer_models );
		if ( method_exists( $question, 'setOnline' ) ) {
			$question->setOnline( true );
		}

		$mapper = new WpProQuiz_Model_QuestionMapper();
		$saved  = $mapper->save( $question );
		$pro_id = ( $saved instanceof WpProQuiz_Model_Question ) ? (int) $saved->getId() : (int) $question->getId();
		if ( ! $pro_id ) {
			$error = __( 'LearnDash could not save the question.', 'quiz-answers-extender-bulk-import-for-learndash' );
			return false;
		}

		// meta_input is written before save_post fires, so LearnDash sees the link immediately
		// and does not create a second ProQuiz question for this post.
		// wp_insert_post() unslashes its input, so slash text to keep backslashes intact.
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'sfwd-question',
				'post_status'  => 'publish',
				'post_title'   => wp_slash( $v['title'] ),
				'post_content' => wp_slash( $v['text'] ),
				'post_author'  => get_current_user_id(),
				'menu_order'   => $sort,
				'meta_input'   => array(
					'question_pro_id'     => $pro_id,
					'question_type'       => 'single',
					'question_points'     => $points,
					'quiz_id'             => (int) $quiz_id,
					'ld_quiz_' . $quiz_id => (int) $quiz_id,
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			if ( method_exists( $mapper, 'delete' ) ) {
				$mapper->delete( $pro_id ); // Roll back the orphaned ProQuiz row.
			}
			$error = is_wp_error( $post_id ) ? $post_id->get_error_message() : __( 'Could not create the question post.', 'quiz-answers-extender-bulk-import-for-learndash' );
			return false;
		}

		if ( function_exists( 'learndash_update_setting' ) ) {
			learndash_update_setting( $post_id, 'quiz', (int) $quiz_id );
		}

		LDAE_Helpers::save_explanations( $post_id, $v['explanations'] );

		return array(
			'post_id' => (int) $post_id,
			'pro_id'  => $pro_id,
		);
	}

	/**
	 * Append the new questions to the Quiz Builder list.
	 *
	 * @param int   $quiz_id   Quiz post ID.
	 * @param array $new_links question_post_id => question_pro_id.
	 */
	private static function attach_to_quiz( $quiz_id, array $new_links ) {
		$current = LDAE_Helpers::get_quiz_question_map( $quiz_id );
		foreach ( $new_links as $post_id => $pro_id ) {
			$current[ (int) $post_id ] = (int) $pro_id;
		}

		$handled = false;
		if ( class_exists( 'LDLMS_Factory_Post' ) && method_exists( 'LDLMS_Factory_Post', 'quiz_questions' ) ) {
			$quiz_questions = LDLMS_Factory_Post::quiz_questions( (int) $quiz_id );
			if ( is_object( $quiz_questions ) && method_exists( $quiz_questions, 'set_questions' ) ) {
				$quiz_questions->set_questions( $current ); // LearnDash's own API (saves meta + clears its cache).
				$handled = true;
			}
		}
		// Always make sure the meta itself is correct.
		$saved = get_post_meta( $quiz_id, 'ld_quiz_questions', true );
		if ( ! $handled || ! is_array( $saved ) || array_diff_key( $new_links, $saved ) ) {
			update_post_meta( $quiz_id, 'ld_quiz_questions', $current );
		}
	}

	// Sample CSV.

	/**
	 * Stream a sample CSV file.
	 */
	public static function download_sample() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'quiz-answers-extender-bulk-import-for-learndash' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SAMPLE_ACTION );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=quiz-answers-extender-bulk-import-sample.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, self::columns(), ',', '"', '' );
		fputcsv( $out, array( 'Capital of France', 'What is the capital of France?', 'Berlin', 'Madrid', 'Paris', 'Rome', '3', 'Berlin is the capital of Germany.', 'Madrid is the capital of Spain.', 'Correct! Paris has been the capital since 987 AD.', 'Rome is the capital of Italy.' ), ',', '"', '' );
		fputcsv( $out, array( 'Largest planet', 'Which planet is the largest in our solar system?', 'Jupiter', 'Saturn', 'Earth', '', '1', 'Correct! Jupiter is more than twice as massive as all other planets combined.', 'Saturn is the second largest.', 'Earth is much smaller than the gas giants.', '' ), ',', '"', '' );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
