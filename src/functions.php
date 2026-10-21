<?php
/**
 * Additional helper functions
 *
 * This file is included by composer's autoload mechanism.
 * Requiring global functions is considered bad practice
 * but this will stay until all old code smells are removed.
 */

/**
 * Returns the translation for a textstring
 * @param string $text
 * @return string
 */
function __($text)
{
    return gettext($text);
}

/**
 * Echo the translation for a textstring
 * @param string $text
 */
function _e($text)
{
    echo gettext($text);
}

/**
 * Return singular/plural translation for a textstring
 * @param string $msg1
 * @param string $msg2
 * @param int $n
 * @return string
 */
function _n($msg1, $msg2, $n)
{
    return ngettext($msg1, $msg2, $n);
}

/**
 * PHP 8.1+ adds a "full_path" entry to every uploaded file in $_FILES.
 * The Symfony 3.4 FileBag only knows the five classic keys and would not recognize the upload as a file
 * ($request->files->get() returns an array then), which breaks all file uploads.
 *
 * @param array $files
 * @return array
 */
function runalyze_strip_full_path_from_files(array $files)
{
    unset($files['full_path']);

    foreach ($files as $key => $value) {
        if (is_array($value)) {
            $files[$key] = runalyze_strip_full_path_from_files($value);
        }
    }

    return $files;
}

if (!empty($_FILES)) {
    $_FILES = runalyze_strip_full_path_from_files($_FILES);
}
