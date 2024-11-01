<?php
namespace WPuppy;

class ApiCalls
{
    public function update_plugins()
    {
        global $wpuppy;

        $slugs = filter_input(
            INPUT_POST,
            "slugs",
            FILTER_DEFAULT,
            FILTER_REQUIRE_ARRAY
        );

        if (empty($slugs)) {
            $slugs = filter_input(
                INPUT_GET,
                "slugs",
                FILTER_DEFAULT,
                FILTER_REQUIRE_ARRAY
            );
        }

        if (empty($slugs)) {
            return array(
                "type" => "error",
                "message" => "No plugins were specified"
            );
        }

        return $wpuppy->updatePlugins($slugs);
    }

    public function update_wordpress()
    {
        global $wpuppy;

        return $wpuppy->updateWordpress();
    }

    public function update_themes()
    {
        global $wpuppy;

        $slugs = filter_input(
            INPUT_POST,
            "slugs",
            FILTER_DEFAULT,
            FILTER_REQUIRE_ARRAY
        );

        if (empty($slugs)) {
            $slugs = filter_input(
                INPUT_GET,
                "slugs",
                FILTER_DEFAULT,
                FILTER_REQUIRE_ARRAY
            );
        }

        if (empty($slugs)) {
            return json_encode(
                array(
                    "type" => "error",
                    "message" => "No themes were specified"
                )
            );
        }

        return $wpuppy->updateThemes($slugs);
    }

    public function delete_cache()
    {
        global $wpuppy;

        return $wpuppy->deleteCache();
    }

    /*
     * GET Functions
     * get_plugins: Gets a list of plugins of this website
     * get_themes: Gets a list of themes of this website
     * get_sitemap: Generate a sitemap list of this website
     * check_updates: Checks the website on any updates available
     */
    public function get_plugins()
    {
        global $wpuppy;

        return $wpuppy->getPluginList();
    }

    public function get_themes()
    {
        global $wpuppy;

        return $wpuppy->getThemesList();
    }

    public function get_sitemap()
    {
        global $wpuppy;

        return $wpuppy->getSitemap();
    }

    public function check_updates()
    {
        global $wpuppy;

        $plugins = $wpuppy->listPluginUpdates();
        $wordpress = $wpuppy->listCoreUpdates();
        $themes = $wpuppy->listThemeUpdates();
        $requirements = $wpuppy->getRequirements();

        if (empty($plugins) && empty($wordpress) && empty($themes)) {
            echo json_encode(
                array(
                    "type" => "uptodate",
                    "data" => $wpuppy->getPluginData(),
                    "requirements" => $requirements
                )
            );
        }

        //Remove empty arrays
        if (empty($plugins)) {
            unset($plugins);
        }

        if (empty($themes)) {
            unset($themes);
        }

        if (empty($wordpress)) {
            unset($wordpress);
        }

        echo json_encode(
            array(
                "type" => "notuptodate",
                "data" => $wpuppy->getPluginData(),
                "updates" => array(
                    "core" => $wordpress ? : 'up-to-date',
                    "plugins" => $plugins ? : 'up-to-date',
                    "themes" => $themes ? : 'up-to-date'
                ),
                "requirements" => $requirements
            )
        );
    }

    public function get_versions()
    {
        global $wp_version, $wpuppy;

        if (empty($wp_version)) {
            $wp_version = get_bloginfo("version");
        }

        echo json_encode(
            array(
                "plugins" => $wpuppy->getPluginList(),
                "themes" => $wpuppy->getThemesList(),
                "wordpress" => $wp_version
            )
        );
    }

    /*
     * PUT Functions
     * backupDatabase: Backups the database and puts a file in the root
     */
    public function backup_database()
    {
        global $wpuppy;

        return $wpuppy->backupDatabase();
    }

    public function cleanup_database()
    {
        global $wpuppy;

        return $wpuppy->backupDatabaseClean();
    }

    public function restore_database()
    {
        global $wpuppy;

        return $wpuppy->restoreDatabase();
    }

    public function reset_htaccess()
    {
        global $wpuppy;

        return $wpuppy->resetHtaccess();
    }

    public function generate_oneclick()
    {
        global $wpuppy;

        $user = filter_input(INPUT_POST, "user");

        if (empty($user)) {
            $user = filter_input(INPUT_GET, "user");
        }

        if (empty($user)) {
            echo json_encode(
                array(
                    "type" => "error",
                    "message" => "No user was specified"
                )
            );
        }
        return $wpuppy->generateOneClick($user);
    }

    public function backup_generate_filelist()
    {
        global $wpuppy;

        $excludeList = filter_input(INPUT_POST, "exclude");

        if (empty($excludeList)) {
            $excludeList = array();
        } else {
            $excludeList = json_decode($excludeList);
        }

        return $wpuppy->createFileList($excludeList);
    }

    public function backup_execute_step()
    {
        global $wpuppy;

        return $wpuppy->backupStep();
    }

    public function get_content_url()
    {
        return array(
            "url" => content_url()
        );
    }
}