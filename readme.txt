=== Quiz Answers Extender & Bulk Import for LearnDash ===
Contributors: saqibabbasi
Tags: learndash, quiz, explanation, csv import, lms
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show explanations for every answer option of LearnDash Single Choice questions after quiz submission, and bulk import quiz questions from CSV.

== Description ==

Quiz Answers Extender & Bulk Import for LearnDash lets you explain *why* each answer of a quiz question is right or wrong. After finishing a quiz, students click **View Questions** on the results screen and see the explanation printed directly under every answer.

**Features**

* An "Answer Explanation" field under every answer in the LearnDash question editor (works with the classic editor, the block editor and the legacy LearnDash question editor).
* An "Answer explanations" panel on every question in the LearnDash Quiz Builder.
* Explanations shown on the quiz results "View Questions" review, with a heading that reflects the answer: "Why this is correct", "Why your answer is incorrect" or "Explanation".
* Explanations are loaded only after the quiz is completed, so students cannot read them in the page source before answering.
* A "Loading..." indicator while explanations are fetched, and prefetching as soon as the results screen appears so they usually show instantly.
* Bulk CSV importer (LearnDash LMS > Import Questions) that creates Single Choice questions, their answers and explanations, and adds them to the selected quiz.
* Duplicate detection by question title (within the quiz or across the whole site).
* Limited, safe inline HTML in explanations: links, bold, italic, underline, line breaks, code, subscript and superscript.

**Requirements**

This plugin is an add-on for [LearnDash LMS](https://www.learndash.com/), which must be installed and active. The plugin cannot be activated while LearnDash LMS is inactive.

This is an independent plugin. It is not affiliated with, endorsed by, or sponsored by LearnDash. LearnDash is a separate, commercial product, and LearnDash is a trademark of its respective owner.

Explanations currently support **Single Choice** questions.

== Installation ==

1. Install and activate LearnDash LMS (required; activation is refused without it).
2. Upload the `quiz-answers-extender-bulk-import-for-learndash` folder to `/wp-content/plugins/`, or install the plugin from the Plugins > Add New screen.
3. Activate the plugin through the Plugins screen.
4. Edit any Single Choice question and fill in the "Answer Explanation" field under each answer, or open LearnDash LMS > Import Questions to import questions from a CSV file.

== Frequently Asked Questions ==

= Which question types are supported? =

Single Choice questions. Other question types are left untouched.

= When do students see the explanations? =

On the quiz results screen, after they click "View Questions". Logged-in students must have completed the quiz. Guests can see explanations for quizzes that are not part of a course, or that belong to an "open" course.

= What is the CSV format? =

The first row must contain these column headers:

`question_title, question_text, answer_1, answer_2, answer_3, answer_4, correct_answer, explanation_1, explanation_2, explanation_3, explanation_4`

* At least two answers are required; empty answer columns are skipped.
* `correct_answer` is the number (1-4) of the correct answer column.
* `question_title` or `question_text` is required (the other is filled in automatically).
* Comma or semicolon separated, UTF-8 encoded. Files saved by Excel as Windows-1252 are converted automatically.

A sample file can be downloaded from the importer page.

= Who can use the importer? =

Users with the `manage_options` capability (administrators) by default. Change it with the `ldae_import_capability` filter.

= Can I customise the plugin? =

Yes, with these filters:

* `ldae_supported_answer_types` - answer types that get explanations (default `array( 'single' )`).
* `ldae_allowed_explanation_html` - allowed inline HTML, as a `wp_kses()` array.
* `ldae_user_can_view_explanations` - final decision on who may load explanations.
* `ldae_allow_guest_explanations` - whether guests may load explanations for a quiz.
* `ldae_enqueue_frontend` - load the frontend assets on custom pages that display a quiz.
* `ldae_builder_root_selectors` - CSS selectors of the Quiz Builder container.
* `ldae_import_capability` - capability required for the importer.

= What happens to my data when I delete the plugin? =

Quiz completion records used by the plugin are removed. Answer explanations are kept, so they are not lost if you reinstall. To delete them too (including explanations of questions edited in the legacy question editor), add `define( 'LDAE_DELETE_EXPLANATIONS_ON_UNINSTALL', true );` to `wp-config.php` before deleting the plugin.

== Changelog ==

= 1.0.4 =
* Changed: the plugin is renamed to "Quiz Answers Extender & Bulk Import for LearnDash" (slug and text domain `quiz-answers-extender-bulk-import-for-learndash`). Features, settings, hooks and stored data are unchanged; explanations saved by earlier versions keep working.

= 1.0.3 =
* New: the plugin can only be activated when LearnDash LMS is active, with a clear message otherwise.

= 1.0.2 =
* Fix: when LearnDash's legacy question editor saves a question under a new question ID, the explanations now follow the question instead of staying on the old ID. LearnDash's editor URLs are not changed.
* New: explanations of a question that LearnDash re-created under a new ID are recovered automatically when the question's quiz, title and answers are unchanged.

= 1.0.1 =
* Fix: explanations entered in LearnDash's legacy question editor were not saved for questions that have no LearnDash question post. They are now saved, shown again after updating, and displayed on the results review.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.4 =
New plugin name and slug. No functional changes; existing explanations are kept.

= 1.0.3 =
Adds a LearnDash LMS requirement check on activation.

= 1.0.2 =
Explanations now stay with a question when LearnDash's legacy editor saves it under a new ID.

= 1.0.1 =
Fixes explanations not saving in the legacy LearnDash question editor.

= 1.0.0 =
Initial release.
