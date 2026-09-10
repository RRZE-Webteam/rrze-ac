#!/usr/bin/env node

'use strict';

var fs = require('fs');
var path = require('path');
var sass = require('sass');
var webpack = require('webpack');
var webpackConfig = require('../webpack.config');
var packageConfig = require('../package.json');

function getConfiguredDirectory(group, key) {
    var value = packageConfig[group] && packageConfig[group][key];

    if (typeof value !== 'string' || value.trim() === '') {
        throw new Error('Missing package.json path: ' + group + '.' + key);
    }

    return path.resolve(__dirname, '..', value);
}

var sourceSassDirectory = getConfiguredDirectory('source', 'sass');
var targetJsDirectory = getConfiguredDirectory('target', 'js');
var targetCssDirectory = getConfiguredDirectory('target', 'css');

var sassEntries = [
    {
        source: path.join(sourceSassDirectory, 'rrze-ac.scss'),
        output: path.join(targetCssDirectory, 'rrze-ac.css')
    },
    {
        source: path.join(sourceSassDirectory, 'rrze-ac-admin.scss'),
        output: path.join(targetCssDirectory, 'rrze-ac-admin.css')
    }
];

var legacyStyles = [
    path.join(targetCssDirectory, 'rrze-ac-rtl.css'),
    path.join(targetCssDirectory, 'rrze-ac-rtl.css.map'),
    path.join(targetCssDirectory, 'rrze-ac-admin-rtl.css'),
    path.join(targetCssDirectory, 'rrze-ac-admin-rtl.css.map')
];

var legacyScripts = [
    path.join(targetJsDirectory, 'rrze-ac.js'),
    path.join(targetJsDirectory, 'rrze-ac.js.map'),
    path.join(targetJsDirectory, 'rrze-ac.asset.php')
];

function ensureDir(directory) {
    if (!fs.existsSync(directory)) {
        fs.mkdirSync(directory, { recursive: true });
    }
}

function parseArgs(argv) {
    var mode = 'dev';
    var watch = false;
    var i;

    for (i = 2; i < argv.length; i++) {
        if (argv[i] === 'dev' || argv[i] === 'prod') {
            mode = argv[i];
        } else if (argv[i] === '--watch') {
            watch = true;
        }
    }

    return {
        mode: mode,
        watch: watch
    };
}

function getWebpackConfig(mode) {
    var config = Object.assign({}, webpackConfig);
    config.mode = mode === 'prod' ? 'production' : 'development';

    if (mode === 'prod') {
        config.devtool = false;
    }

    return config;
}

function removeSourceMapFiles(directory) {
    var entries;
    var removed = 0;
    var i;
    var filePath;

    if (!fs.existsSync(directory)) {
        return removed;
    }

    entries = fs.readdirSync(directory, { withFileTypes: true });
    for (i = 0; i < entries.length; i++) {
        filePath = path.join(directory, entries[i].name);

        if (entries[i].isDirectory()) {
            removed += removeSourceMapFiles(filePath);
        } else if (entries[i].isFile() && path.extname(entries[i].name) === '.map') {
            fs.unlinkSync(filePath);
            removed++;
        }
    }

    return removed;
}

function removeLegacyStyles() {
    var i;

    for (i = 0; i < legacyStyles.length; i++) {
        if (fs.existsSync(legacyStyles[i])) {
            fs.unlinkSync(legacyStyles[i]);
        }
    }
}

function removeLegacyScripts() {
    var i;

    for (i = 0; i < legacyScripts.length; i++) {
        if (fs.existsSync(legacyScripts[i])) {
            fs.unlinkSync(legacyScripts[i]);
        }
    }
}

function buildStyles(mode) {
    var isProduction = mode === 'prod';
    var i;
    var entry;
    var result;
    var css;
    var sourceMapFile;

    for (i = 0; i < sassEntries.length; i++) {
        entry = sassEntries[i];
        ensureDir(path.dirname(entry.output));
        result = sass.compile(entry.source, {
            style: isProduction ? 'compressed' : 'expanded',
            sourceMap: !isProduction,
            sourceMapIncludeSources: !isProduction
        });
        css = result.css;
        sourceMapFile = entry.output + '.map';

        if (isProduction) {
            if (fs.existsSync(sourceMapFile)) {
                fs.unlinkSync(sourceMapFile);
            }
        } else if (result.sourceMap) {
            css += '\n/*# sourceMappingURL=' + path.basename(sourceMapFile) + ' */\n';
            fs.writeFileSync(sourceMapFile, JSON.stringify(result.sourceMap));
        }

        fs.writeFileSync(entry.output, css);
    }

    removeLegacyStyles();
}

function printStats(stats) {
    console.log(stats.toString({
        colors: true,
        preset: 'minimal'
    }));
}

function runBuild(config) {
    return new Promise(function runBuildPromise(resolve, reject) {
        var compiler = webpack(config);

        compiler.run(function onBuild(error, stats) {
            if (error) {
                reject(error);
                return;
            }

            if (stats.hasErrors()) {
                printStats(stats);
                reject(new Error('Webpack build failed.'));
                return;
            }

            printStats(stats);
            compiler.close(function onClose(closeError) {
                if (closeError) {
                    reject(closeError);
                    return;
                }

                resolve();
            });
        });
    });
}

function cleanProductionSourceMaps() {
    var removed = removeSourceMapFiles(targetJsDirectory);

    if (targetCssDirectory !== targetJsDirectory) {
        removed += removeSourceMapFiles(targetCssDirectory);
    }

    if (removed > 0) {
        console.log('Removed ' + removed + ' production source map file(s).');
    }
}

function watchSassDirectory(directory, callback) {
    var entries;
    var i;
    var entryPath;

    if (!fs.existsSync(directory)) {
        return;
    }

    fs.watch(directory, callback);
    entries = fs.readdirSync(directory, { withFileTypes: true });

    for (i = 0; i < entries.length; i++) {
        if (!entries[i].isDirectory()) {
            continue;
        }

        entryPath = path.join(directory, entries[i].name);
        watchSassDirectory(entryPath, callback);
    }
}

function watchStyles() {
    var timeout;

    function rebuildStyles() {
        clearTimeout(timeout);
        timeout = setTimeout(function buildUpdatedStyles() {
            try {
                buildStyles('dev');
                console.log('Sass assets compiled.');
            } catch (error) {
                console.error(error);
            }
        }, 100);
    }

    watchSassDirectory(sourceSassDirectory, rebuildStyles);
}

function runWatch(config) {
    var compiler = webpack(config);

    buildStyles('dev');
    watchStyles();

    compiler.watch({}, function onBuild(error, stats) {
        if (error) {
            console.error(error);
            return;
        }

        printStats(stats);
    });

    console.log('Webpack and Sass watch active.');
}

function main() {
    var args = parseArgs(process.argv);
    var config = getWebpackConfig(args.mode);

    if (args.mode === 'prod') {
        cleanProductionSourceMaps();
    }

    if (args.watch) {
        removeLegacyScripts();
        runWatch(config);
        return;
    }

    runBuild(config)
        .then(function onBuildDone() {
            buildStyles(args.mode);
            removeLegacyScripts();

            if (args.mode === 'prod') {
                cleanProductionSourceMaps();
            }
        })
        .catch(function onBuildError(error) {
            console.error(error);
            process.exitCode = 1;
        });
}

main();
