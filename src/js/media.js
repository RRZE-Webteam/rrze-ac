/* global jQuery, wpUploaderInit */

jQuery( document ).ready( function ( $ ) {
	'use strict';
	const input = $( 'input[name="access_protected"]' ),
		ctrl = document.getElementById( 'access_protected' ),
		ui = $( '#plupload-upload-ui' );

	ui.addClass( 'rrze-ac' );
	if ( ! input.length || ! ctrl || typeof wpUploaderInit === 'undefined' ) {
		return;
	}

	wpUploaderInit.multipart_params = wpUploaderInit.multipart_params || {};

	function state( check ) {
		return 'rrze-ac-upload-' + ( check === 'on' ? '' : 'un' ) + 'checked';
	}

	input.on( 'change', function () {
		const check = ctrl.checked ? 'on' : 'off';
		ui.removeClass( state( check === 'on' ? 'off' : 'on' ) ).addClass(
			state( check )
		);

		wpUploaderInit.multipart_params.access_protected = check;
	} );

	setTimeout( function () {
		input.change();
	}, 200 );
} );
