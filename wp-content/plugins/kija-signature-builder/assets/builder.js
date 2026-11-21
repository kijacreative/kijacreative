/**
 * Kija Signature Builder.
 *
 * Builds email-safe signature markup from a profile stored on the user's
 * WordPress account. Photos come from the media library, so their URLs are
 * served by this site and load for every recipient.
 */
(function () {
	'use strict';

	var cfg = window.KJSB || {};
	var strings = cfg.strings || {};
	var WIDTH = 512;

	var state = merge(cfg.defaults || {}, cfg.profile || {});
	var currentHtml = '';
	var saveTimer = null;
	var mediaFrame = null;

	/* ------------------------------------------------------------------ */
	/* helpers                                                             */
	/* ------------------------------------------------------------------ */

	function merge(base, over) {
		var out = JSON.parse(JSON.stringify(base));
		Object.keys(over || {}).forEach(function (k) {
			out[k] = over[k];
		});
		if (!Array.isArray(out.links)) {
			out.links = [];
		}
		return out;
	}

	function esc(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function safeUrl(raw) {
		var s = String(raw === null || raw === undefined ? '' : raw).trim();
		if (!s) {
			return '';
		}
		if (/^(https?:|mailto:|tel:)/i.test(s)) {
			return s;
		}
		if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s)) {
			return 'mailto:' + s;
		}
		if (/^[+(]?[\d][\d\s().-]{5,}$/.test(s)) {
			return 'tel:' + s.replace(/[^\d+]/g, '');
		}
		if (/^(javascript|data|vbscript):/i.test(s)) {
			return '';
		}
		return 'https://' + s.replace(/^\/+/, '');
	}

	function safeImg(raw) {
		var s = String(raw === null || raw === undefined ? '' : raw).trim();
		if (!s) {
			return '';
		}
		if (/^https?:\/\//i.test(s)) {
			return s;
		}
		if (/^\/\//.test(s)) {
			return 'https:' + s;
		}
		if (/^[\w.-]+\.[a-z]{2,}\//i.test(s)) {
			return 'https://' + s;
		}
		return '';
	}

	function color(raw, fallback) {
		var s = String(raw === null || raw === undefined ? '' : raw).trim();
		return /^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(s) ? s : fallback;
	}

	function gap(px) {
		return '<div style="height:' + px + 'px;line-height:' + px + 'px;font-size:0;">&nbsp;</div>';
	}

	function byId(id) {
		return document.getElementById(id);
	}

	/* ------------------------------------------------------------------ */
	/* signature markup                                                    */
	/* ------------------------------------------------------------------ */

	function buildSignature(d) {
		var accent = color(d.accent, '#AE0F64');
		var c2 = color(d.c2, '#F9E400');
		var c3 = color(d.c3, '#5DFF92');
		var bar = color(d.barColor, '#101010');
		var ink = '#101010';
		var face = 'Arial,Helvetica,sans-serif';

		var photoSrc = safeImg(d.photoUrl);
		var showPhoto = !!(d.photoOn && photoSrc);
		var cols = showPhoto ? 2 : 1;
		var out = [];

		out.push('<table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse:collapse;font-family:' + face + ';color:' + ink + ';width:' + WIDTH + 'px;">');
		out.push('<tbody><tr>');

		if (showPhoto) {
			out.push('<td width="112" style="vertical-align:top;padding-right:24px;">');
			out.push('<img src="' + esc(photoSrc) + '" width="104" height="104" alt="' + esc(d.name || 'Profile photo') + '" style="display:block;width:104px;height:104px;border-radius:50%;border:3px solid ' + accent + ';object-fit:cover;">');
			out.push('</td>');
		}

		out.push('<td style="vertical-align:top;">');

		if (d.name) {
			out.push('<div style="font-size:21px;line-height:21px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:' + ink + ';text-shadow:-1px -1px 0 ' + accent + ',1px 1px 0 ' + c3 + ';">' + esc(d.name) + '</div>');
		}

		var roleBits = [];
		if (d.role) {
			roleBits.push(esc(d.role));
		}
		if (d.company) {
			roleBits.push(esc(d.company));
		}
		if (roleBits.length) {
			out.push(gap(9));
			out.push('<div style="font-size:11px;line-height:11px;font-weight:700;letter-spacing:2.2px;text-transform:uppercase;color:' + accent + ';">' + roleBits.join('&nbsp; ·&nbsp; ') + '</div>');
		}

		var rows = [];
		if (d.email) {
			rows.push({ label: d.email, url: 'mailto:' + d.email });
		}
		if (d.phone) {
			rows.push({ label: d.phone, url: 'tel:' + String(d.phone).replace(/[^\d+]/g, '') });
		}
		(d.links || []).forEach(function (link) {
			if (link && (link.label || link.url)) {
				rows.push({ label: link.label || link.url, url: link.url || link.label });
			}
		});

		if (rows.length) {
			out.push(gap(14));
			out.push('<table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse:collapse;"><tbody>');
			rows.forEach(function (row) {
				var href = safeUrl(row.url);
				var inner = '<span style="color:' + ink + ';text-decoration:none;display:inline-block;">' + esc(row.label) + '</span>';
				var cell = href ? '<a href="' + esc(href) + '" style="color:' + ink + ';text-decoration:none;">' + inner + '</a>' : inner;
				out.push('<tr><td style="font-size:12px;line-height:19px;font-family:' + face + ';">' + cell + '</td></tr>');
			});
			out.push('</tbody></table>');
		}

		var bookHref = safeUrl(d.bookingUrl);
		if (d.bookingOn && bookHref && d.bookingLabel) {
			out.push(gap(16));
			out.push('<table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse:collapse;"><tbody><tr>');
			out.push('<td bgcolor="' + accent + '" style="background:' + accent + ';background-color:' + accent + ';border-radius:999px;padding:9px 17px;font-family:' + face + ';font-size:10px;line-height:10px;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;">');
			out.push('<a href="' + esc(bookHref) + '" style="color:#FFFFFF;text-decoration:none;"><span style="color:#FFFFFF;text-decoration:none;display:inline-block;">' + esc(d.bookingLabel) + '</span></a>');
			out.push('</td></tr></tbody></table>');
		}

		out.push('</td></tr>');

		if (d.stripesOn || d.barOn) {
			out.push('<tr><td colspan="' + cols + '" style="padding-top:20px;">');

			if (d.stripesOn) {
				var w1 = 174;
				var w2 = 169;
				var w3 = WIDTH - w1 - w2;
				out.push('<table cellpadding="0" cellspacing="0" border="0" role="presentation" width="' + WIDTH + '" style="border-collapse:collapse;width:' + WIDTH + 'px;table-layout:fixed;"><tbody><tr>');
				[[w1, accent], [w2, c2], [w3, c3]].forEach(function (seg) {
					out.push('<td width="' + seg[0] + '" height="4" bgcolor="' + seg[1] + '" style="background:' + seg[1] + ';background-color:' + seg[1] + ';font-size:0;line-height:0;height:4px;">&nbsp;</td>');
				});
				out.push('</tr></tbody></table>');
			}

			if (d.barOn && (d.company || d.website)) {
				var siteHref = safeUrl(d.website);
				var barInk = '#F6F4EE';
				out.push('<table cellpadding="0" cellspacing="0" border="0" role="presentation" width="' + WIDTH + '" style="border-collapse:collapse;width:' + WIDTH + 'px;"><tbody><tr>');
				out.push('<td bgcolor="' + bar + '" style="background:' + bar + ';background-color:' + bar + ';padding:14px 20px;font-family:' + face + ';">');
				var nameSpan = '<span style="color:' + barInk + ';text-decoration:none;display:inline-block;font-size:12px;font-weight:800;letter-spacing:2.6px;text-transform:uppercase;">' + esc(d.company || d.website) + '</span>';
				out.push(siteHref ? '<a href="' + esc(siteHref) + '" style="color:' + barInk + ';text-decoration:none;">' + nameSpan + '</a>' : nameSpan);
				if (d.website && d.company) {
					out.push('<span style="color:#9A9A9A;font-size:10px;letter-spacing:1.3px;text-transform:uppercase;">&nbsp;&nbsp;·&nbsp;&nbsp;' + esc(d.website) + '</span>');
				}
				out.push('</td></tr></tbody></table>');
			}

			out.push('</td></tr>');
		}

		out.push('</tbody></table>');
		return out.join('');
	}

	function plainText(d) {
		var head = [d.name, [d.role, d.company].filter(Boolean).join(', ')].filter(Boolean).join(' — ');
		var line = [d.email, d.phone]
			.concat((d.links || []).map(function (l) { return l.label || l.url; }))
			.filter(Boolean)
			.join(' · ');
		var book = (d.bookingOn && d.bookingUrl) ? 'Book a call: ' + d.bookingUrl : '';
		return [head, line, book].filter(Boolean).join('\n');
	}

	/* ------------------------------------------------------------------ */
	/* saving                                                              */
	/* ------------------------------------------------------------------ */

	function setStatus(message, isError) {
		var el = byId('kjsb-status');
		if (!el) {
			return;
		}
		el.textContent = message || '';
		el.className = 'kjsb-status' + (isError ? ' is-error' : '');
	}

	function queueSave() {
		if (!cfg.restUrl) {
			return;
		}
		window.clearTimeout(saveTimer);
		saveTimer = window.setTimeout(saveNow, 900);
	}

	function saveNow() {
		if (!cfg.restUrl || !window.fetch) {
			return;
		}
		fetch(cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: JSON.stringify(state)
		}).then(function (res) {
			if (!res.ok) {
				throw new Error('save failed');
			}
			setStatus(strings.saved || 'Saved', false);
		}).catch(function () {
			setStatus(strings.saveFailed || 'Could not save', true);
		});
	}

	/* ------------------------------------------------------------------ */
	/* rendering                                                           */
	/* ------------------------------------------------------------------ */

	function render() {
		currentHtml = buildSignature(state);
		byId('kjsb-out').innerHTML = currentHtml;
		byId('kjsb-code').textContent = currentHtml;

		var src = safeImg(state.photoUrl);
		var thumb = byId('kjsb-thumb');
		thumb.style.backgroundImage = src ? 'url("' + src.replace(/"/g, '\\"') + '")' : 'none';

		var clearBtn = byId('kjsb-clear-photo');
		if (clearBtn) {
			clearBtn.hidden = !src;
		}

		renderPhotoOrigin(src);
		queueSave();
	}

	function renderPhotoOrigin(src) {
		var note = byId('kjsb-photo-origin');
		if (!note) {
			return;
		}
		if (!src) {
			note.textContent = '';
			note.className = 'kjsb-note';
			return;
		}
		var sameHost = false;
		try {
			sameHost = new URL(src, window.location.href).host === window.location.host;
		} catch (e) {
			sameHost = false;
		}
		if (sameHost) {
			note.textContent = 'Hosted on ' + window.location.host + ' — recipients can load it.';
			note.className = 'kjsb-note is-good';
		} else {
			note.textContent = 'This image is hosted somewhere else. Upload it here instead if that host ever goes away.';
			note.className = 'kjsb-note is-warn';
		}
	}

	function syncInputs() {
		document.querySelectorAll('[data-key]').forEach(function (el) {
			var key = el.getAttribute('data-key');
			if (el.type === 'checkbox') {
				el.checked = !!state[key];
			} else {
				el.value = state[key] === null || state[key] === undefined ? '' : state[key];
			}
		});
		renderLinks();
	}

	function renderLinks() {
		var list = byId('kjsb-links');
		list.innerHTML = '';

		state.links.forEach(function (link, index) {
			var row = document.createElement('div');
			row.className = 'kjsb-linkrow';

			var labelField = document.createElement('p');
			labelField.className = 'kjsb-field';
			var labelLabel = document.createElement('label');
			labelLabel.setAttribute('for', 'kjsb-link-label-' + index);
			labelLabel.textContent = 'Label';
			var labelInput = document.createElement('input');
			labelInput.type = 'text';
			labelInput.id = 'kjsb-link-label-' + index;
			labelInput.value = link.label || '';
			labelInput.placeholder = '@kijacreative';
			labelInput.addEventListener('input', function () {
				state.links[index].label = labelInput.value;
				render();
			});
			labelField.appendChild(labelLabel);
			labelField.appendChild(labelInput);

			var urlField = document.createElement('p');
			urlField.className = 'kjsb-field kjsb-wide';
			var urlLabel = document.createElement('label');
			urlLabel.setAttribute('for', 'kjsb-link-url-' + index);
			urlLabel.textContent = 'URL';
			var urlInput = document.createElement('input');
			urlInput.type = 'text';
			urlInput.id = 'kjsb-link-url-' + index;
			urlInput.value = link.url || '';
			urlInput.placeholder = 'https://instagram.com/...';
			urlInput.addEventListener('input', function () {
				state.links[index].url = urlInput.value;
				render();
			});
			urlField.appendChild(urlLabel);
			urlField.appendChild(urlInput);

			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'button-link kjsb-remove';
			remove.textContent = 'Remove';
			remove.addEventListener('click', function () {
				state.links.splice(index, 1);
				renderLinks();
				render();
			});

			row.appendChild(labelField);
			row.appendChild(urlField);
			row.appendChild(remove);
			list.appendChild(row);
		});
	}

	/* ------------------------------------------------------------------ */
	/* media library                                                       */
	/* ------------------------------------------------------------------ */

	function pickPhoto() {
		if (!window.wp || !window.wp.media) {
			window.alert('The media library did not load. Reload the page and try again.');
			return;
		}

		if (!mediaFrame) {
			mediaFrame = window.wp.media({
				title: strings.frameTitle || 'Signature photo',
				library: { type: 'image' },
				multiple: false,
				button: { text: strings.frameButton || 'Use this photo' }
			});

			mediaFrame.on('select', function () {
				var selected = mediaFrame.state().get('selection').first();
				if (!selected) {
					return;
				}
				usePhoto(selected.toJSON());
			});
		}

		mediaFrame.open();
	}

	/**
	 * Apply a chosen attachment, cropped 1:1.
	 *
	 * Email clients ignore CSS cropping, so the signature always points at a
	 * real square file. If the attachment has no square size yet (anything
	 * uploaded before this plugin existed), the server makes one.
	 *
	 * @param {Object} attachment Attachment, as the media frame reports it.
	 */
	function usePhoto(attachment) {
		var sizes = attachment.sizes || {};
		var square = sizes['kija-sig-photo'];

		state.photoId = attachment.id || 0;
		state.photoOn = true;

		var toggle = byId('kjsb-photoOn');
		if (toggle) {
			toggle.checked = true;
		}

		if (square && square.url) {
			applyPhotoUrl(square.url);
			return;
		}

		if (!cfg.cropUrl || !window.fetch || !state.photoId) {
			applyPhotoUrl(squareFallback(attachment));
			return;
		}

		setStatus(strings.cropping || 'Cropping to a square…', false);

		fetch(cfg.cropUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: JSON.stringify({ id: state.photoId })
		}).then(function (res) {
			return res.ok ? res.json() : Promise.reject(new Error('crop failed'));
		}).then(function (data) {
			applyPhotoUrl(data && data.url ? data.url : squareFallback(attachment));
			setStatus('');
		}).catch(function () {
			applyPhotoUrl(squareFallback(attachment));
			setStatus(strings.cropFailed || 'Could not crop that image — using it as it is.', true);
		});
	}

	/**
	 * Best square-ish URL when the server could not crop.
	 *
	 * `thumbnail` is hard-cropped square in a stock WordPress install; the full
	 * image is the last resort and may not be square.
	 *
	 * @param {Object} attachment Attachment.
	 * @return {string} URL.
	 */
	function squareFallback(attachment) {
		var sizes = attachment.sizes || {};
		if (sizes.thumbnail && sizes.thumbnail.url && sizes.thumbnail.width === sizes.thumbnail.height) {
			return sizes.thumbnail.url;
		}
		return attachment.url || '';
	}

	/**
	 * Point the signature at a photo URL and refresh the form field.
	 *
	 * @param {string} url Image URL.
	 */
	function applyPhotoUrl(url) {
		state.photoUrl = url || '';
		var urlInput = byId('kjsb-photoUrl');
		if (urlInput) {
			urlInput.value = state.photoUrl;
		}
		render();
	}

	/* ------------------------------------------------------------------ */
	/* copying                                                             */
	/* ------------------------------------------------------------------ */

	function flash(button, message) {
		var original = button.getAttribute('data-label') || button.textContent;
		button.setAttribute('data-label', original);
		button.textContent = message;
		button.classList.add('kjsb-done');
		window.setTimeout(function () {
			button.textContent = original;
			button.classList.remove('kjsb-done');
		}, 1800);
	}

	function copyRich(button) {
		var html = currentHtml;
		var text = plainText(state);
		var ok = function () { flash(button, strings.copied || 'Copied'); };

		var fallback = function () {
			var range = document.createRange();
			range.selectNodeContents(byId('kjsb-out'));
			var selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange(range);
			var copied = false;
			try {
				copied = document.execCommand('copy');
			} catch (e) {
				copied = false;
			}
			selection.removeAllRanges();
			if (copied) {
				ok();
			} else {
				flash(button, strings.copyFailed || 'Use Copy HTML');
			}
		};

		if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write) {
			try {
				navigator.clipboard.write([new window.ClipboardItem({
					'text/html': new Blob([html], { type: 'text/html' }),
					'text/plain': new Blob([text], { type: 'text/plain' })
				})]).then(ok, fallback);
			} catch (e) {
				fallback();
			}
		} else {
			fallback();
		}
	}

	function copySource(button) {
		var ok = function () { flash(button, strings.copied || 'Copied'); };
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(currentHtml).then(ok, function () {
				flash(button, 'Select & copy');
			});
		} else {
			flash(button, 'Select & copy');
		}
	}

	/* ------------------------------------------------------------------ */
	/* wiring                                                              */
	/* ------------------------------------------------------------------ */

	function init() {
		if (!byId('kjsb-out')) {
			return;
		}

		document.querySelectorAll('[data-key]').forEach(function (el) {
			var key = el.getAttribute('data-key');
			var evt = el.type === 'checkbox' ? 'change' : 'input';
			el.addEventListener(evt, function () {
				state[key] = el.type === 'checkbox' ? el.checked : el.value;
				if (key === 'photoUrl') {
					state.photoId = 0;
				}
				render();
			});
		});

		byId('kjsb-add-link').addEventListener('click', function () {
			state.links.push({ label: '', url: '' });
			renderLinks();
			render();
		});

		byId('kjsb-pick').addEventListener('click', pickPhoto);

		byId('kjsb-clear-photo').addEventListener('click', function () {
			state.photoUrl = '';
			state.photoId = 0;
			byId('kjsb-photoUrl').value = '';
			render();
		});

		byId('kjsb-reset-colors').addEventListener('click', function () {
			var defaults = cfg.defaults || {};
			['accent', 'c2', 'c3', 'barColor'].forEach(function (key) {
				state[key] = defaults[key];
				var input = document.querySelector('[data-key="' + key + '"]');
				if (input) {
					input.value = defaults[key];
				}
			});
			render();
		});

		byId('kjsb-copy').addEventListener('click', function () { copyRich(this); });
		byId('kjsb-copy-html').addEventListener('click', function () { copySource(this); });

		syncInputs();
		render();
		setStatus('');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
