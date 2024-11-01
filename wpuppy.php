<?php
/*
    Plugin Name: WPuppy
    Plugin URI: http://www.wpuppy.com/
    Description: This is the plugin used by WPuppy web Application to communicate to Wordpress.
    Version: 1.3.4.2
    Author: WPuppy
    Author URI: http://www.wpuppy.com/
 */

if (!defined("ABSPATH")) {
    die("No script kiddies plis");
}

require_once(ABSPATH . "wp-admin/includes/class-wp-upgrader.php");

class WPuppy
{
    private $installed;
    private $key;
    private $pluginDir;
    private $adminDir;

    public function __construct()
    {
        $this->init();
    }
    private function init()
    {
        add_action("admin_menu", array($this, "generateMenus"));
        add_action("admin_init", array($this, "frameHeader"));

        add_filter("auto_core_update_send_email", "wpb_stop_auto_update_emails", 10, 4);

        if (($this->installed = get_option("wpuppy_setup")) === false) {
            $this->installed = "false";
            add_option("wpuppy_setup", "false");
        }

        if ($this->installed === "") {
            update_option("wpuppy_setup", "false");
        }

        $this->key = get_option("wpuppy_key");

        $this->pluginDir = str_replace("//", "/", ABSPATH . substr(plugins_url(), strlen(home_url())) . "/");
        $this->adminDir = ABSPATH . "wp-admin/";

        add_action("init", function () {
            header("Access-Control-Allow-Origin: https://*.wpuppy.com'");
            $site_url = str_replace("http://", "", str_replace("https://", "", str_replace("www.", "", site_url())));

            if (substr($site_url, -1) !== "/") {
                $site_url .= "/";
            }

            $url = trim(
                str_replace(
                    $site_url,
                    "",
                    str_replace("www.", "", $_SERVER["HTTP_HOST"]) . $_SERVER["REQUEST_URI"]
                )
            );
            $url = explode("/", $url);

            if ($url[0] !== "wpuppy") {
                return;
            }

            if ($url[1] === "api") {
                @ini_set("memory_limit", "-1");
                @ini_set("max_execution_time", "0");
                @set_time_limit(0);

                // load the file if exists
                include_once(__DIR__ . "/wpuppy-api.php");
                exit;
            }

            if ($url[1] === "setup") {
                @ini_set("memory_limit", "-1");
                @set_time_limit(0);

                // load the file if exists
                include_once(__DIR__ . "/wpuppy-setup.php");
                exit;
            }

            if ($url[1] === "login") {
                @ini_set("memory_limit", "-1");
                @set_time_limit(0);

                ob_start();
                $user = filter_var($url[2], FILTER_SANITIZE_STRING);
                $loginKey = filter_var($url[3], FILTER_SANITIZE_STRING);
                // Load the login script
                include_once(__DIR__ . "/wpuppy-login.php");
                ob_end_flush();
                exit;
            }
        });
    }

    public function frameHeader()
    {
        @header("X-Frame-Options: ALLOWALL");
    }

    public function generateMenus()
    {
        add_menu_page("WPuppy", "WPuppy", "manage_options", "wpuppy/wpuppy-admin.php");
        add_submenu_page(
            "wpuppy/wpuppy-admin.php",
            "WPuppy Settings",
            "Settings",
            "manage_options",
            "wpuppy/wpuppy-settings.php"
        );
    }

    public function checkApiKey($key)
    {
        if (filter_var($this->installed, FILTER_VALIDATE_BOOLEAN) === false) {
            return false;
        }

        if ($this->key === $key) {
            return true;
        }

        return false;
    }

    public function showKey()
    {
        return $this->key;
    }

    public function getPluginData()
    {
        return get_plugin_data(__FILE__);
    }

