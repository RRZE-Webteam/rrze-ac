import { registerPlugin } from "@wordpress/plugins";
import { PluginDocumentSettingPanel } from "@wordpress/editor";
import { SelectControl } from "@wordpress/components";
import { useState, useEffect } from "@wordpress/element";
import { dispatch, useSelect } from "@wordpress/data";
import apiFetch from "@wordpress/api-fetch";
import { __ } from "@wordpress/i18n";

const ACSettingPanel = () => {
    const postId = useSelect(
        (select) => select("core/editor").getCurrentPostId(),
        []
    );
    const postType = useSelect(
        (select) => select("core/editor").getCurrentPostType(),
        []
    );

    const postMeta = useSelect(
        (select) => select("core/editor").getEditedPostAttribute("meta"),
        []
    );

    const permissions = acObject.permissions || {};
    const metaKey = acObject.metaKey || "";

    const storedPermission = postMeta[metaKey] || "";

    const [selectedPermission, setSelectedPermission] = useState(
        storedPermission || acObject.permission || ""
    );

    useEffect(() => {
        if (
            storedPermission !== "" &&
            storedPermission !== selectedPermission
        ) {
            setSelectedPermission(storedPermission);
        }
    }, [storedPermission]);

    const permissionsArray = Object.keys(permissions).map((key) => ({
        value: key,
        label: permissions[key].select,
        active: permissions[key].active,
    }));

    const onPermissionChange = (newPermission) => {
        const isValid = permissionsArray.some(
            (perm) => perm.value === newPermission
        );
        if (!isValid) {
            return;
        }

        setSelectedPermission(newPermission);

        let restRouteBase;
        if (postType === "page") {
            restRouteBase = "pages";
        } else if (postType === "post") {
            restRouteBase = "posts";
        } else if (postType === "attachment") {
            restRouteBase = "media";
        } else {
            restRouteBase = postType + "s";
        }

        apiFetch({
            path: `/wp/v2/${restRouteBase}/${postId}`,
            method: "POST",
            data: {
                meta: {
                    [metaKey]: newPermission,
                },
            },
        })
            .then((response) => {
                let notice;
                notice = __("Permission updated successfully.", "rrze-ac");
                dispatch("core/notices").createInfoNotice(notice, {
                    isDismissible: true,
                    type: "snackbar",
                    speak: true,
                });
            })
            .catch((error) => {
                console.error("Error saving permission:", error);
            });
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
