<?php

namespace RRZE\AccessControl;

defined('ABSPATH') || exit;

class Utils
{
    public static function encodeEmail($email)
    {
        $output = '';
        for ($i = 0; $i < mb_strlen($email); $i++) {
            $output .= '&#' . ord($email[$i]) . ';';
        }
        return $output;
    }
}
