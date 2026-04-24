<?php
/**
 * Plugin Name:       Custom Func
 * Description:       Headless site functionality plugin for abcnorio.org.
 * Version:           0.1.0
 * Requires PHP:      8.3
 * Author:            abcnorio
 * Text Domain:       custom-func
 */

if (! defined('ABSPATH')) {
    exit;
}

define('ABCNORIO_CUSTOM_FUNC_FILE', __FILE__);

$autoload = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
}

register_activation_hook(__FILE__, ['abcnorio\\CustomFunc\\Plugin', 'activate']);

abcnorio\CustomFunc\Plugin::boot();
