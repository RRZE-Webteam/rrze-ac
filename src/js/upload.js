/* global jQuery */

jQuery( document ).ready( function ( $ ) {
	const vals = {};
	let postId, permissionsField;

	$( 'body' )
		.on(
			'accessLoaded',
			'#access-attachment-fields',
			function ( event, id ) {
				postId = id;
				permissionsField = $( '#access-attachment-permissions-field' );

				if ( Object.prototype.hasOwnProperty.call( vals, postId ) ) {
					$( this )
						.find(
							'.access-protection-toggle, .access-permission-select'
						)
						.each( function () {
							if (
								! Object.prototype.hasOwnProperty.call(
									vals[ postId ],
									this.name
								)
							) {
								return;
							}

							if ( 'checkbox' === this.type ) {
								this.checked = vals[ postId ][ this.name ];
							} else {
								this.value = vals[ postId ][ this.name ];
							}
						} );
				}

				$( this )
					.find( '.access-protection-toggle' )
					.trigger( 'change', 'accessJustLoaded' );
			}
		)

		.on(
			'change',
			'.access-protection-toggle',
			function ( event, justLoaded ) {
				const duration = 'accessJustLoaded' !== justLoaded ? 400 : 0;

				if ( this.checked ) {
					permissionsField.slideDown( duration );
				} else {
					permissionsField.slideUp( duration );
				}
			}
		)

		.on(
			'change',
			'.access-protection-toggle, .access-permission-select',
			function ( event, justLoaded ) {
				if ( 'accessJustLoaded' === justLoaded ) {
					return;
				}

				if ( ! Object.prototype.hasOwnProperty.call( vals, postId ) ) {
					vals[ postId ] = {};
				}

				vals[ postId ][ this.name ] =
					'checkbox' === this.type ? this.checked : this.value;
			}
		);
} );
