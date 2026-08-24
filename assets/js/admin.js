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
}() );
