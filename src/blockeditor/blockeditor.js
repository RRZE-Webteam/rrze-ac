/* global acObject */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { SelectControl } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { dispatch, useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

function ACSettingPanel() {
	const postId = useSelect( function selectCurrentPostId( select ) {
		return select( 'core/editor' ).getCurrentPostId();
	}, [] );
	const postType = useSelect( function selectCurrentPostType( select ) {
		return select( 'core/editor' ).getCurrentPostType();
	}, [] );

	const postMeta = useSelect( function selectPostMeta( select ) {
		return select( 'core/editor' ).getEditedPostAttribute( 'meta' );
	}, [] );

	const permissions = acObject.permissions || {};
	const metaKey = acObject.metaKey || '';
	const canView = !! acObject.canView;
	const canEdit = !! acObject.canEdit;

	const storedPermission =
		postMeta && postMeta[ metaKey ] ? postMeta[ metaKey ] : '';

	const [ selectedPermission, setSelectedPermission ] = useState(
		storedPermission || acObject.permission || ''
	);

	useEffect(
		function syncStoredPermission() {
			setSelectedPermission(
				storedPermission || acObject.permission || ''
			);
		},
		[ storedPermission ]
	);

	const permissionsArray = Object.keys( permissions ).map(
		function mapPermission( key ) {
			return {
				value: key,
				label: permissions[ key ].select,
				active: permissions[ key ].active,
			};
		}
	);

	function permissionSelectOption( perm ) {
		return {
			label: perm.label,
			value: perm.value,
		};
	}

	function onPermissionChange( newPermission ) {
		if ( ! canEdit ) {
			return;
		}

		const isValid = permissionsArray.some(
			function permissionMatches( perm ) {
				return perm.value === newPermission;
			}
		);
		if ( ! isValid ) {
			return;
		}

		setSelectedPermission( newPermission );

		let restRouteBase;
		if ( postType === 'page' ) {
			restRouteBase = 'pages';
		} else if ( postType === 'post' ) {
			restRouteBase = 'posts';
		} else if ( postType === 'attachment' ) {
			restRouteBase = 'media';
		} else {
			restRouteBase = postType + 's';
		}

		apiFetch( {
			path: `/wp/v2/${ restRouteBase }/${ postId }`,
			method: 'POST',
			data: {
				meta: {
					[ metaKey ]: newPermission,
				},
			},
		} )
			.then( function permissionUpdated( updatedPost ) {
				const updatedMeta = updatedPost.meta || {};
				const updatedPermission = Object.prototype.hasOwnProperty.call(
					updatedMeta,
					metaKey
				)
					? updatedMeta[ metaKey ]
					: newPermission;

				dispatch( 'core/editor' ).editPost( {
					meta: {
						...( postMeta || {} ),
						[ metaKey ]: updatedPermission,
					},
				} );
				setSelectedPermission( updatedPermission );

				const notice = __(
					'Permission updated successfully.',
					'rrze-ac'
				);
				dispatch( 'core/notices' ).createInfoNotice( notice, {
					isDismissible: true,
					type: 'snackbar',
					speak: true,
				} );
			} )
			.catch( function permissionUpdateFailed() {
				setSelectedPermission(
					storedPermission || acObject.permission || ''
				);

				dispatch( 'core/notices' ).createErrorNotice(
					__( 'Permission could not be updated.', 'rrze-ac' ),
					{
						isDismissible: true,
						type: 'snackbar',
						speak: true,
					}
				);
			} );
	}

	if ( ! canView ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="rrze-ac-setting-panel"
			title={ __( 'Access Restriction', 'rrze-ac' ) }
			className="rrze-ac rrze-ac-setting-panel"
		>
			<SelectControl
				label={ __( 'Permission', 'rrze-ac' ) }
				value={ selectedPermission }
				options={ permissionsArray.map( permissionSelectOption ) }
				onChange={ onPermissionChange }
				disabled={ ! canEdit }
				help={ ! canEdit ? acObject.lockedMessage : undefined }
			/>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'rrze-ac-setting-panel', {
	render: ACSettingPanel,
} );
