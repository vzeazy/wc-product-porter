(function ($) {
	'use strict';

	const settings = typeof wcppImporter === 'undefined' ? {} : wcppImporter;
	const strings = settings.strings || {};

	const importer = {
		state: {
			importId: null,
			total: 0,
			batch: 1,
			batchSize: 0,
			running: false,
			cancelled: false,
		},

		selectors: {
			form: '#wcpp-import-form',
			file: '#wcpp-import-file',
			update: '#wcpp-import-update',
			start: '#wcpp-import-start',
			cancel: '#wcpp-import-cancel',
			progress: '#wcpp-import-progress',
			progressBar: '#wcpp-import-progress .wcpp-progress-bar',
			progressText: '#wcpp-import-progress .wcpp-progress-text',
			log: '#wcpp-import-log',
		},

		init() {
			if (!settings.ajaxUrl || !settings.nonce) {
				return;
			}

			$(importer.selectors.file).on('change', importer.handleFileChange);
			$(importer.selectors.form).on('submit', importer.handleFormSubmit);
			$(importer.selectors.cancel).on('click', importer.handleCancelClick);
		},

		handleFileChange() {
			const hasFile = $(importer.selectors.file)[0].files.length > 0;
			$(importer.selectors.start).prop('disabled', !hasFile);
		},

		handleFormSubmit(event) {
			event.preventDefault();

			if (importer.state.running) {
				return;
			}

			const fileInput = $(importer.selectors.file)[0];

			if (!fileInput.files.length) {
				importer.logMessage(strings.missingFile || 'Please choose a package before starting the import.', 'error');
				return;
			}

			importer.resetLog();
			importer.setRunning(true);
			importer.updateProgress(0, 1);
			importer.logMessage(strings.uploading || 'Uploading package…', 'info');

			importer.uploadPackage(fileInput.files[0])
				.then((response) => {
					importer.state.importId = response.import_id;
					importer.state.total = response.total_products;
					importer.state.batchSize = response.batch_size;
					importer.state.batch = 1;
					importer.state.cancelled = false;
					importer.logMessage(strings.processing || 'Processing batch', 'info');
					return importer.processNextBatch();
				})
				.then(() => {
					if (!importer.state.cancelled) {
						importer.logMessage(strings.completed || 'Import complete.', 'success');
					}
				})
				.fail((jqXHR) => {
					const message = importer.extractError(jqXHR) || (strings.error || 'An error occurred during import.');
					importer.logMessage(message, 'error');
				})
				.always(() => {
					importer.cleanupSession();
				});
		},

		handleCancelClick(event) {
			event.preventDefault();

			if (!importer.state.running) {
				return;
			}

			if (window.confirm(strings.confirmCancel || 'Cancel the current import?')) {
				importer.state.cancelled = true;
				importer.logMessage(strings.cancelled || 'Import cancelled by user.', 'warning');
				importer.cleanupSession();
			}
		},

		uploadPackage(file) {
			const formData = new window.FormData();
			formData.append('action', 'wcpp_import_setup');
			formData.append('nonce', settings.nonce);
			formData.append('import_file', file);

			if ($(importer.selectors.update).is(':checked')) {
				formData.append('update_existing', '1');
			}

			return $.ajax({
				url: settings.ajaxUrl,
				type: 'POST',
				data: formData,
				contentType: false,
				processData: false,
				dataType: 'json',
			}).then(importer.handleAjaxSuccess);
		},

		processNextBatch() {
			if (importer.state.cancelled) {
				return $.Deferred().resolve().promise();
			}

			return $.ajax({
				url: settings.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'wcpp_process_batch',
					nonce: settings.nonce,
					import_id: importer.state.importId,
					batch: importer.state.batch,
				},
			}).then(importer.handleAjaxSuccess).then((response) => {
				if (!response) {
					return;
				}

				if (response.logs && response.logs.length) {
					response.logs.forEach((log) => importer.logMessage(log, 'log'));
				}

				importer.updateProgress(response.processed_total, response.total);

				if (!response.completed && !importer.state.cancelled) {
					importer.state.batch += 1;
					return importer.processNextBatch();
				}

				return null;
			});
		},

		handleAjaxSuccess(response) {
			if (!response) {
				return $.Deferred().reject().promise();
			}

			if (!response.success) {
				const message = response.data && response.data.message ? response.data.message : (strings.error || 'An error occurred during import.');
				return $.Deferred().reject({ responseJSON: { data: { message } } }).promise();
			}

			return response.data;
		},

		cleanupSession() {
			if (importer.state.importId) {
				$.post(settings.ajaxUrl, {
					action: 'wcpp_import_cleanup',
					nonce: settings.nonce,
					import_id: importer.state.importId,
				});
			}

			importer.setRunning(false);
			importer.state.importId = null;
			importer.state.total = 0;
			importer.state.batch = 1;
			importer.state.batchSize = 0;
		},

		setRunning(running) {
			importer.state.running = running;
			$(importer.selectors.start).prop('disabled', running);
			$(importer.selectors.file).prop('disabled', running);
			$(importer.selectors.cancel).toggle(running);
			$(importer.selectors.progress).toggle(running).attr('aria-hidden', !running);
		},

		updateProgress(processed, total) {
			total = total || 1;
			const percent = Math.min(100, Math.round((processed / total) * 100));
			$(importer.selectors.progressBar).css('width', percent + '%');
			$(importer.selectors.progressText).text(percent + '%');
		},

		logMessage(message, type) {
			const $log = $(importer.selectors.log);
			$log.show();

			const classes = ['wcpp-log-line'];

			switch (type) {
				case 'error':
					classes.push('error');
					break;
				case 'success':
					classes.push('success');
					break;
				case 'warning':
					classes.push('warning');
					break;
				default:
					classes.push('info');
			}

			const line = $('<p/>', { class: classes.join(' '), text: message });
			$log.append(line);
			$log.scrollTop($log[0].scrollHeight);
		},

		resetLog() {
			$(importer.selectors.log).empty().hide();
		},

		extractError(jqXHR) {
			if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
				return jqXHR.responseJSON.data.message;
			}

			return jqXHR && jqXHR.statusText ? jqXHR.statusText : '';
		},
	};

	$(importer.init);
})(jQuery);
