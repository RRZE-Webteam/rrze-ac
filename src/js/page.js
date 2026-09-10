/* global jQuery */

function rrzeAcHandlePostProtectionClick( event ) {
	const $ = jQuery;
	const previous = $( '#access-permission-select' ).data(
		'rrze-ac-previous-value'
	);

	event.preventDefault();

	if ( $( this ).hasClass( 'cancel-post-protection' ) ) {
		$( '#access-permission-select' ).val( previous );
	} else if ( $( this ).hasClass( 'save-post-protection' ) ) {
		const selectedValue = $( '#access-permission-select' ).val();
		$( '#access-permission-select' ).data(
			'rrze-ac-previous-value',
			selectedValue
		);
		$( '#post-protection-label' ).text(
			$(
				'#access-permission-select option[value=' + selectedValue + ']'
			).text()
		);
		if ( selectedValue === 'all' ) {
			$( '#access-icon' )
				.removeClass( 'access-icon' )
				.addClass( 'access-all-icon' );
		} else {
			$( '#access-icon' )
				.removeClass( 'access-all-icon' )
				.addClass( 'access-icon' );
		}
	}

	$( '#post-protection-field' ).slideToggle( 'fast' );
}

function rrzeAcInitPostProtection() {
	const $ = jQuery;
	const accessPermissionSelect = $( '#access-permission-select' );

	accessPermissionSelect.data(
		'rrze-ac-previous-value',
		accessPermissionSelect.val()
	);

	$(
		'.edit-post-protection, .save-post-protection, .cancel-post-protection'
	).on( 'click', rrzeAcHandlePostProtectionClick );
}

function rrzeAcToggleMessageTextFields( toggle ) {
	const $ = jQuery;
	const group = $( toggle ).data( 'rrze-ac-text-group' );
	const fields = $(
		'.rrze-ac-message-text[data-rrze-ac-text-group="' + group + '"]'
	);
	const useDefaults = $( toggle ).is( ':checked' );

	fields.toggleClass( 'is-readonly', useDefaults );
	fields.find( 'input, textarea' ).prop( 'readonly', useDefaults );
}

function rrzeAcInitMessageTextToggles() {
	const $ = jQuery;
	const toggles = $( '.rrze-ac-default-text-toggle' );

	toggles.each( function initializeMessageTextToggle() {
		rrzeAcToggleMessageTextFields( this );
	} );
	toggles.on( 'change', function changeMessageTextToggle() {
		rrzeAcToggleMessageTextFields( this );
	} );
}

function rrzeAcInitPage() {
	rrzeAcInitPostProtection();
	rrzeAcInitMessageTextToggles();
}

jQuery( document ).ready( rrzeAcInitPage );
