'use strict';

var fs = require('fs');
var path = require('path');

function readJson(filePath) {
    var raw = fs.readFileSync(filePath, 'utf8');
    return JSON.parse(raw);
}

function isNonEmptyString(value) {
    return typeof value === 'string' && value.trim() !== '';
}

function getString(obj, key, fallback) {
    if (!obj || typeof obj !== 'object') {
        return fallback;
    }

    if (!Object.prototype.hasOwnProperty.call(obj, key)) {
        return fallback;
    }

    if (!isNonEmptyString(obj[key])) {
        return fallback;
    }

    return obj[key].trim();
}

function getCsv(value) {
    if (Array.isArray(value)) {
        return value.join(', ');
    }

    if (isNonEmptyString(value)) {
        return value.trim();
    }

    return '';
}

function replacePluginHeaderField(content, field, value) {
    var pattern = new RegExp(
        '^(\\s*(?:\\*\\s*)?' + field + ':\\s*)(.+)$',
        'm'
    );

    if (!pattern.test(content)) {
        throw new Error('Plugin header field not found: ' + field);
    }

    return content.replace(pattern, function replaceHeaderField(match, prefix) {
        return prefix + value;
    });
}

function synchronizePluginHeader(pkg) {
    var author = pkg.author && typeof pkg.author === 'object' ? pkg.author : {};
    var repository = pkg.repository && typeof pkg.repository === 'object' ? pkg.repository : {};
    var compatibility = pkg.compatibility && typeof pkg.compatibility === 'object' ? pkg.compatibility : {};
    var mainFile = getString(pkg, 'main', '');
    var headerFields = [
        ['Plugin Name', getString(pkg, 'title', getString(pkg, 'name', ''))],
        ['Plugin URI', getString(repository, 'url', '')],
        ['Version', getString(pkg, 'version', '')],
        ['Description', getString(pkg, 'description', '')],
        ['Author', getString(author, 'name', '')],
        ['Author URI', getString(author, 'url', '')],
        ['License', getString(pkg, 'license', '')],
        ['License URI', getString(pkg, 'licenseurl', '')],
        ['Text Domain', getString(pkg, 'textdomain', '')],
        ['Domain Path', getString(pkg, 'domainPath', '')],
        ['Requires at least', getString(compatibility, 'wprequires', '')],
        ['Requires PHP', getString(compatibility, 'phprequires', '')]
    ];
    var root = process.cwd();
    var filePath;
    var content;
    var updated;
    var i;

    if (!mainFile) {
        throw new Error('package.json has no valid "main" entry');
    }

    filePath = path.join(root, mainFile);
    if (!fs.existsSync(filePath)) {
        throw new Error('Plugin main file not found: ' + filePath);
    }

    content = fs.readFileSync(filePath, 'utf8');
    updated = content;

    for (i = 0; i < headerFields.length; i++) {
        if (!headerFields[i][1]) {
            throw new Error('package.json has no value for plugin header field: ' + headerFields[i][0]);
        }

        updated = replacePluginHeaderField(
            updated,
            headerFields[i][0],
            headerFields[i][1]
        );
    }

    if (updated !== content) {
        fs.writeFileSync(filePath, updated, 'utf8');
    }
}

function buildReadme(pkg) {
    var authorObj = pkg.author && typeof pkg.author === 'object' ? pkg.author : {};
    var repository = pkg.repository && typeof pkg.repository === 'object' ? pkg.repository : {};
    var compatibility = pkg.compatibility && typeof pkg.compatibility === 'object' ? pkg.compatibility : {};

    var pluginName = getString(pkg, 'title', getString(pkg, 'name', 'RRZE Plugin'));
    var version = getString(pkg, 'version', '0.0.0');
    var description = getString(pkg, 'description', '');

    var author = getString(authorObj, 'name', '');
    var authorUri = getString(authorObj, 'url', '');

    var license = getString(pkg, 'license', '');
    var licenseUri = getString(pkg, 'licenseurl', '');
    var textDomain = getString(pkg, 'textdomain', '');

    var githubURL = getString(repository, 'url', '');
    var githubIssue = getString(repository, 'issues', '');

    var requiresAtLeast = getString(compatibility, 'wprequires', '');
    var requiresPHP = getString(compatibility, 'phprequires', '');
    var testedUpTo = getString(compatibility, 'wptestedup', '');

    var tags = getCsv(pkg.tags);

    var out = [];

    out.push('=== Plugin Name: ' + pluginName + ' ===');
    out.push('Version: ' + version);
    out.push('Plugin URI: ' + githubURL);
    out.push('GitHub Issue URL: ' + githubIssue);
    out.push('Author: ' + author);
    out.push('Author URI: ' + authorUri);
    out.push('Licence: ' + license);
    out.push('Licence URI: ' + licenseUri);

    if (requiresAtLeast) {
        out.push('Requires at least: ' + requiresAtLeast);
    }
    if (testedUpTo) {
        out.push('Tested up to: ' + testedUpTo);
    }
    if (requiresPHP) {
        out.push('Requires PHP: ' + requiresPHP);
    }
    if (textDomain) {
        out.push('Text Domain: ' + textDomain);
    }
    if (tags) {
        out.push('Tags: ' + tags);
    }

    out.push('');
    out.push('== Description ==');
    out.push('');
    out.push(description);

    return out.join('\n') + '\n';
}

function main() {
    var root = process.cwd();
    var pkgPath = path.join(root, 'package.json');
    var pkg = readJson(pkgPath);

    synchronizePluginHeader(pkg);

    var readme = buildReadme(pkg);
    var outPath = path.join(root, 'readme.txt');

    fs.writeFileSync(outPath, readme, 'utf8');
}

main();
