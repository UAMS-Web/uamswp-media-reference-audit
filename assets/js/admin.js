/* global UAMSMRA */
( function () {
	'use strict';

	var button = document.getElementById( 'uams-mra-scan' );
	var panel = document.getElementById( 'uams-mra-progress' );
	var text = document.getElementById( 'uams-mra-progress-text' );
	var bar = panel ? panel.querySelector( '.uams-mra-bar span' ) : null;
	var form = document.getElementById( 'uams-mra-results-form' );

	function post( action ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', UAMSMRA.nonce );

		return fetch( UAMSMRA.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	// Steps are uneven in length, so the bar reports position in the pipeline
	// rather than a false percentage of rows.
	var pipeline = [ 'index', 'posts', 'postmeta', 'options', 'termmeta', 'usermeta', 'commentmeta', 'network', 'finalize', 'done' ];

	function paint( data ) {
		var position = pipeline.indexOf( data.step );
		var percent = position < 0 ? 5 : Math.round( ( ( position + 1 ) / pipeline.length ) * 100 );

		if ( bar ) {
			bar.style.width = percent + '%';
		}

		if ( text ) {
			text.textContent = data.done
				? data.message
				: data.message + ' \u00b7 ' + Number( data.processed ).toLocaleString() + ' rows read';
		}
	}

	function fail() {
		if ( text ) {
			text.textContent = UAMSMRA.i18n.failed;
		}
		if ( button ) {
			button.disabled = false;
		}
	}

	function loop() {
		post( 'uams_mra_scan_step' ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				fail();
				return;
			}

			paint( response.data );

			if ( response.data.done ) {
				window.location.reload();
				return;
			}

			loop();
		} ).catch( fail );
	}

	if ( button ) {
		button.addEventListener( 'click', function () {
			if ( ! window.confirm( UAMSMRA.i18n.confirmScan ) ) {
				return;
			}

			button.disabled = true;
			if ( panel ) {
				panel.hidden = false;
			}

			post( 'uams_mra_scan_start' ).then( function ( response ) {
				if ( ! response || ! response.success ) {
					fail();
					return;
				}
				paint( response.data );
				loop();
			} ).catch( fail );
		} );
	}

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var action = form.querySelector( 'select[name="action"]' );
			var action2 = form.querySelector( 'select[name="action2"]' );
			var chosen = ( action && action.value !== '-1' ) ? action.value : ( action2 ? action2.value : '-1' );

			if ( chosen !== 'uams_mra_delete' ) {
				return;
			}

			if ( ! form.querySelector( 'input[name="ids[]"]:checked' ) ) {
				event.preventDefault();
				window.alert( UAMSMRA.i18n.noSelection );
				return;
			}

			if ( ! window.confirm( UAMSMRA.i18n.confirmDelete ) ) {
				event.preventDefault();
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Triage: a decision and a note per file, saved as they are made.
	 * ---------------------------------------------------------------- */

	var noteTimers = {};

	function triageSave( wrap, disposition, note, done ) {
		var body = new FormData();
		body.append( 'action', 'uams_mra_triage' );
		body.append( 'nonce', UAMSMRA.triageNonce );
		body.append( 'id', wrap.getAttribute( 'data-id' ) );
		body.append( 'd', disposition );
		body.append( 'note', note );

		var state = wrap.querySelector( '.uams-mra-tri-state' );

		return fetch( UAMSMRA.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( 'save failed' );
			}

			if ( state ) {
				state.textContent = UAMSMRA.i18n.saved;
				state.setAttribute( 'data-ok', '1' );
				window.setTimeout( function () { state.textContent = ''; }, 1600 );
			}

			// Keep the standing delete button honest about how many files it covers.
			var marked = document.getElementById( 'uams-mra-delete-marked' );
			if ( marked && json.data && json.data.deleteLabel ) {
				if ( json.data.counts.disp.delete > 0 ) {
					marked.textContent = json.data.deleteLabel;
					marked.closest( '.uams-mra-marked' ).hidden = false;
				} else {
					marked.closest( '.uams-mra-marked' ).hidden = true;
				}
			}

			if ( done ) { done( json.data ); }
		} ).catch( function () {
			if ( state ) {
				state.textContent = UAMSMRA.i18n.saveFailed;
				state.setAttribute( 'data-ok', '0' );
			}
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.uams-mra-tri-btn' );
		if ( ! button ) { return; }

		var wrap = button.closest( '.uams-mra-tri' );
		var note = wrap.querySelector( '.uams-mra-tri-note' );
		var wanted = button.getAttribute( 'data-d' );
		var already = 'true' === button.getAttribute( 'aria-pressed' );
		var next = already ? '' : wanted;

		wrap.querySelectorAll( '.uams-mra-tri-btn' ).forEach( function ( other ) {
			other.setAttribute( 'aria-pressed', other.getAttribute( 'data-d' ) === next ? 'true' : 'false' );
		} );

		wrap.setAttribute( 'data-d', next );

		if ( note ) {
			if ( next || note.value ) { note.hidden = false; } else { note.hidden = true; }
		}

		triageSave( wrap, next, note ? note.value : '' );
	} );

	document.addEventListener( 'input', function ( event ) {
		var note = event.target.closest( '.uams-mra-tri-note' );
		if ( ! note ) { return; }

		var wrap = note.closest( '.uams-mra-tri' );
		var id = wrap.getAttribute( 'data-id' );

		// One save when typing stops, not one per keystroke.
		window.clearTimeout( noteTimers[ id ] );
		noteTimers[ id ] = window.setTimeout( function () {
			var pressed = wrap.querySelector( '.uams-mra-tri-btn[aria-pressed="true"]' );
			triageSave( wrap, pressed ? pressed.getAttribute( 'data-d' ) : '', note.value );
		}, 700 );
	} );

	var deleteMarked = document.getElementById( 'uams-mra-delete-marked' );
	if ( deleteMarked ) {
		deleteMarked.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( UAMSMRA.i18n.confirmMarked ) ) {
				event.preventDefault();
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Archive: batched zip build on the review screen.
	 * ---------------------------------------------------------------- */

	var archiveButton = document.getElementById( 'uams-mra-archive' );

	if ( archiveButton ) {
		var archivePanel = document.getElementById( 'uams-mra-archive-progress' );
		var archiveText = document.getElementById( 'uams-mra-archive-text' );
		var archiveParts = document.getElementById( 'uams-mra-archive-parts' );
		var archiveBar = archivePanel ? archivePanel.querySelector( '.uams-mra-bar span' ) : null;

		var archivePost = function ( action ) {
			var body = new FormData();
			body.append( 'action', action );
			body.append( 'nonce', UAMSMRA.archiveNonce );

			return fetch( UAMSMRA.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( response ) {
				return response.json();
			} );
		};

		var archivePaint = function ( data ) {
			if ( archiveBar ) { archiveBar.style.width = data.percent + '%'; }
			if ( archiveText ) {
				archiveText.textContent = data.done
					? UAMSMRA.i18n.archiveDone + ' ' + data.progress
					: UAMSMRA.i18n.archiving + ' ' + data.progress;
			}

			if ( ! archiveParts ) { return; }

			archiveParts.innerHTML = '';
			data.parts.forEach( function ( part ) {
				var link = document.createElement( 'a' );
				link.className = 'button';
				link.href = part.url;
				link.textContent = 'Download part ' + part.part + ' (' + part.files + ' files, ' + part.size + ')';
				archiveParts.appendChild( link );
			} );
		};

		var archiveFail = function ( message ) {
			if ( archiveText ) {
				archiveText.textContent = message || UAMSMRA.i18n.archiveFailed;
			}
			archiveButton.disabled = false;
		};

		var archiveLoop = function () {
			archivePost( 'uams_mra_archive_step' ).then( function ( response ) {
				if ( ! response || ! response.success ) {
					archiveFail( response && response.data ? response.data.message : '' );
					return;
				}

				archivePaint( response.data );

				if ( response.data.done ) {
					archiveButton.disabled = false;
					return;
				}

				archiveLoop();
			} ).catch( function () { archiveFail(); } );
		};

		archiveButton.addEventListener( 'click', function () {
			archiveButton.disabled = true;
			if ( archivePanel ) { archivePanel.hidden = false; }
			if ( archiveParts ) { archiveParts.innerHTML = ''; }

			archivePost( 'uams_mra_archive_start' ).then( function ( response ) {
				if ( ! response || ! response.success ) {
					archiveFail( response && response.data ? response.data.message : '' );
					return;
				}
				archivePaint( response.data );
				archiveLoop();
			} ).catch( function () { archiveFail(); } );
		} );
	}

	var deleteSelection = document.getElementById( 'uams-mra-delete-selection' );
	if ( deleteSelection ) {
		deleteSelection.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( UAMSMRA.i18n.confirmDelete ) ) {
				event.preventDefault();
			}
		} );
	}

}() );
