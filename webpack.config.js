const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const webpack = require("webpack");
const { basename, dirname, resolve } = require("path");
const srcDir = "src";

const access = resolve(process.cwd(), "src", "access");
const page = resolve(process.cwd(), "src", "page");
const attachment = resolve(process.cwd(), "src", "attachment");
const upload = resolve(process.cwd(), "src", "upload");
const media = resolve(process.cwd(), "src", "media");
const blockeditor = resolve(process.cwd(), "src", "blockeditor");

module.exports = {
    ...defaultConfig,
    entry: {
        access,
        page,
        attachment,
        upload,
        media,
        blockeditor,
    },
    output: {
        path: resolve(process.cwd(), "build"),
        filename: "[name].js",
        clean: true,
    },
    optimization: {
        ...defaultConfig.optimization,
        splitChunks: {
            cacheGroups: {
                style: {
                    type: "css/mini-extract",
                    test: /[\\/]style(\.module)?\.(pc|sc|sa|c)ss$/,
                    chunks: "all",
                    enforce: true,
                    name(_, chunks, cacheGroupKey) {
                        const chunkName = chunks[0].name;
                        return `${dirname(chunkName)}/${basename(
                            chunkName
                        )}.${cacheGroupKey}`;
                    },
                },
                default: false,
            },
        },
    },
    plugins: [
        ...defaultConfig.plugins,
        new webpack.ProvidePlugin({
            $: "jquery",
            jQuery: "jquery",
        }),
    ],
};
