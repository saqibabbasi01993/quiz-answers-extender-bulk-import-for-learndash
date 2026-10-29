/**
 * Question editor (sfwd-question): injects an "Answer Explanation" textarea directly under
 * every answer of LearnDash's native "Answers" box.
 *
 * Answer rows are detected by their form fields (a textarea named answerData[..][answer]
 * plus a "Correct" input in the same row), not by container class names, so it works with
 * both the classic list layout and the newer "Options | Answer" table layout. The editor is
 * also re-scanned whenever LearnDash adds, removes or re-renders rows.
 *
 * Values are sent through one hidden JSON field (#ldae-explanations-payload). If inline
 * injection is impossible, the server-rendered fallback fields in the
 * "Answer Explanations" meta box remain active instead.
 */
(function ($) {
	'use strict';

	var cfg = window.LDAE_Editor || {};
	var i18n = cfg.i18n || {};
	var supported = cfg.supportedTypes || ['single'];
	var initial = Array.isArray(cfg.explanations) ? cfg.explanations : [];
	var known = typeof WeakSet !== 'undefined' ? new WeakSet() : null;
	var initialised = false;
	var active = false;
	var scheduled = false;

	function log() {
		if (window.LDAE_DEBUG && window.console) {
			console.log.apply(console, ['[LDAE]'].concat([].slice.call(arguments)));
		}
	}

	/* ---------- Finding answer rows ---------- */

	function isAnswerTextarea(ta) {
		var name = ta.getAttribute('name') || '';
		return name.indexOf('answerData') === 0 && /\[answer\]$/.test(name);
	}

	function correctInput(row) {
		return row.querySelector('input[name^="answerData"][name$="[correct]"], input.wpProQuiz_classCorrect');
	}

	function rowFor(ta) {
		// The row is the list item that holds this answer (classic and table layouts both use <li>).
		var li = ta.closest('li');
		if (li) {
			return li;
		}
		var table = ta.closest('table');
		return table ? (table.parentElement || table) : null;
	}

	function isTemplate(row) {
		return row.style.display === 'none' || /template/i.test(row.className || '') || row.hasAttribute('data-template');
	}

	function rows() {
		var list = [];
		document.querySelectorAll('textarea[name^="answerData"]').forEach(function (ta) {
			if (!isAnswerTextarea(ta) || ta.closest('#ldae-answer-explanations')) {
				return;
			}
			var row = rowFor(ta);
			// Only Single/Multiple choice rows have a "Correct" input.
			if (!row || list.indexOf(row) !== -1 || isTemplate(row) || !correctInput(row)) {
				return;
			}
			list.push(row);
		});
		return list;
	}

	/* ---------- Answer type ---------- */

	function answerType(list) {
		var checked = document.querySelector('input[name="answerType"]:checked');
		if (checked) {
			return String(checked.value);
		}
		var select = document.querySelector('select[name="answerType"]');
		if (select && select.value) {
			return String(select.value);
		}
		// Single choice shows "Correct" as a radio button, multiple choice as a checkbox.
		for (var i = 0; i < list.length; i++) {
			var input = correctInput(list[i]);
			if (input) {
				return input.type === 'radio' ? 'single' : 'multiple';
			}
		}
		return String(cfg.answerType || '');
	}

	/* ---------- Field ---------- */

	function buildField(value) {
		var id = 'ldae-exp-' + Math.random().toString(36).slice(2, 10);
		var wrap = document.createElement('div');
		wrap.className = 'ldae-explanation-field';

		var label = document.createElement('label');
		label.setAttribute('for', id);
		label.textContent = i18n.label || 'Answer Explanation';

		var ta = document.createElement('textarea');
		ta.id = id;
		ta.className = 'large-text ldae-explanation-input';
		ta.rows = 2;
		ta.placeholder = i18n.placeholder || '';
		ta.value = value || '';

		wrap.appendChild(label);
		wrap.appendChild(ta);
		return wrap;
	}

	function insertField(row, field) {
		// Directly under the answer box: after the "Options | Answer" table when present.
		var answer = row.querySelector('textarea[name^="answerData"][name$="[answer]"]');
		var table = answer ? answer.closest('table') : null;
		if (table && row.contains(table) && table !== row) {
			table.parentNode.insertBefore(field, table.nextSibling);
		} else {
			row.appendChild(field);
		}
	}

	/* ---------- Hidden fields (payload + nonce) ---------- */

	function ensureHiddenFields(list) {
		var payload = document.getElementById('ldae-explanations-payload');
		var nonce = document.querySelector('input[name="ldae_question_nonce"]');

		if (!payload) {
			payload = document.createElement('input');
			payload.type = 'hidden';
			payload.id = 'ldae-explanations-payload';
			payload.name = 'ldae_explanations_payload';
		}
		if (!nonce && cfg.nonce) {
			nonce = document.createElement('input');
			nonce.type = 'hidden';
			nonce.name = 'ldae_question_nonce';
			nonce.value = cfg.nonce;
		}

		// Keep them in the same form as the answers so they are always submitted together
		// (the block editor submits meta boxes per location).
		var answersForm = list[0].closest('form');
		var host = answersForm || list[0].parentElement || list[0];
		[payload, nonce].forEach(function (input) {
			if (input && (!input.form || input.form !== answersForm || !document.body.contains(input))) {
				host.appendChild(input);
			}
		});
		return payload;
	}

	function setFallback(enabled) {
		var box = document.getElementById('ldae-fallback');
		if (!box) {
			return;
		}
		box.hidden = !enabled;
		box.querySelectorAll('textarea').forEach(function (ta) {
			ta.disabled = !enabled; // Disabled fields are not submitted.
		});
		var note = document.getElementById('ldae-inline-note');
		if (note) {
			note.hidden = enabled;
		}
	}

	/* ---------- Main loop ---------- */

	function decorate() {
		scheduled = false;
		var list = rows();
		if (!list.length) {
			log('No answer rows found yet.');
			return;
		}

		var supportedType = supported.indexOf(answerType(list)) !== -1;

		list.forEach(function (row, index) {
			var field = row.querySelector('.ldae-explanation-field');
			if (field) {
				// Row cloned by "Add new answer": start the copy empty.
				if (known && !known.has(row)) {
					field.querySelector('textarea').value = '';
					known.add(row);
				}
			} else {
				insertField(row, buildField(initialised ? '' : (initial[index] || '')));
				if (known) {
					known.add(row);
				}
			}
		});

		if (!initialised) {
			initialised = true;
			log('Explanation fields added to', list.length, 'answers.');
		}

		$('.ldae-explanation-field').toggle(supportedType);
		active = true;
		setFallback(false);
		ensureHiddenFields(list);
		sync();
	}

	function schedule() {
		if (scheduled) {
			return;
		}
		scheduled = true;
		setTimeout(decorate, 60);
	}

	function sync() {
		if (!active) {
			return;
		}
		var list = rows();
		var payload = document.getElementById('ldae-explanations-payload');
		if (!payload || !list.length) {
			return;
		}
		if (supported.indexOf(answerType(list)) === -1) {
			payload.value = ''; // Not single choice: server keeps stored data untouched.
			return;
		}
		var entries = list.map(function (row) {
			var answerEl = row.querySelector('textarea[name^="answerData"][name$="[answer]"]');
			var expEl = row.querySelector('.ldae-explanation-input');
			return {
				answer: answerEl ? answerEl.value : '',
				explanation: expEl ? expEl.value : ''
			};
		});
		payload.value = JSON.stringify(entries);
	}

	function syncBeforeSave() {
		if (window.tinyMCE && typeof window.tinyMCE.triggerSave === 'function') {
			window.tinyMCE.triggerSave();
		}
		sync();
	}

	$(function () {
		decorate();
		// LearnDash may render or re-render the answers box after page load.
		[300, 1000, 2500, 5000].forEach(function (ms) {
			setTimeout(decorate, ms);
		});
		new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });

		$(document).on('input change', 'textarea[name^="answerData"], .ldae-explanation-input', sync);
		$(document).on('change click', 'input[name="answerType"], select[name="answerType"], input[name$="[correct]"]', schedule);
		$(document).on('sortupdate sortstop', schedule);
		$(document).on('submit', 'form', syncBeforeSave);

		if (window.wp && wp.data && typeof wp.data.subscribe === 'function') {
			var wasSaving = false;
			wp.data.subscribe(function () {
				var editor = wp.data.select('core/editor');
				if (!editor || typeof editor.isSavingPost !== 'function') {
					return;
				}
				var saving = editor.isSavingPost();
				if (saving && !wasSaving) {
					syncBeforeSave();
				}
				wasSaving = saving;
			});
		}
	});
})(jQuery);
