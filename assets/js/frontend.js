/**
 * Results review: when a student clicks "View Questions" (input[name="reShowQuestion"]),
 * fetch the explanations for this quiz and print each one beneath its answer.
 *
 * Mapping: every answer <li class="wpProQuiz_questionListItem"> carries data-pos = the answer's
 * index in LearnDash's answer_data (stable even when answers are displayed in random order).
 */
(function ($) {
	'use strict';

	var cfg = window.LDAE_Front;
	if (!cfg) {
		return;
	}

	var cache = {};

	function parseJsonAttr(node, attr) {
		var raw = node ? node.getAttribute(attr) : null;
		if (!raw) {
			return null;
		}
		try {
			return JSON.parse(raw);
		} catch (e) {
			return null;
		}
	}

	function quizIds($content) {
		var meta = parseJsonAttr($content[0], 'data-quiz-meta') || {};
		var postId = parseInt(meta.quiz_post_id, 10) || 0;
		var proId = parseInt(meta.quiz_pro_id, 10) || 0;
		if (!proId) {
			var m = String($content.attr('id') || '').match(/^wpProQuiz_(\d+)/);
			proId = m ? parseInt(m[1], 10) : 0;
		}
		return { postId: postId, proId: proId };
	}

	function load(ids) {
		var key = ids.postId + ':' + ids.proId;
		if (cache[key]) {
			return cache[key];
		}
		var deferred = $.Deferred();
		var attempts = 0;

		function attempt() {
			attempts++;
			$.ajax({
				url: cfg.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'ldae_get_quiz_explanations',
					nonce: cfg.nonce,
					quiz_post_id: ids.postId,
					quiz_pro_id: ids.proId
				}
			}).done(function (res) {
				if (res && res.success) {
					deferred.resolve(res.data);
				} else {
					failed(res && res.data);
				}
			}).fail(function (xhr) {
				failed(xhr.responseJSON && xhr.responseJSON.data);
			});
		}

		function failed(data) {
			// LearnDash may still be saving the attempt when "View Questions" is clicked.
			if (data && data.code === 'not_completed' && attempts < 4) {
				setTimeout(attempt, 1000 * attempts);
				return;
			}
			delete cache[key];
			deferred.reject(data);
		}

		attempt();
		cache[key] = deferred.promise();
		return cache[key];
	}

	function render($content, data) {
		var byPost = {};
		var byPro = {};
		$.each((data && data.questions) || [], function (_, q) {
			if (q.question_post_id) {
				byPost[q.question_post_id] = q.explanations;
			}
			if (q.question_pro_id) {
				byPro[q.question_pro_id] = q.explanations;
			}
		});

		$content.find('.wpProQuiz_listItem').each(function () {
			var item = this;
			var $list = $(item).find('.wpProQuiz_questionList').first();
			var meta = parseJsonAttr(item, 'data-question-meta') || {};
			var type = meta.type || item.getAttribute('data-type') || $list.attr('data-type') || '';

			if (type && type !== 'single') {
				return;
			}

			var explanations = (meta.question_post_id && byPost[meta.question_post_id]) ||
				byPro[meta.question_pro_id] ||
				byPro[$list.attr('data-question_id')];
			if (!explanations) {
				return;
			}

			$list.children('.wpProQuiz_questionListItem').each(function (domIndex) {
				var li = this;
				if (li.querySelector('.ldae-explanation')) {
					return; // Already rendered.
				}
				var pos = li.getAttribute('data-pos');
				var key = (pos !== null && pos !== '') ? String(pos) : String(domIndex);
				var html = explanations[key];
				if (!html) {
					return;
				}
				// The visible answer card is the <label>; render inside it.
				var card = li.querySelector('label') || li;
				card.appendChild(buildExplanation(html, answerState(li, card)));
				card.classList.add('ldae-has-explanation');
				li.classList.add('ldae-has-explanation');
			});
		});
	}

	/**
	 * Single-choice questions in the review list (the only type explanations support).
	 */
	function reviewLists($content) {
		var lists = [];
		$content.find('.wpProQuiz_listItem').each(function () {
			var $list = $(this).find('.wpProQuiz_questionList').first();
			var meta = parseJsonAttr(this, 'data-question-meta') || {};
			var type = meta.type || this.getAttribute('data-type') || $list.attr('data-type') || '';
			if (!type || type === 'single') {
				lists.push($list);
			}
		});
		return lists;
	}

	/**
	 * Show a "Loading..." row inside every answer card while explanations are fetched.
	 */
	function showLoading($content) {
		var text = (cfg.i18n && cfg.i18n.loading) || 'Loading...';
		$.each(reviewLists($content), function (_, $list) {
			$list.children('.wpProQuiz_questionListItem').each(function () {
				var li = this;
				var card = li.querySelector('label') || li;
				if (li.querySelector('.ldae-explanation') || card.querySelector('.ldae-loading')) {
					return;
				}
				var box = document.createElement('div');
				box.className = 'ldae-loading';
				box.setAttribute('role', 'status');
				box.setAttribute('aria-live', 'polite');

				var spinner = document.createElement('span');
				spinner.className = 'ldae-loading__spinner';
				spinner.setAttribute('aria-hidden', 'true');

				var label = document.createElement('span');
				label.className = 'ldae-loading__text';
				label.textContent = text;

				box.appendChild(spinner);
				box.appendChild(label);
				card.appendChild(box);
				card.classList.add('ldae-is-loading');
			});
		});
	}

	function hideLoading($content) {
		$content.find('.ldae-loading').remove();
		$content.find('.ldae-is-loading').removeClass('ldae-is-loading');
	}

	/**
	 * correct   = the right answer (LearnDash marks it in review),
	 * incorrect = the student's wrong choice,
	 * neutral   = any other option.
	 */
	function answerState(li, card) {
		if ($(li).hasClass('wpProQuiz_answerCorrect') || $(card).hasClass('wpProQuiz_answerCorrect')) {
			return 'correct';
		}
		if ($(li).hasClass('wpProQuiz_answerIncorrect') || $(card).hasClass('wpProQuiz_answerIncorrect')) {
			return 'incorrect';
		}
		return 'neutral';
	}

	function buildExplanation(html, state) {
		var labels = (cfg.i18n && cfg.i18n.states) || {};
		var box = document.createElement('div');
		box.className = 'ldae-explanation ldae-explanation--' + state;
		box.setAttribute('role', 'note');

		var body = document.createElement('div');
		body.className = 'ldae-explanation__body';

		var heading = document.createElement('span');
		heading.className = 'ldae-explanation__label';
		heading.textContent = labels[state] || (cfg.i18n && cfg.i18n.label) || 'Explanation';

		var text = document.createElement('div');
		text.className = 'ldae-explanation__text';
		text.innerHTML = html; // Sanitised server-side with wp_kses (limited inline HTML).

		body.appendChild(heading);
		body.appendChild(text);
		box.appendChild(body);
		return box;
	}

	$(document).on('click', 'input[name="reShowQuestion"], .wpProQuiz_button[name="reShowQuestion"]', function () {
		var $content = $(this).closest('.wpProQuiz_content');
		if (!$content.length) {
			return;
		}
		var ids = quizIds($content);
		if (!ids.postId && !ids.proId) {
			return;
		}
		var request = load(ids);
		var pending = request.state() === 'pending';

		// Let LearnDash finish building/marking the review list first.
		setTimeout(function () {
			if (pending && request.state() === 'pending') {
				showLoading($content);
			}
			request.done(function (data) {
				setTimeout(function () {
					hideLoading($content);
					render($content, data);
				}, pending ? 0 : 50);
			}).fail(function () {
				hideLoading($content);
			});
		}, 50);
	});

	/**
	 * Prefetch: start loading explanations as soon as the results screen appears,
	 * so they are usually ready by the time "View Questions" is clicked.
	 */
	function prefetch(resultsEl) {
		var $content = $(resultsEl).closest('.wpProQuiz_content');
		if (!$content.length || resultsEl.style.display === 'none') {
			return;
		}
		var ids = quizIds($content);
		if (ids.postId || ids.proId) {
			load(ids);
		}
	}

	if (window.MutationObserver) {
		$(function () {
			$('.wpProQuiz_content .wpProQuiz_results').each(function () {
				var el = this;
				new MutationObserver(function () {
					prefetch(el);
				}).observe(el, { attributes: true, attributeFilter: ['style', 'class'] });
			});
		});
	}

	// Remove explanations when the quiz is restarted.
	$(document).on('click', 'input[name="restartQuiz"]', function () {
		var $content = $(this).closest('.wpProQuiz_content');
		hideLoading($content);
		$content.find('.ldae-explanation').remove();
		$content.find('.ldae-has-explanation').removeClass('ldae-has-explanation');
		var ids = quizIds($content);
		delete cache[ids.postId + ':' + ids.proId];
	});
})(jQuery);