    public function getRequirements()
    {
        require_once(__DIR__ . "/wpuppy-cachecleaner.php");

        $requirements = array(
            "htaccess" => false,
            "accessible" => false,
            "phpversion" => phpversion(),
            "php" => false,
            "safemode" => ini_get("safe_mode"),
            "cachePlugins" => array(),
            "securityPlugins" => array(),
        );

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, get_site_url() . "/wpuppy/api/");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_TIMEOUT, 0);

            //Check if we can execute the CURL and if we get output
        if (($output = curl_exec($curl)) && $output !== "") {
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if ($httpCode === 200) {
                $requirements["accessible"] = true;
            }
        }

        if (version_compare($requirements["phpversion"], "5.3.0", ">=")) {
            $requirements["php"] = true;
        }

        $plugins = $this->getPluginList();

        // add security plugins here, like this:
        // "supported-plugin-slug" => true
        // "unsupported-plugin-slug" => false
        $securityPluginSlugs = array(
            //"wordfence" => false,
            //"better-wp-security" => false,
            //"jetpack" => true,
        );

        foreach ($plugins["data"] as $plugin) {
            if ($plugin["activated"] === "false") {
                continue;
            }

            if (array_key_exists($plugin["slug"], $securityPluginSlugs)) {
                $requirements["securityPlugins"][] = array(
                    "name" => $plugin["name"],
                    "supported" => $securityPluginSlugs[$plugin["slug"]]
                );
                continue;
            }

            try {
                if (($method = \CacheCleaner\CacheCleanerFactory::create($plugin["slug"])) === false) {
                    continue;
                }

                $requirements["cachePlugins"][] = array(
                    "name" => $plugin["name"],
                    "supported" => true
                );
            } catch (\Exception $e) {
                $requirements["cachePlugins"][] = array(
                    "name" => $plugin["name"],
                    "supported" => false
                );
            }
        }

        return $requirements;
    }

        /*
     * UPDATE Functions
     */

    public function updateWordpress()
    {
        $this->setupFSMethod();
        require_once(ABSPATH . "wp-admin/includes/file.php");
        require_once(ABSPATH . "wp-admin/includes/update.php");

        $upgrader = new Core_Upgrader(new Automatic_Upgrader_Skin());

        wp_version_check(array(), true);

        $current = get_site_transient("update_core")->updates[0];

        $result = $upgrader->upgrade($current);

        if (!$result || is_wp_error($result)) {
            $this->unflagMaintenance();

            if ($result->get_error_message() !== __("WordPress is at the latest version.")) {
                return array(
                    "type" => "error",
                    "version" => $version,
                    "message" => $result->get_error_message()
                );
            }
        }

        $translation_upgrader = new Language_Pack_Upgrader(new Automatic_Upgrader_Skin());
        $result = $translation_upgrader->bulk_upgrade();

        wp_localize_script("updates", "_wpUpdatesItemCounts", array(
            "totals" => wp_get_update_data(),
        ));

        if (!$result || is_wp_error($result)) {
            $this->unflagMaintenance();

            return array(
                "type" => "error",
                "message" => $result->get_error_message()
            );
        }

        $this->unflagMaintenance();

        return array(
            "type" => "success",
            "version" => $current->version
        );
    }

    public function wpbStopUpdateEmails($send, $type, $core_update, $result)
    {
        if (!empty($type) && $type == "success") {
            return false;
        }

        return true;
    }

    /**
     * Empty server sided cache of any installed caching plugin
     *
     * All paths through deleteCache should return success, as failing is not necessarily disruptive
     *
     * @return string[] "type" ("success"/"error"), and a "message".
     */
    public function deleteCache()
    {
        require_once(__DIR__ . "/wpuppy-cachecleaner.php");
        $plugins = $this->getPluginList();
        $messages = array();

        foreach ($plugins["data"] as $plugin) {
            if ($plugin["activated"] === "false") {
                continue;
            }

            try {
                if (($method = \CacheCleaner\CacheCleanerFactory::create($plugin["slug"])) === false) {
                    continue;
                }

                $messages[] = $method->clean();
            } catch (\Exception $e) {
                $messages[] = $e->getMessage();
            }
        }

        return array(
            "type" => "success",
            "message" => $messages
        );
    }

    public function updateThemes($slugs)
    {
        define("DOING_CRON", true);

        $this->setupFSMethod();
        require_once(ABSPATH . "wp-admin/includes/file.php");
        require_once(ABSPATH . "wp-admin/includes/theme.php");

        wp_clean_themes_cache();
        wp_update_themes();

        $theme_upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin());
        $messages = array();

        foreach ($slugs as $slug) {
            $result = $theme_upgrader->upgrade($slug);

            //currently also catches here if the theme doesn't exist
            if (!$result || is_wp_error($result)) {
                $error = end($theme_upgrader->skin->get_upgrade_messages());

                if ($error === __("The theme is at the latest version.")) {
                    $messages[] = array(
                        "slug" => $slug,
                        "type" => "success",
                        "message" => "up-to-date"
                    );

                    continue;
                }

                $messages[] = array(
                    "slug" => $slug,
                    "type" => "error",
                    "message" => end($theme_upgrader->skin->get_upgrade_messages())
                );

                continue;
            }

            $messages[] = array("slug" => $slug, "type" => "success");
        }

        $this->unflagMaintenance();
        return array("type" => "success", "message" => $messages);
    }

    public function updatePlugins($slugs)
    {
        define("DOING_CRON", true);

        $this->setupFSMethod();
        require_once(ABSPATH . "wp-admin/includes/file.php");
        require_once(ABSPATH . "wp-admin/includes/plugin.php");

        wp_clean_plugins_cache();
        wp_update_plugins();

        $plugin_updater = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $messages = array();

        foreach ($slugs as $slug) {
            if (($file = $this->getPluginFile($slug)) === false) {
                $messages[] = array(
                    "slug" => $slug,
                    "file" => $file,
                    "type" => "error",
                    "message" => "Couldn't find file for '{$slug}'"
                );

                continue;
            }

            $isActive = is_plugin_active($file);
            $result = $plugin_updater->upgrade($file);
            if (!$result || is_wp_error($result)) {
                $error = end($plugin_updater->skin->get_upgrade_messages());

                if ($error === __("The plugin is at the latest version.")) {
                    $messages[] = array(
                        "slug" => $slug,
                        "file" => $file,
                        "type" => "success",
                        "message" => "up-to-date"
                    );

                    continue;
                }

                $messages[] = array(
                    "slug" => $slug,
                    "file" => $file,
                    "type" => "error",
                    "message" => end($plugin_updater->skin->get_upgrade_messages())
                );

                continue;
            }

            //Try to re-activate the plugin if it turned off
            if ($isActive) {
                $activate = activate_plugin($file);

                if (is_wp_error($activate)) {
                    $messages[] = array(
                        "slug" => $slug,
                        "file" => $file,
                        "type" => "error",
                        "message" => "Error reactivating plugin: " . end($result->get_upgrade_messages())
                    );

                    continue;
                }
            }

            $messages[] = array(
                "slug" => $slug,
                "file" => $file,
                "type" => "success"
            );
        }

        $this->unflagMaintenance();
        return array("type" => "success", "message" => $messages);
    }

    /*
     * GET Functions
     */

    public function getSitemap()
    {
        $urls = array();
        $pages = get_pages();

        foreach ($pages as $page) {
            $urls[] = get_page_link($page->ID);
        }

        $posts = get_posts();
        foreach ($posts as $post) {
            $urls[] = get_post_permalink($post->ID);
        }

        return array("type" => "success", "data" => $urls);
    }

    public function listPluginUpdates()
    {
        $plugins = $this->getPluginList();
        $updates = array();
        foreach ($plugins["data"] as $key => $value) {
            $uptodate = $value["up-to-date"];

            if ($uptodate == "false") {
                $updates[$key] = $value["slug"];
            }
        }

        return $updates;
    }

    public function listThemeUpdates()
    {
        $themes = $this->getThemesList();
        $updates = array();

        foreach ($themes as $key => $theme) {
            if ($theme["version"] !== $theme["latest_version"]) {
                $updates[$key] = $theme["slug"];
            }
        }

        return $updates;
    }

    public function listCoreUpdates()
    {
        $from_api = get_site_transient("update_core");

        if (!isset($from_api->updates) || !is_array($from_api->updates)) {
            return false;
        }

        foreach ($from_api->updates as $update) {
            if ($update->current !== $from_api->version_checked) {
                return $update;
            }
        }
        return false;
    }

    private function getUpdated($toUpdate, &$needsUpdate, &$updated)
    {
        if (!empty($toUpdate)) {
            if (!empty($toUpdate->response)) {
                foreach ($toUpdate->response as $key => $value) {
                    array_push($needsUpdate, $key);
                }
            }
            if (!empty($toUpdate->no_update)) {
                foreach ($toUpdate->no_update as $key => $value) {
                    array_push($updated, $key);
                }
            }
        }
    }

    public function getPluginList()
    {
        $pluginJSON = array();

        if (!function_exists("get_plugins")) {
            require_once ABSPATH . "wp-admin/includes/plugin.php";
        }

        if (!function_exists("plugins_api")) {
            require_once ABSPATH . "wp-admin/includes/plugin-install.php";
        }

        wp_clean_plugins_cache();
        wp_update_plugins();

        $active_plugins = get_option("active_plugins");
        $plugins = get_plugins();

        $toUpdate = get_site_transient("update_plugins");

        $needsUpdate = array();
        $updated = array();
        $this->getUpdated($toUpdate, $needsUpdate, $updated);

        if (!empty($active_plugins)) {
            foreach ($active_plugins as $plugin) {
                $plugins[$plugin]["activated"] = "true";
            }
        }

        foreach ($plugins as $key => $plugin) {
            if (!isset($plugin["activated"])) {
                $plugin["activated"] = "false";
            }

            if (strpos($key, "/") !== false) {
                $string = explode("/", $key);
                $slug = $string[0];
            } else {
                $slug = basename(
                    $key,
                    ".php"
                );
            }

            if (in_array($key, $updated)) {
                $plugin["updated"] = "true";
                $plugin["latest_version"] = $toUpdate->no_update[$key]->new_version;
            } elseif (in_array($key, $needsUpdate)) {
                $plugin["updated"] = "false";
                $plugin["latest_version"] = $toUpdate->response[$key]->new_version;
            } else {
                $r = plugins_api("plugin_information", array(
                    "slug" => $slug
                ));
                if (is_wp_error($r)) {
                    $plugin["updated"] = "Can't update this plugin because it can not be found in the Plugin API";
                    $plugin["latest_version"] = $plugin["Version"];
                } else {
                    $plugin["latest_version"] = $r->version;
                }
            }

            array_push(
                $pluginJSON,
                array(
                    "name" => $plugin["Name"],
                    "slug" => $slug,
                    "activated" => $plugin["activated"],
                    "up-to-date" => $plugin["updated"],
                    "latest_version" => $plugin["latest_version"],
                    "version" => $plugin["Version"]
                )
            );
        }

        $urlJSON = array("type" => "success", "data" => $pluginJSON);

        return $urlJSON;
    }

    public function getThemesList()
    {
        require_once ABSPATH . "/wp-admin/includes/update.php";
        $output = array();

        if (!function_exists("themes_api")) {
            require_once ABSPATH . "wp-admin/includes/theme.php";
        }

        wp_clean_themes_cache();
        wp_update_themes();

        $themes = wp_get_themes();
        $updates = get_theme_updates();
        $current_theme = wp_get_theme();
        $current_theme = $current_theme->get("Name");

        foreach ($themes as $theme) {
            $latest_version = $theme->get("Version");
            $r = themes_api("theme_information", array(
                "slug" => $theme["Template"]
            ));

            if (!is_wp_error($r)) {
                $latest_version = $r->version;
            }

            array_push($output, array(
                "name" => $theme->get("Name"),
                "slug" => $theme["Template"],
                "activated" => (($current_theme === $theme->get("Name")) ? true : false),
                "version" => $theme->get("Version"),
                "latest_version" => $latest_version
            ));
        }

        return array("type" => "success", "themes" => $output);
    }

    /*
     * PUT Functions
     */
    public function backupDatabase()
    {
        global $wpdb, $wp_filesystem;

        try {
            require_once(dirname(__FILE__) . "/lib/database/mysqldump.php");

            $host = $wpdb->dbhost;
            $port = "";

            if (strpos($host, ":") !== false) {
                $host = explode(":", $host);
                $port = "port={$host[1]};";
                $host = $host[0];
            }

            if (empty($host)) {
                $host = "localhost";
            }

            if (!$this->setupFilesystemAPI()) {
                throw new \Exception(
                    "Failed to setup filesystem api: "
                    . print_r($wp_filesystem->errors, true)
                );
            }

            $dump = new Ifsnop\Mysqldump\Mysqldump(
                "mysql:host={$host};{$port}dbname={$wpdb->dbname}",
                $wpdb->dbuser,
                $wpdb->dbpassword,
                array(
                    "add-drop-table" => true,
                    "compress" => "Stream"
                )
            );
            $backup = $dump->start();
        } catch (\Exception $e) {
            return array("type" => "error", "message" => $e->getMessage());
        }

        return array("type" => "success", "data" => $backup);
    }

    public function backupDatabaseClean()
    {
        global $wp_filesystem;

        if (!$this->setupFilesystemAPI()) {
            return array("type" => "error", "message" => "failed to setup filesystem api");
        }

        $folder = $wp_filesystem->abspath();

        if (!$wp_filesystem->exists($folder . "/backup.sql")) {
            return array("type" => "success", "message" => "backup.sql didn't exist");
        }

        if (!$wp_filesystem->delete($folder . "/backup.sql")) {
            return array("type" => "success", "message" => "Couldn't remove backup.sql, please remove manually");
        }

        return array("type" => "success");
    }

    public function restoreDatabase()
    {
        global $wpdb, $wp_filesystem;

        if (!$this->setupFilesystemAPI()) {
            return array("type" => "error", "message" => "failed to setup filesystem api");
        }

        $folder = $wp_filesystem->abspath();
        $backup = $wp_filesystem->get_contents($folder . "/backup.sql");
        $backup = $this->splitSQL($backup);

        $host = $wpdb->dbhost;

        if (strpos($host, ":") !== false) {
            $host = explode(":", $host);
            $port = $host[1];
            $host = $host[0];
            $mysqli = new mysqli($host, $wpdb->dbuser, $wpdb->dbpassword, $wpdb->dbname, $port);
        } else {
            $mysqli = new mysqli($host, $wpdb->dbuser, $wpdb->dbpassword, $wpdb->dbname);
        }

        if ($mysqli->connect_error) {
            return array(
                "type" => "error",
                "message" => "({$mysqli->connect_errno}) {$mysqli->connect_error}"
            );
        }

        foreach ($backup as &$query) {
            if (!$mysqli->multi_query($query) || ($mysqli->errno)) {
                return array("type" => "success", "message" => $mysqli->error);
            }

            $this->clearStoredResults($mysqli);
        }

        return $this->backupDatabaseClean();
    }

    public function generateOneClick($user)
    {
        $id = get_user_by("login", $user)->data->ID;

        if ($id === false) {
            return array(
                "type" => "error",
                "message" => "User does not exist"
            );
        }

        $key = $this->generateRandomString(50);
        update_option("login_{$id}", $key);
        $expiration = time() + 60;
        update_option("login_expire_{$id}", $expiration);

        return array(
            "type" => "success",
            "url" => (isset($_SERVER['HTTPS']) ? "https" : "http")
                . "://{$_SERVER["HTTP_HOST"]}/wpuppy/login/{$id}/{$key}"
        );
    }

    public function resetHtaccess()
    {
        file_put_contents(
            dirname(dirname(dirname(dirname(__FILE__)))) . "/.htaccess",
            "# BEGIN WordPress
            <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteBase /
            RewriteRule ^index\.php$ - [L]
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule . /index.php [L]
            </IfModule>

            # END WordPress"
        );

        return array(
            "type" => "success",
            "message" => "Successfully resetted the htaccess file"
        );
    }

    public function createFileList($excludeList)
    {
        require_once(__DIR__ . "/lib/pclzip/pclzip.lib.php");

        global $wpdb;
        $this->createFileListTable();

        $tableName = $wpdb->prefix . "wpuppy_filelist";
        $wpdb->query("DELETE FROM $tableName;");

        if (empty($excludeList)) {
            $excludeList = array();
        }

        // Append the backup path
        $excludeList []= end(explode("/", content_url())) . "/backup";

        $existing = $this->scanDirRecursive(__DIR__ . "/backup", array());

        // Remove the old backup archives if any
        foreach ($existing as &$path) {
            unlink($path["name"]);
        }

        $filelist = $this->scanDirRecursive(ABSPATH, $excludeList);
        $count = count($filelist);

        // Insert into DB
        usort($filelist, function ($a, $b) {
            if ($a["size"] === $b["size"]) {
                return 0;
            }

            return $a["size"] < $b["size"] ? -1 : 1;
        });

        foreach ($filelist as &$file) {
            $file = "(\"" . $file["name"] . "\"," . $file["size"] . ")";
        }

        $filelist = implode(",", $filelist);
        $wpdb->get_results("INSERT INTO $tableName (file_path, file_size) VALUES {$filelist}");

        return array(
            "type" => "success",
            "message" => "Successfully generated file list",
            "count" => $count,
            "size" => (int) $wpdb->get_var("SELECT SUM(file_size) FROM $tableName"),
            "contentUrl" => content_url()
        );
    }

    public function backupStep()
    {
        require_once(__DIR__ . "/lib/pclzip/pclzip.lib.php");

        global $wpdb;
        $this->createFileListTable();

        $tableName = $wpdb->prefix . "wpuppy_filelist";
        $startTime = time();
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $tableName");

        // Check if the file list table is empty
        if ($count === null || ((int)$count) === 0) {
            return array(
                "type" => "error",
                "message" => "No files to backup"
            );
        }

        $archive = new PclZip(__DIR__ . "/backup/backup.zip");
        $done = false;
        $totalSize = 0;
        $processed = 0;

        // We only have 10s per request
        while (time() - $startTime < 5) {
            $currentFiles = $wpdb->get_results("SELECT * FROM $tableName LIMIT 10000;");

            if (empty($currentFiles)) {
                $done = true;
                break;
            }

            $files = array();
            $ids = array();
            $size = 0;

            foreach ($currentFiles as &$file) {
                $size += $file->file_size;
                $files []= $file->file_path;
                $ids []= $file->id;

                if (($totalSize + $size) >= 50000000 || ($processed + count($files)) > 4000) {
                    break;
                }
            }

            // No more files available
            if (count($files) === 0) {
                break;
            }

            // Delete the file from the list and add it to the zip file
            $ids = implode(",", $ids);
            $wpdb->query("DELETE FROM $tableName WHERE id IN ($ids)");

            if (!file_exists(__DIR__ . "/backup/backup.zip")) {
                $archive->create(
                    $files,
                    PCLZIP_OPT_REMOVE_PATH,
                    ABSPATH,
                    PCLZIP_OPT_NO_COMPRESSION
                );
            } else {
                $archive->add(
                    $files,
                    PCLZIP_OPT_REMOVE_PATH,
                    ABSPATH,
                    PCLZIP_OPT_NO_COMPRESSION
                );
            }

            $totalSize += $size;
            $processed += count($files);
        }

        $count = $wpdb->get_var("SELECT COUNT(*) FROM $tableName");
        if ($count === null || ((int)$count) === 0) {
            $done = true;
            $count = 0;
        }

        return array(
            "type" => "success",
            "done" => $done,
            "sizeLeft" => (int) $wpdb->get_var("SELECT SUM(file_size) FROM $tableName"),
            "filesProcessed" => $processed,
            "filesLeft" => (int) $count
        );
    }

    /*
     * Private functions
     */

    private function createFileListTable()
    {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        global $wpdb;
        $tableName = $wpdb->prefix . "wpuppy_filelist";
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $tableName (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            file_path text NOT NULL,
            file_size bigint(20) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        dbDelta($sql);
    }

    private function testFilesystem()
    {
        global $wp_filesystem;

        if (!$wp_filesystem->put_contents(
            $wp_filesystem->abspath()
            . "/test.txt",
            "test",
            FS_CHMOD_FILE
        )) {
            return false;
        }

        $wp_filesystem->delete($wp_filesystem->abspath() . "/test.txt");
        return true;
    }

    private function setupFilesystemAPI()
    {
        global $wp_filesystem;

        $this->setupFSMethod();
        require_once(ABSPATH . "wp-admin/includes/file.php");
        
        $credentials = array(
            "hostname" => (defined("FTP_HOST") ? FTP_HOST : ""),
            "username" => (defined("FTP_USER") ? FTP_USER : ""),
            "password" => (defined("FTP_PASS") ? FTP_PASS : "")
        );

        $altCredentials = array(
            "hostname" => (defined("FTP_HOST_ALT") ? FTP_HOST_ALT : ""),
            "username" => (defined("FTP_USER_ALT") ? FTP_USER_ALT : ""),
            "password" => (defined("FTP_PASS_ALT") ? FTP_PASS_ALT : "")
        );

        // try regular credentials
        if (!WP_Filesystem($credentials)) {
            if (!defined("FTP_USER_ALT")) {
                return false;
            }

            // try alt credentials
            if (!WP_Filesystem($altCredentials)) {
                return false;
            }
        }

        if (!$this->testFilesystem()) {
            $method = "ftpext";

            if (!class_exists("WP_Filesystem_$method")) {
                /**
                 * Filters the path for a specific filesystem method class file.
                 *
                 * @since 2.6.0
                 *
                 * @see get_filesystem_method()
                 *
                 * @param string $path   Path to the specific filesystem method class file.
                 * @param string $method The filesystem method to use.
                 */
                $abstraction_file = apply_filters(
                    "filesystem_method_file",
                    ABSPATH . "wp-admin/includes/class-wp-filesystem-" . $method . ".php",
                    $method
                );

                if (!file_exists($abstraction_file)) {
                    return false;
                }

                require_once($abstraction_file);
            }
            $method = "WP_Filesystem_$method";

            $wp_filesystem = new $method(
                array(
                    "hostname" => FTP_HOST, "username" => FTP_USER, "password" => FTP_PASS
                )
            );
            if (!$wp_filesystem->connect()) {
                if (!defined("FTP_HOST_ALT")) {
                    return false;
                }

                $wp_filesystem = new $method(
                    array(
                        "hostname" => FTP_HOST_ALT, "username" => FTP_USER_ALT, "password" => FTP_PASS_ALT
                    )
                );
                if (!$wp_filesystem->connect()) {
                    return false;
                }

                if (!$this->testFilesystem()) {
                    return false;
                }
            } elseif (!$this->testFilesystem()) {
                return false;
            }
        }

        return true;
    }

    private function getPluginFile($slug)
    {
        if (!function_exists("get_plugins")) {
            require_once(ABSPATH . "wp-admin/includes/plugin.php");
        }

        wp_clean_plugins_cache();
        wp_update_plugins();

        $plugins = get_plugins();

        foreach ($plugins as $file => $plugin) {
            if (strpos($file, "/") !== false) {
                $string = explode("/", $file);
                $plugin_slug = $string[0];
            } else {
                $plugin_slug = basename(
                    $file,
                    ".php"
                );
            }

            if ($plugin_slug === $slug) {
                return $file;
            }
        }

        return false;
    }

    private function setupFSMethod()
    {
        //Include the template file for submit_button etc
        require_once(ABSPATH . "/wp-admin/includes/template.php");
        require_once(ABSPATH . "/wp-admin/includes/file.php");
        $input = INPUT_POST;

        if (strtolower(get_filesystem_method()) === "direct") {
            return true;
        }

        if (defined("FTP_USER") && defined("FTP_PASS") && defined("FTP_HOST")) {
            return true;
        }

        if (filter_input(INPUT_POST, "username") === null) {
            if (filter_input(INPUT_GET, "username") === null) {
                echo json_encode(
                    array(
                        "type" => "error",
                        "message" => "FTP Details required"
                    )
                );
                exit;
            }

            $input = INPUT_GET;
        }

        if (!defined("FTP_HOST")) {
            define("FTP_HOST", filter_input($input, "hostname"));
        }

        if (!defined("FTP_USER")) {
            define("FTP_USER", filter_input($input, "username"));
        }

        if (!defined("FTP_PASS")) {
            define("FTP_PASS", filter_input($input, "password"));
        }
    }

    private function zipStatusString($status)
    {
        switch ((int)$status) {
            case ZipArchive::ER_OK:
                return "N No error";
            case ZipArchive::ER_MULTIDISK:
                return "N Multi-disk zip archives not supported";
            case ZipArchive::ER_RENAME:
                return "S Renaming temporary file failed";
            case ZipArchive::ER_CLOSE:
                return "S Closing zip archive failed";
            case ZipArchive::ER_SEEK:
                return "S Seek error";
            case ZipArchive::ER_READ:
                return "S Read error";
            case ZipArchive::ER_WRITE:
                return "S Write error";
            case ZipArchive::ER_CRC:
                return "N CRC error";
            case ZipArchive::ER_ZIPCLOSED:
                return "N Containing zip archive was closed";
            case ZipArchive::ER_NOENT:
                return "N No such file";
            case ZipArchive::ER_EXISTS:
                return "N File already exists";
            case ZipArchive::ER_OPEN:
                return "S Can\"t open file";
            case ZipArchive::ER_TMPOPEN:
                return "S Failure to create temporary file";
            case ZipArchive::ER_ZLIB:
                return "Z Zlib error";
            case ZipArchive::ER_MEMORY:
                return "N Malloc failure";
            case ZipArchive::ER_CHANGED:
                return "N Entry has been changed";
            case ZipArchive::ER_COMPNOTSUPP:
                return "N Compression method not supported";
            case ZipArchive::ER_EOF:
                return "N Premature EOF";
            case ZipArchive::ER_INVAL:
                return "N Invalid argument";
            case ZipArchive::ER_NOZIP:
                return "N Not a zip archive";
            case ZipArchive::ER_INTERNAL:
                return "N Internal error";
            case ZipArchive::ER_INCONS:
                return "N Zip archive inconsistent";
            case ZipArchive::ER_REMOVE:
                return "S Can\"t remove file";
            case ZipArchive::ER_DELETED:
                return "N Entry has been deleted";

            default:
                return sprintf("Unknown status %s", $status);
        }
    }

    private function unflagMaintenance()
    {
        global $wp_filesystem;
        if (!$this->setupFilesystemAPI()) {
            return false;
        }

        $folder = $wp_filesystem->abspath();
        if ($wp_filesystem->exists($folder . "/.maintenance")) {
            $wp_filesystem->delete($folder . "/.maintenance");
        }

        return true;
    }

    private function clearStoredResults($mysqli)
    {
        do {
            if ($res = $mysqli->store_result()) {
                $res->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());

    }

    private function splitSQL($sql, $maxSize = 50)
    {
        $num = 0;
        $checkpoint = 0;
        $checkpointNum = 0;
        $buffer = "";
        $data = array();

        $split = preg_split('/\r\n|\r|\n/', $sql);

        foreach ($split as &$line) {
            $line = trim($line);

            if (substr($line, 0, 2) === "--") {
                // comment

                $index1 = strpos($line, "Table structure for table");
                $index2 = strpos($line, "Dumping data for table");

                if ($index1 === false && $index2 === false) {
                    continue;
                }

                $checkpoint = strlen($buffer);
                $checkpointNum = $num;

                continue;
            }

            $num++;
            $buffer .= $line . "\n";

            if ($num === $maxSize) {
                $tmp = substr($buffer, 0, $checkpoint);
                $buffer = substr($buffer, $checkpoint);

                $data[] = $tmp;
                $num -= $checkpointNum;

                $checkpoint = 0;
                $checkpointNum = $num;
            }
        }

        if (!empty($buffer)) {
            $data[] = $buffer;
        }

        return $data;
    }

    private function generateRandomString($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private function scanDirRecursive($path, $excludeList)
    {
        $total = array();
        $path = rtrim($path, "/");

        $files = scandir($path);
        foreach ($files as $file) {
            if ($file === "." || $file === "..") {
                continue;
            }

            $newPath = $path . "/" . $file;
            $newPath = rtrim($newPath, "/");

            // Check if this file/directory is excluded
            $base = substr($newPath, strlen(ABSPATH));
            if (array_search($base, $excludeList) !== false || array_search("/" . $base, $excludeList) !== false) {
                continue;
            }

            if (!is_dir($newPath)) {
                $total []= array(
                    "name" => $newPath,
                    "size" => filesize($newPath)
                );
                continue;
            }

            $extra = $this->scanDirRecursive($newPath, $excludeList);
            $total = array_merge($total, $extra);
        }

        return $total;
    }
}

global $wpuppy;
$wpuppy = new WPuppy();
