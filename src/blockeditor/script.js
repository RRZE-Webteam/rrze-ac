import { registerPlugin } from "@wordpress/plugins";
import { PluginDocumentSettingPanel } from "@wordpress/edit-post";
import { SelectControl } from "@wordpress/components";
import { useState } from "@wordpress/element";
import { useSelect, useDispatch } from "@wordpress/data";
import { __ } from "@wordpress/i18n";

const ACSettingPanel = () => {
    const { editPost } = useDispatch("core/editor");
    const postMeta = useSelect(
        (select) => select("core/editor").getEditedPostAttribute("meta"),
        []
    );
    const permissions = acObject.permissions || {};
    const permission = acObject.permission || "";
    const metaKey = acObject.metaKey || "";

    const [selectedPermission, setSelectedPermission] = useState(permission);

    let permissionsArray = Object.keys(acObject.permissions).map((key) => ({
        value: key,
        label: permissions[key].select,
        active: permissions[key].active,
    }));

    const onPermissionChange = (newPermission) => {
        // Check if newPermission is a key in permissionsArray
        const isValidPermission = permissionsArray.some(
            (perm) => perm.value === newPermission
        );
        if (!isValidPermission) {
            return;
        }
        // Update the status with the new selected permission
        setSelectedPermission(newPermission);
        // Update the meta with the new permission
        editPost({ meta: { ...postMeta, [metaKey]: newPermission } });
    };

    return (
        <PluginDocumentSettingPanel
            name="rrze-ac-setting-panel"
            title={__("Access Restriction", "rrze-ac")}
            className="rrze-ac-setting-panel"
        >
            <SelectControl
                label={__("Permission", "rrze-ac")}
                value={selectedPermission}
                options={permissionsArray.map((perm) => ({
                    label: perm.label,
                    value: perm.value,
                }))}
                onChange={onPermissionChange}
            />
        </PluginDocumentSettingPanel>
    );
};

registerPlugin("rrze-ac-setting-panel", {
    render: ACSettingPanel,
});
