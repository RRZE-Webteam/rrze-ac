<?php

namespace RRZE\AccessControl\Media;

defined('ABSPATH') || exit;

use RRZE\AccessControl\{Access, Options};

class Files
{
    const PROTECTED_DIRNAME = '_protected';

    public static function init()
    {
        add_filter('upload_dir', [__CLASS__, 'changeUploadDirectory'], 999);

        add_action('init', [__CLASS__, 'requestFile'], 0);
    }

    public static function isAttachmentProtected($attachmentId)
    {
        $file = get_post_meta($attachmentId, '_wp_attached_file', true);

        if (!empty($file) && (0 === stripos($file, self::protectedUploadDir('/')))) {
            return true;
        }

        return false;
    }

    public static function protectedUploadDir($path = '', $inUrl = false)
    {
        $dirpath = $inUrl ? '/' : '';
        $dirpath .= self::PROTECTED_DIRNAME;
        $dirpath .= $path;

        return $dirpath;
    }

    public static function changeUploadDirectory($param)
    {
        if (isset($_POST['access_protected']) && 'on' == $_POST['access_protected']) {
            $param['subdir'] = self::protectedUploadDir($param['subdir'], true);
            $param['path'] = $param['basedir'] . $param['subdir'];
            $param['url'] = $param['baseurl'] . $param['subdir'];
        }

        return $param;
    }

    public static function moveAttachmentFromProtected($attachmentId)
    {
        $file = get_post_meta($attachmentId, '_wp_attached_file', true);

        if (0 !== stripos($file, self::protectedUploadDir('/'))) {
            return true;
        }

        $newRelDir = ltrim(dirname($file), self::protectedUploadDir('/'));

        return self::moveAttachmentFiles($attachmentId, $newRelDir);
    }

    public static function moveAttachmentToProtected($attachmentId)
    {
        $file = get_post_meta($attachmentId, '_wp_attached_file', true);

        if (0 === stripos($file, self::protectedUploadDir('/'))) {
            return true;
        }

        $reldir = dirname($file);
        if (in_array($reldir, array('\\', '/', '.'), true)) {
            $reldir = '';
        }

        $newRelDir = path_join(self::protectedUploadDir(), $reldir);

        return self::moveAttachmentFiles($attachmentId, $newRelDir);
    }

    public static function moveAttachmentFiles($attachmentId, $newRelDir)
    {
        if ('attachment' != get_post_type($attachmentId)) {
            return new \WP_Error('not_attachment', sprintf(
                /* translators: %d is the attachment id */
                __("The post %d is not a Media Post-Type.", 'rrze-ac'),
                $attachmentId
            ));
        }

        if (path_is_absolute($newRelDir)) {
            return new \WP_Error('new_reldir_not_relative', sprintf(
                /* translators: %s is the path to the WP uploads directory */
                __("The newly specified path %s is absolute. The new path must be a path relative to the WP uploads directory.", 'rrze-ac'),
                $newRelDir
            ));
        }

        $meta = wp_get_attachment_metadata($attachmentId);

        $file = get_post_meta($attachmentId, '_wp_attached_file', true);

        $backups = get_post_meta($attachmentId, '_wp_attachment_backup_sizes', true);

        $uploadDir = wp_upload_dir();

        $oldRelDir = dirname($file);
        if (in_array($oldRelDir, array('\\', '/', '.'), true)) {
            $oldRelDir = '';
        }

        if ($newRelDir === $oldRelDir) {
            return null;
        }

        $oldFullDir = path_join($uploadDir['basedir'], $oldRelDir);
        $newFullDir = path_join($uploadDir['basedir'], $newRelDir);

        if (!wp_mkdir_p($newFullDir)) {
            return new \WP_Error('wp_mkdir_p_error', sprintf(
                /* translators: %s is the path to a directory */
                __("An error has occurred while creating the directory %s.", 'rrze-ac'),
                $newFullDir
            ));
        }

        $metaSizes = [];
        if (isset($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                $metaSizes[] = $size['file'];
            }
        }

        $backupSizes = [];
        if (is_array($backups)) {
            foreach ($backups as $size) {
                $backupSizes[] = $size['file'];
            }
        }

        $oldBasenames = $newBasenames = array_merge(array(basename($file)), $metaSizes, $backupSizes);

        $origBasename = basename($file);
        if (is_array($backups) && isset($backups['full-orig'])) {
            $origBasename = $backups['full-orig']['file'];
        }

        $origFilename = pathinfo($origBasename);
        $origFilename = $origFilename['filename'];
        $conflict = true;
        $number = 1;
        $separator = '#';
        $medFilename = $origFilename;

        while ($conflict) {
            $conflict = false;
            foreach ($newBasenames as $basename) {
                if (is_file(path_join($newFullDir, $basename))) {
                    $conflict = true;
                    break;
                }
            }

            if ($conflict) {
                $newFilename = "$origFilename$number";
                $number++;
                $pattern = "$separator$medFilename";
                $replace = "$separator$newFilename";
                $newBasenames = explode($separator, ltrim(str_replace($pattern, $replace, $separator . implode($separator, $newBasenames)), $separator));
                $medFilename = $newFilename;
            }
        }

        $uniqueOldBasenames = array_values(array_unique($oldBasenames));
        $uniqueNewBasenames = array_values(array_unique($newBasenames));

        $i = count($uniqueOldBasenames);
        while ($i--) {
            $oldFullpath = path_join($oldFullDir, $uniqueOldBasenames[$i]);
            $newFullpath = path_join($newFullDir, $uniqueNewBasenames[$i]);

            rename($oldFullpath, $newFullpath);

            if (!is_file($newFullpath)) {
                return new \WP_Error('rename_failed', sprintf(
                    /* translators: 1: old file path, 2: new file path */
                    __('The file can not be moved from %1$s to %2$s.', 'rrze-ac'),
                    $oldFullpath,
                    $newFullpath
                ));
            }
        }

        $file = path_join($newRelDir, $newBasenames[0]);
        if (wp_attachment_is_image($attachmentId)) {
            $meta['file'] = $file;
        }

        update_post_meta($attachmentId, '_wp_attached_file', $file);

        if ($newBasenames[0] != $oldBasenames[0]) {
            $origBasename = ltrim(str_replace($pattern, $replace, $separator . $origBasename), $separator);

            if (is_array($meta['sizes'])) {
                $i = 0;
                foreach ($meta['sizes'] as $size => $data) {
                    $meta['sizes'][$size]['file'] = $newBasenames[++$i];
                }
            }

            if (is_array($backups)) {
                $i = 0;
                $l = count($backups);
                $new_backup_sizes = array_slice($newBasenames, -$l, $l);

                foreach ($backups as $size => $data) {
                    $backups[$size]['file'] = $new_backup_sizes[$i++];
                }
                update_post_meta($attachmentId, '_wp_attachment_backup_sizes', $backups);
            }
        }

        update_post_meta($attachmentId, '_wp_attachment_metadata', $meta);

        $path = explode('/wp-content/', path_join($newFullDir, $origBasename));

        $permalink = site_url('/wp-content/' . $path[1]);

        global $wpdb;
        $wpdb->update($wpdb->posts, array('guid' => $permalink), array('ID' => $attachmentId), array('%s'), array('%d'));

        return true;
    }

