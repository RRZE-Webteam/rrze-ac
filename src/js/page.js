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

function rrzeAcInitPage() {
	rrzeAcInitPostProtection();
}

jQuery( document ).ready( rrzeAcInitPage );
