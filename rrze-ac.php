<?php

/**
 * Plugin Name:     RRZE Access Control
 * Plugin URI:      https://gitlab.rrze.fau.de/rrze-webteam/rrze-ac
 * Description:     Allows protection of files/documents through user and network related functions.
 * Version:         2.5.3
 * Author:          RRZE Webteam
 * Author URI:      https://blogs.fau.de/webworking/
 * License:         GNU General Public License v2
 * License URI:     http://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path:     /languages
 * Text Domain:     rrze-ac
 */

namespace RRZE\AccessControl;

use RRZE\AccessControl\Main;

defined('ABSPATH') || exit;

const RRZE_PHP_VERSION = '7.1';
const RRZE_WP_VERSION = '5.2';

register_activation_hook(__FILE__, 'RRZE\AccessControl\activation');

add_action('plugins_loaded', 'RRZE\AccessControl\loaded');

/*
 * Einbindung der Sprachdateien.
 * @return void
 */
function load_textdomain() {
    load_plugin_textdomain('rrze-ac', FALSE, sprintf('%s/languages/', dirname(plugin_basename(__FILE__))));
}

/*
* Wird durchgeführt, nachdem das Plugin aktiviert wurde.
* @return void
*/
function activation() {
    // Sprachdateien werden eingebunden.
    load_textdomain();

    // Überprüft die minimal erforderliche PHP- u. WP-Version.
    system_requirements();
 }

 /*
  * Überprüft die minimal erforderliche PHP- u. WP-Version.
  * @return void
  */
function system_requirements() {
    global $is_apache;

    $error = '';

    // Überprüft die minimal erforderliche PHP-Version.
    if (version_compare(PHP_VERSION, RRZE_PHP_VERSION, '<')) {
        $error = sprintf(__("Your server is running PHP version %s. Please upgrade at least to PHP version %s.", 'rrze-ac'), PHP_VERSION, RRZE_PHP_VERSION);
    }

    // Überprüft die minimal erforderliche WP-Version.
    elseif (version_compare($GLOBALS['wp_version'], RRZE_WP_VERSION, '<')) {
        $error = sprintf(__("Your Wordpress version is %s. Please upgrade at least to Wordpress version %s.", 'rrze-ac'), $GLOBALS['wp_version'], RRZE_WP_VERSION);
    }

    // Überprüft das Webserver-Software.
    elseif (!$is_apache) {
        $error = __("The Web server software is not compatible. Please use instead the Apache Web server software.", 'rrze-ac');
    }

    // Überprüft Multisite-Einstellung.
    elseif (!is_multisite()) {
        $error = __("The WordPress instance is not a MultiSite.", 'rrze-ac');
    }

    // Überprüft Rewrite-Modul.
    elseif (!got_mod_rewrite()) {
        $error = __("The Web server software does not support the Rewrite module.", 'rrze-ac');
    }

    // Wenn die Überprüfung fehlschlägt, dann wird das Plugin automatisch deaktiviert.
    if (!empty($error)) {
        deactivate_plugins(plugin_basename(__FILE__), FALSE, TRUE);
        wp_die($error);
    }
 }

/*
* Wird durchgeführt, nachdem das WP-Grundsystem hochgefahren
* und alle Plugins eingebunden wurden.
* @return void
*/
function loaded() {
    // Sprachdateien werden eingebunden.
    load_textdomain();

    // Erforderliche WP-Dateien.
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

    // Automatische Laden von Klassen.
    autoload();
}

/*
 * Automatische Laden von Klassen.
 * @return void
 */
function autoload() {
    require 'autoload.php';
    $main = new Main(plugin_basename(__FILE__));
}
