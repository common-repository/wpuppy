<?php
header('Content-Type: application/json');

//Need WPuppy key against script kiddies
if (filter_input(INPUT_GET, "key") === null) {
    finish("error", "No script kiddies");
}

//Load Wordpress
require_once(__DIR__ . "/../../../../wp-load.php");

$key = get_option("wpuppy_key");

if (filter_input(INPUT_GET, "key") !== $key) {
    finish("error", "Key is invalid");
}

//Find the correct Plugin Directory (as this can be changed)
$pluginDir = implode("/", array_slice(explode("/", plugins_url()), 3));

$abs = explode("/", ABSPATH);
$plug = explode("/", $pluginDir);

if ($abs[count($abs) - 2] === $plug[0]) {
    unset($plug[0]);
    $pluginDir = ABSPATH . implode("/", $plug);
} else {
    $pluginDir = ABSPATH . $pluginDir;
}

//Check if the WPuppy plugin is actually installed
if (!file_exists("{$pluginDir}/wpuppy/wpuppy.php")) {
    finish("error", "WPuppy is not installed: {$pluginDir}");
}

if (!function_exists("get_plugins")) {
    require_once(ABSPATH . "/wp-admin/includes/plugin.php");
}

//re-activate wpuppy
$activate = activate_plugin("wpuppy/wpuppy.php");

if (is_wp_error($activate)) {
    finish(
        "error",
        "Error reactivating plugin: " . end($result->get_upgrade_messages())
    );
}

finish("success", "Reactivated plugin");

/**
 * Finish the reactivation process
 *
 * @param string $code    "success" or "error"
 * @param string $message message you want to pass
 * @return void
 */
function finish($code, $message)
{
    die(json_encode(array($code => $message)));
}