    public static function requestFile()
    {
        if (isset($_GET['protected_file']) && !empty($_GET['protected_file'])) {
            if (isset($_GET['access_rewrite_test']) && $_GET['access_rewrite_test']) {
                die('rewrite test passed');
            }

            self::getFile($_GET['protected_file']);
            exit();
        }
    }

    public static function getFile($relFile)
    {
        $relFile = isset($relFile) ? $relFile : '';
        $relFile = str_replace('..', '', $relFile);
        $relFile = rtrim($relFile, '/');
        $startPos = strpos($relFile, self::protectedUploadDir('/', true));
        if ($startPos !== false) {
            $relFile = substr($relFile, $startPos);
        }

        $uploadDir = wp_upload_dir();

        if (empty($uploadDir['basedir'])) {
            wp_die(
                __('The requested file was not found.', 'rrze-ac'),
                __('Not Found', 'rrze-ac'),
                [
                    'response' => '404',
                    'back_link' => false
                ]
            );
        }

        $file = $uploadDir['basedir'] . $relFile;

        if (!is_file($file)) {
            $relFile = str_replace(self::protectedUploadDir('/', true), '', $relFile);
            $file = $uploadDir['basedir'] . $relFile;
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

        $fileInfo = pathinfo($relFile);

        if (0 !== stripos($fileInfo['dirname'], self::protectedUploadDir('/', true))) {
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
        $attachmentDirname = trim($fileInfo['dirname'], '/\\');
        $attachmentFile = $attachmentDirname . '/' . $fileInfo['basename'];

        $attachment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
                '_wp_attached_file',
                $attachmentFile
            )
        );

        if (is_null($attachment)) {
            $attachment = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT post_id "
                        . "FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value LIKE %s "
                        . "AND post_id IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value LIKE %s) ",
                    '_wp_attachment_metadata',
                    '%' . $fileInfo['basename'] . '%',
                    '_wp_attached_file',
                    '%' . $attachmentDirname . '%'
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

        $attachmentId = $attachment->post_id;

        if (!Access::try($attachmentId)) {
            $options = Options::getOptions();
            wp_die(
                Access::permissionMessage($attachmentId, $options),
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

        $clientEtag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? stripslashes($_SERVER['HTTP_IF_NONE_MATCH']) : false;

        if (!isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $_SERVER['HTTP_IF_MODIFIED_SINCE'] = false;
        }

        $clientLastModified = trim($_SERVER['HTTP_IF_MODIFIED_SINCE']);

        $clientModifiedTimestamp = $clientLastModified ? strtotime($clientLastModified) : 0;

        $modifiedTimestamp = strtotime($last_modified);

        if (($clientLastModified && $clientEtag) ? (($clientModifiedTimestamp >= $modifiedTimestamp) && ($clientEtag == $etag)) : (($clientModifiedTimestamp >= $modifiedTimestamp) || ($clientEtag == $etag))) {
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
