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
        access: "./src/access/index.js",
        attachment: "./src/attachment/index.js",
        blockeditor: "./src/blockeditor/index.js",
        media: "./src/media/index.js",
        page: "./src/page/index.js",
        upload: "./src/upload/index.js",
    },
    plugins: [
        ...defaults.plugins,
        new webpack.ProvidePlugin({
            $: "jquery",
            jQuery: "jquery",
        }),
    ],
};
