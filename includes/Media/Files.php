<?php

namespace RRZE\AccessControl\Media;

defined('ABSPATH') || exit;

use RRZE\AccessControl\{Access, Options};

class Files
{
    const PROTECTED_DIRNAME = '_protected';

    public static function init()
    {
        add_filter('upload_dir', [__CLASS__, 'change_upload_directory'], 999);
    }

    public static function is_attachment_protected($attachment_id)
    {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);

        if (!empty($file) && (0 === stripos($file, self::protected_upload_dir('/')))) {
            return true;
        }

        return false;
    }

    public static function protected_upload_dir($path = '', $in_url = false)
    {
        $dirpath = $in_url ? '/' : '';
        $dirpath .= self::PROTECTED_DIRNAME;
        $dirpath .= $path;

        return $dirpath;
    }

    public static function change_upload_directory($param)
    {
        if (isset($_POST['access_protected']) && 'on' == $_POST['access_protected']) {
            $param['subdir'] = self::protected_upload_dir($param['subdir'], true);
            $param['path'] = $param['basedir'] . $param['subdir'];
            $param['url'] = $param['baseurl'] . $param['subdir'];
        }

        return $param;
    }

    public static function move_attachment_from_protected($attachment_id)
    {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);

        if (0 !== stripos($file, self::protected_upload_dir('/'))) {
            return true;
        }

        $new_reldir = ltrim(dirname($file), self::protected_upload_dir('/'));

        return self::move_attachment_files($attachment_id, $new_reldir);
    }

    public static function move_attachment_to_protected($attachment_id)
    {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);

        if (0 === stripos($file, self::protected_upload_dir('/'))) {
            return true;
        }

        $reldir = dirname($file);
        if (in_array($reldir, array('\\', '/', '.'), true)) {
            $reldir = '';
        }

        $new_reldir = path_join(self::protected_upload_dir(), $reldir);

        return self::move_attachment_files($attachment_id, $new_reldir);
    }

    public static function move_attachment_files($attachment_id, $new_reldir)
    {
        if ('attachment' != get_post_type($attachment_id)) {
            return new \WP_Error('not_attachment', sprintf(
                /* translators: %d is the attachment id */
                __("The post %d is not a Media Post-Type.", 'rrze-ac'),
                $attachment_id
            ));
        }

        if (path_is_absolute($new_reldir)) {
            return new \WP_Error('new_reldir_not_relative', sprintf(
                /* translators: %s is the path to the WP uploads directory */
                __("The newly specified path %s is absolute. The new path must be a path relative to the WP uploads directory.", 'rrze-ac'),
                $new_reldir
            ));
        }

        $meta = wp_get_attachment_metadata($attachment_id);

        $file = get_post_meta($attachment_id, '_wp_attached_file', true);

        $backups = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);

        $upload_dir = wp_upload_dir();

        $old_reldir = dirname($file);
        if (in_array($old_reldir, array('\\', '/', '.'), true)) {
            $old_reldir = '';
        }

        if ($new_reldir === $old_reldir) {
            return null;
        }

        $old_fulldir = path_join($upload_dir['basedir'], $old_reldir);
        $new_fulldir = path_join($upload_dir['basedir'], $new_reldir);

        if (!wp_mkdir_p($new_fulldir)) {
            return new \WP_Error('wp_mkdir_p_error', sprintf(
                /* translators: %s is the path to a directory */
                __("An error has occurred while creating the directory %s.", 'rrze-ac'),
                $new_fulldir
            ));
        }

        $meta_sizes = array();
        if (isset($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                $meta_sizes[] = $size['file'];
            }
        }

        $backup_sizes = array();
        if (is_array($backups)) {
            foreach ($backups as $size) {
                $backup_sizes[] = $size['file'];
            }
        }

        $old_basenames = $new_basenames = array_merge(array(basename($file)), $meta_sizes, $backup_sizes);

        $orig_basename = basename($file);
        if (is_array($backups) && isset($backups['full-orig'])) {
            $orig_basename = $backups['full-orig']['file'];
        }

        $orig_filename = pathinfo($orig_basename);
        $orig_filename = $orig_filename['filename'];
        $conflict = true;
        $number = 1;
        $separator = '#';
        $med_filename = $orig_filename;

        while ($conflict) {
            $conflict = false;
            foreach ($new_basenames as $basename) {
                if (is_file(path_join($new_fulldir, $basename))) {
                    $conflict = true;
                    break;
                }
            }

            if ($conflict) {
                $new_filename = "$orig_filename$number";
                $number++;
                $pattern = "$separator$med_filename";
                $replace = "$separator$new_filename";
                $new_basenames = explode($separator, ltrim(str_replace($pattern, $replace, $separator . implode($separator, $new_basenames)), $separator));
                $med_filename = $new_filename;
            }
        }

        $unique_old_basenames = array_values(array_unique($old_basenames));
        $unique_new_basenames = array_values(array_unique($new_basenames));

        $i = count($unique_old_basenames);
        while ($i--) {
            $old_fullpath = path_join($old_fulldir, $unique_old_basenames[$i]);
            $new_fullpath = path_join($new_fulldir, $unique_new_basenames[$i]);

            rename($old_fullpath, $new_fullpath);

            if (!is_file($new_fullpath)) {
                return new \WP_Error('rename_failed', sprintf(
                    /* translators: 1: old file path, 2: new file path */
                    __('The file can not be moved from %1$s to %2$s.', 'rrze-ac'),
                    $old_fullpath,
                    $new_fullpath
                ));
            }
        }

        $file = path_join($new_reldir, $new_basenames[0]);
        if (wp_attachment_is_image($attachment_id)) {
            $meta['file'] = $file;
        }

        update_post_meta($attachment_id, '_wp_attached_file', $file);

        if ($new_basenames[0] != $old_basenames[0]) {
            $orig_basename = ltrim(str_replace($pattern, $replace, $separator . $orig_basename), $separator);

            if (is_array($meta['sizes'])) {
                $i = 0;
                foreach ($meta['sizes'] as $size => $data) {
                    $meta['sizes'][$size]['file'] = $new_basenames[++$i];
                }
            }

            if (is_array($backups)) {
                $i = 0;
                $l = count($backups);
                $new_backup_sizes = array_slice($new_basenames, -$l, $l);

                foreach ($backups as $size => $data) {
                    $backups[$size]['file'] = $new_backup_sizes[$i++];
                }
                update_post_meta($attachment_id, '_wp_attachment_backup_sizes', $backups);
            }
        }

        update_post_meta($attachment_id, '_wp_attachment_metadata', $meta);

        $path = explode('/wp-content/', path_join($new_fulldir, $orig_basename));

        $permalink = site_url('/wp-content/' . $path[1]);

        global $wpdb;
        $wpdb->update($wpdb->posts, array('guid' => $permalink), array('ID' => $attachment_id), array('%s'), array('%d'));

        return true;
    }

    public static function request_file()
    {
        if (isset($_GET['protected_file']) && !empty($_GET['protected_file'])) {
            if (isset($_GET['access_rewrite_test']) && $_GET['access_rewrite_test']) {
                die('rewrite test passed');
            }

            self::get_file($_GET['protected_file']);
            exit();
        }
    }

    public static function get_file($rel_file)
    {
        $rel_file = isset($rel_file) ? $rel_file : '';
        $upload_dir = wp_upload_dir();

        if (empty($upload_dir['basedir'])) {
            wp_die(
                __('The requested file was not found.', 'rrze-ac'),
                __('Not Found', 'rrze-ac'),
                [
                    'response' => '404',
                    'back_link' => false
                ]
            );
        }

        $file = rtrim($upload_dir['basedir'], '/') . str_replace('..', '', $rel_file);

        if (!is_file($file)) {
            $rel_file = str_replace('_protected', '', rtrim($rel_file, '/'));
            $file = rtrim($upload_dir['basedir'], '/') . str_replace('..', '', $rel_file);
            if (!is_file($file)) {
                wp_die(
                    __('The requested file was not found.', 'rrze-ac'),
                    __('Not Found', 'rrze-ac'),
                    [
                        'response' => '404',
                        'back_link' => false
                    ]
                );
            }
        }

        $mime = wp_check_filetype($file);

        if (isset($mime['type']) && $mime['type']) {
            $mimetype = $mime['type'];
        } else {
            wp_die(
                __('The request was due lack of client permission not performed.', 'rrze-ac'),
                __('Forbidden', 'rrze-ac'),
                [
                    'response' => '403',
                    'back_link' => false
                ]
            );
        }

        $file_info = pathinfo($rel_file);

        if (0 !== stripos($file_info['dirname'] . '/', self::protected_upload_dir('/', true))) {
            wp_die(
                __('The requested file was not found.', 'rrze-ac'),
                __('Not Found', 'rrze-ac'),
                [
                    'response' => '404',
                    'back_link' => false
                ]
            );
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', 1);
        }

        if (!defined('DONOTCACHEOBJECT')) {
            define('DONOTCACHEOBJECT', 1);
        }

        if (!defined('DONOTMINIFY')) {
            define('DONOTMINIFY', 1);
        }

        global $wpdb;
        $attachment_dirname = trim($file_info['dirname'], '/\\');
        $attachment_file = $attachment_dirname . '/' . $file_info['basename'];

        $attachment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
                '_wp_attached_file',
                $attachment_file
            )
        );

        if (is_null($attachment)) {
            $attachment = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT post_id "
                        . "FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value LIKE %s "
                        . "AND post_id IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value LIKE %s) ",
                    '_wp_attachment_metadata',
                    '%' . $file_info['basename'] . '%',
                    '_wp_attached_file',
                    '%' . $attachment_dirname . '%'
                )
            );
        }

        if (is_null($attachment)) {
            wp_die(
                __('The requested attachment was not found.', 'rrze-ac'),
                __('Not Found', 'rrze-ac'),
                [
                    'response' => '404',
                    'back_link' => false
                ]
            );
        }

        $attachment_id = $attachment->post_id;

        if (!Access::try($attachment_id)) {
            $options = Options::getOptions();
            wp_die(
                Access::permission_message($attachment_id, $options),
                __('Login is required', 'rrze-ac'),
                [
                    'response' => '403',
                    'back_link' => false
                ]
            );
        }

        header('Content-Type: ' . $mimetype);
        header('Content-Length: ' . filesize($file));

        $last_modified = gmdate('D, d M Y H:i:s', filemtime($file));
        $etag = '"' . md5($last_modified) . '"';
        header("Last-Modified: $last_modified GMT");
        header('ETag: ' . $etag);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Dec 1994 16:00:00 GMT');

        $client_etag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? stripslashes($_SERVER['HTTP_IF_NONE_MATCH']) : false;

        if (!isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $_SERVER['HTTP_IF_MODIFIED_SINCE'] = false;
        }

        $client_last_modified = trim($_SERVER['HTTP_IF_MODIFIED_SINCE']);

        $client_modified_timestamp = $client_last_modified ? strtotime($client_last_modified) : 0;

        $modified_timestamp = strtotime($last_modified);

        if (($client_last_modified && $client_etag) ? (($client_modified_timestamp >= $modified_timestamp) && ($client_etag == $etag)) : (($client_modified_timestamp >= $modified_timestamp) || ($client_etag == $etag))) {
            status_header(304);  // Not Modified
            exit();
        }

        if (ob_get_length()) {
            ob_clean();
        }

        flush();

        readfile($file);
        exit();
    }
}
