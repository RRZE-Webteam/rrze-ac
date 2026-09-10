const defaults = require("@wordpress/scripts/config/webpack.config");
const path = require("path");
const webpack = require("webpack");
const packageConfig = require("./package.json");

/**
 * WP-Scripts Webpack config.
 *
 * @see https://developer.wordpress.org/block-editor/packages/packages-scripts/#provide-your-own-webpack-config
 */
module.exports = {
    ...defaults,
    entry: {
        "rrze-ac-admin": path.resolve(
            __dirname,
            packageConfig.source.js,
            "rrze-ac-admin.js"
        ),
        blockeditor: "./src/blockeditor/index.js",
    },
    output: {
        ...defaults.output,
        path: path.resolve(__dirname, packageConfig.target.js),
    },
    plugins: [
        ...defaults.plugins,
        new webpack.ProvidePlugin({
            $: "jquery",
            jQuery: "jquery",
        }),
    ],
};
