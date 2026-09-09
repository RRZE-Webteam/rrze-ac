const defaults = require("@wordpress/scripts/config/webpack.config");
const webpack = require("webpack");

/**
 * WP-Scripts Webpack config.
 *
 * @see https://developer.wordpress.org/block-editor/packages/packages-scripts/#provide-your-own-webpack-config
 */
module.exports = {
    ...defaults,
    entry: {
        "rrze-ac-admin": "./src/js/rrze-ac-admin.js",
        "rrze-ac": "./src/js/rrze-ac-frontend.js",
        blockeditor: "./src/blockeditor/index.js",
    },
    plugins: [
        ...defaults.plugins,
        new webpack.ProvidePlugin({
            $: "jquery",
            jQuery: "jquery",
        }),
    ],
};
