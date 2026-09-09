#!/usr/bin/env node

'use strict';

var fs = require('fs');
var path = require('path');
var webpack = require('webpack');
var webpackConfig = require('../webpack.config');

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
    var buildDirectory = path.resolve(__dirname, '..', 'build');
    var removed = removeSourceMapFiles(buildDirectory);

    if (removed > 0) {
        console.log('Removed ' + removed + ' production source map file(s).');
    }
}

function runWatch(config) {
    var compiler = webpack(config);

    compiler.watch({}, function onBuild(error, stats) {
        if (error) {
            console.error(error);
            return;
        }

        printStats(stats);
    });

    console.log('Webpack watch active.');
}

function main() {
    var args = parseArgs(process.argv);
    var config = getWebpackConfig(args.mode);

    if (args.mode === 'prod') {
        cleanProductionSourceMaps();
    }

    if (args.watch) {
        runWatch(config);
        return;
    }

    runBuild(config)
        .then(function onBuildDone() {
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
