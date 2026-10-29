/**
 * Quiz Builder (sfwd-quiz edit screen): attaches an "Answer explanations" panel to every
 * question row of the native LearnDash Quiz Builder.
 *
 * The builder is a React app without an extension API for answer fields, so rows are found
 * through their question "edit" links (post.php?post=ID&action=edit) restricted to the
 * question IDs of this quiz. Panels are re-attached automatically if React re-renders a row.
 * Loading/saving goes through nonce-protected admin-ajax endpoints.
 */
(function ($) {
	'use strict';

	var cfg = window.LDAE_Builder;
	if (!cfg || !cfg.questionIds || !cfg.questionIds.length) {
		return;
	}

	var i18n = cfg.i18n || {};
	var supported = cfg.supportedTypes || ['single'];
	var idSet = {};
	var state = {};
	var scheduled = false;

	cfg.questionIds.forEach(function (id) {
		idSet[parseInt(id, 10)] = true;
	});

	function has(obj, key) {
		return Object.prototype.hasOwnProperty.call(obj, key);
	}

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) {
			node.className = cls;
		}
		if (text !== undefined && text !== null) {
			node.textContent = text;
		}
		return node;
	}

	function getState(qid) {
		if (!state[qid]) {
			state[qid] = { open: false, data: null, drafts: {}, loading: false, error: '', status: '', statusKind: '' };
		}
		return state[qid];
	}

	function roots() {
		var found = [];
		try {
			found = Array.prototype.slice.call(document.querySelectorAll(cfg.rootSelectors || ''));
		} catch (e) {
			found = [];
		}
		if (!found.length) {
			var fallback = document.querySelector(cfg.fallbackRoot || '#wpbody-content');
			if (fallback) {
				found = [fallback];
			}
		}
		return found;
	}

	function questionIdFromLink(a) {
		var href = a.getAttribute('href') || '';
		if (href.indexOf('post.php') === -1 || href.indexOf('action=edit') === -1) {
			return 0;
		}
		var m = href.match(/[?&]post=(\d+)/);
		var id = m ? parseInt(m[1], 10) : 0;
		return idSet[id] ? id : 0;
	}

	function distinctQuestionCount(node) {
		var seen = {};
		var count = 0;
		node.querySelectorAll('a[href*="post.php"]').forEach(function (a) {
			var id = questionIdFromLink(a);
			if (id && !seen[id]) {
				seen[id] = true;
				count++;
			}
		});
		return count;
	}

	/**
	 * The row is the highest ancestor of the link that still contains only this question.
	 */
	function findRow(link, root) {
		var node = link;
		var depth = 0;
		while (node.parentElement && node.parentElement !== root && depth < 12) {
			if (distinctQuestionCount(node.parentElement) > 1) {
				return node;
			}
			node = node.parentElement;
			depth++;
		}
		// Quiz with a single question: use the nearest list item inside the builder.
		var li = link.closest('li');
		if (li && root.contains(li)) {
			return li;
		}
		return link.parentElement;
	}

	function scan() {
		scheduled = false;
		roots().forEach(function (root) {
			root.querySelectorAll('a[href*="post.php"]').forEach(function (a) {
				var qid = questionIdFromLink(a);
				if (!qid || a.closest('.ldae-builder')) {
					return;
				}
				var row = findRow(a, root);
				if (!row) {
					return;
				}
				if (row === a || row.tagName === 'A') {
					var next = a.nextElementSibling;
					if (!(next && next.classList.contains('ldae-builder'))) {
						a.insertAdjacentElement('afterend', buildPanel(qid));
					}
					return;
				}
				if (!row.querySelector('.ldae-builder[data-qid="' + qid + '"]')) {
					row.appendChild(buildPanel(qid));
				}
			});
		});
	}

	function schedule() {
		if (scheduled) {
			return;
		}
		scheduled = true;
		(window.requestAnimationFrame || setTimeout)(scan);
	}

	function panelsFor(qid) {
		return Array.prototype.slice.call(document.querySelectorAll('.ldae-builder[data-qid="' + qid + '"]'));
	}

	function buildPanel(qid) {
		var st = getState(qid);

		var wrap = el('div', 'ldae-builder');
		wrap.setAttribute('data-qid', String(qid));

		var toggle = el('button', 'button button-small ldae-builder__toggle', i18n.toggle);
		toggle.type = 'button';

		var panel = el('div', 'ldae-builder__panel');
		var body = el('div', 'ldae-builder__body');
		var actions = el('div', 'ldae-builder__actions');

		var save = el('button', 'button button-primary button-small ldae-builder__save', i18n.save);
		save.type = 'button';
		var reload = el('button', 'button button-small ldae-builder__reload', i18n.reload);
		reload.type = 'button';
		var status = el('span', 'ldae-builder__status');
		status.setAttribute('aria-live', 'polite');

		actions.appendChild(save);
		actions.appendChild(reload);
		actions.appendChild(status);
		panel.appendChild(body);
		panel.appendChild(actions);
		wrap.appendChild(toggle);
		wrap.appendChild(panel);

		// Keep the builder's drag-and-drop / row toggles / shortcuts away from our panel.
		['mousedown', 'pointerdown', 'touchstart', 'click', 'keydown', 'keyup', 'keypress', 'dragstart'].forEach(function (type) {
			wrap.addEventListener(type, function (e) {
				e.stopPropagation();
			});
		});

		toggle.addEventListener('click', function (e) {
			e.preventDefault();
			st.open = !st.open;
			renderAll(qid);
			if (st.open && !st.data && !st.loading) {
				load(qid);
			}
		});
		reload.addEventListener('click', function (e) {
			e.preventDefault();
			st.drafts = {};
			load(qid);
		});
		save.addEventListener('click', function (e) {
			e.preventDefault();
			saveExplanations(qid);
		});

		render(wrap, qid);
		return wrap;
	}

	function renderAll(qid) {
		panelsFor(qid).forEach(function (wrap) {
			render(wrap, qid);
		});
	}

	function render(wrap, qid) {
		var st = getState(qid);
		var toggle = wrap.querySelector('.ldae-builder__toggle');
		var panel = wrap.querySelector('.ldae-builder__panel');
		var body = wrap.querySelector('.ldae-builder__body');
		var save = wrap.querySelector('.ldae-builder__save');
		var status = wrap.querySelector('.ldae-builder__status');

		panel.hidden = !st.open;
		toggle.setAttribute('aria-expanded', st.open ? 'true' : 'false');
		status.textContent = st.status || '';
		status.className = 'ldae-builder__status' + (st.statusKind ? ' is-' + st.statusKind : '');

		body.textContent = '';
		save.disabled = true;

		if (st.loading) {
			body.appendChild(el('p', 'description', i18n.loading));
			return;
		}
		if (st.error) {
			body.appendChild(el('p', 'ldae-builder__error', st.error));
			return;
		}
		if (!st.data) {
			return;
		}
		if (supported.indexOf(st.data.answer_type) === -1) {
			body.appendChild(el('p', 'description', i18n.notSingle));
			return;
		}
		if (!st.data.answers || !st.data.answers.length) {
			body.appendChild(el('p', 'description', i18n.noAnswers));
			return;
		}

		save.disabled = false;
		body.appendChild(el('p', 'description', i18n.savedNote));

		st.data.answers.forEach(function (answer, n) {
			var key = String(answer.index);
			var id = 'ldae-b-' + qid + '-' + key;
			var row = el('div', 'ldae-builder__answer');

			var label = el('label', 'ldae-builder__answer-label');
			label.htmlFor = id;
			label.appendChild(document.createTextNode((i18n.answer || 'Answer %d').replace('%d', n + 1) + ': ' + (answer.text || '—')));
			if (answer.correct) {
				label.appendChild(el('span', 'ldae-builder__correct', '✓ ' + i18n.correct));
			}

			var ta = el('textarea', 'large-text ldae-builder__input');
			ta.id = id;
			ta.rows = 2;
			ta.placeholder = i18n.placeholder || '';
			ta.value = has(st.drafts, key) ? st.drafts[key] : (st.data.explanations[key] || '');
			ta.addEventListener('input', function () {
				st.drafts[key] = ta.value;
			});

			row.appendChild(label);
			row.appendChild(ta);
			body.appendChild(row);
		});
	}

	function errorMessage(payload) {
		return (payload && payload.data && payload.data.message) ? payload.data.message : i18n.error;
	}

	function load(qid) {
		var st = getState(qid);
		st.loading = true;
		st.error = '';
		st.status = '';
		renderAll(qid);

		$.post(cfg.ajaxUrl, { action: 'ldae_get_question_answers', nonce: cfg.nonce, question_id: qid })
			.done(function (res) {
				if (res && res.success) {
					st.data = res.data;
					st.data.explanations = st.data.explanations || {};
				} else {
					st.error = errorMessage(res);
				}
			})
			.fail(function (xhr) {
				st.error = errorMessage(xhr.responseJSON);
			})
			.always(function () {
				st.loading = false;
				renderAll(qid);
			});
	}

	function saveExplanations(qid) {
		var st = getState(qid);
		if (!st.data) {
			return;
		}
		var payload = {};
		st.data.answers.forEach(function (answer) {
			var key = String(answer.index);
			payload[key] = has(st.drafts, key) ? st.drafts[key] : (st.data.explanations[key] || '');
		});

		st.status = i18n.saving;
		st.statusKind = '';
		renderAll(qid);

		$.post(cfg.ajaxUrl, { action: 'ldae_save_question_explanations', nonce: cfg.nonce, question_id: qid, explanations: payload })
			.done(function (res) {
				if (res && res.success) {
					st.data.explanations = res.data.explanations || {};
					st.drafts = {};
					st.status = i18n.saved;
					st.statusKind = 'success';
				} else {
					st.status = errorMessage(res);
					st.statusKind = 'error';
				}
			})
			.fail(function (xhr) {
				st.status = errorMessage(xhr.responseJSON);
				st.statusKind = 'error';
			})
			.always(function () {
				renderAll(qid);
			});
	}

	$(function () {
		scan();
		new MutationObserver(schedule).observe(document.getElementById('wpbody-content') || document.body, {
			childList: true,
			subtree: true
		});
	});
})(jQuery);
