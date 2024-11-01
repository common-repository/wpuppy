<?php
if (!defined('ABSPATH')) {
    die ('No script kiddies please');
}

// Check if a user has been send to us
if (empty($user)) {
    die("No user specified");
}

// Check if the loginKey was send to us
if (empty($loginKey)) {
    die("No login key specified");
}

// Check the expiration, a login is only valid for one minute
$expiration = get_option("login_expire_{$user}");
if ($expiration < time()) {
    delete_option("login_expire_{$user}");
    delete_option("login_{$user}");
    die("This login has expired");
}

// Check the key
$key = get_option("login_{$user}");
if ($loginKey !== $key) {
    die("The login key is invalid");
}

// Log the user in
wp_set_auth_cookie($user, true);

// Remove the login key / expiration
delete_option("login_expire_{$user}");
delete_option("login_{$user}");

// Redirect to the Admin URL
$adminUrl = get_admin_url();
header("Location: {$adminUrl}");
