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

global $phase;
$phase = filter_input(INPUT_GET, "phase", FILTER_DEFAULT, FILTER_REQUIRE_SCALAR);

switch ($phase)
{
    // Phase 1: download backup
    case 0:
        downloadBackup();
        break;

    // Phase 2: restore backup
    case 1:
        restoreBackup();
        break;

    // Phase 3: restore database
    case 2:
        restoreDatabase();
        break;
}

function downloadBackup()
{
    if (!isset($_POST["file_data"]) || !isset($_POST["file_size"])) {
        finish("error", "No file data provided");
    }

    $size = filter_input(INPUT_POST, "file_size", FILTER_DEFAULT, FILTER_REQUIRE_SCALAR);
    $remove = filter_input(INPUT_POST, "remove", FILTER_DEFAULT, FILTER_REQUIRE_SCALAR);
    $filename = "backup.zip";

    if ($_GET["type"] === "wpuppy") {
        $filename = "wpuppy.zip";
    }

    if ((int)$remove === 1) {
        @unlink(__DIR__ . "/" . $filename);
    }

    $data = base64_decode($_POST["file_data"]);
    $fd = fopen($filename, "ab");

    if (!$fd) {
        finish("error", "Failed to open file");
    }

    fwrite($fd, $data, $size);
    fclose($fd);

    finish("success", filesize($filename));
}

function restoreBackup()
{
    //Load pclzip
    require_once(__DIR__ . "/../lib/pclzip/pclzip.lib.php");

    $index = filter_input(INPUT_GET, "index", FILTER_DEFAULT, FILTER_REQUIRE_SCALAR);
    $startTime = time();

    if (empty($index) || $index === 0) {
        // starting
        $index = 0;
    }

    $filename = "backup.zip";

    if ($_GET["type"] === "wpuppy") {
        $filename = "wpuppy.zip";
    }

    $zip = new PclZip(__DIR__ . "/" . $filename);
    $files = $zip->listContent();
    $files = array_map(function ($a) {
        return $a["filename"];
    }, $files);

    while (time() - $startTime <= 10 && $index < count($files)) {
        $fileList = array_slice($files, $index, 10);

        if ($_GET["type"] === "wpuppy") {
            $zip->extract(
                PCLZIP_OPT_BY_NAME,
                $fileList,
                PCLZIP_OPT_REMOVE_PATH,
                "wpuppy/",
                PCLZIP_OPT_PATH,
                realpath(__DIR__ . "/../"),
                PCLZIP_OPT_REPLACE_NEWER
            );
        } else {
            $zip->extract(PCLZIP_OPT_BY_NAME, $fileList, PCLZIP_OPT_PATH, ABSPATH, PCLZIP_OPT_REPLACE_NEWER);
        }

        $index += 10;
    }

    $done = $index >= count($files);

    if ($done) {
        unlink($filename);
    }

    finish(
        "success",
        array(
            "index" => $index,
            "done"  => $done,
            "remaining" => max(count($files) - $index, 0)
        )
    );
}

function restoreDatabase()
{
    //Check if backup file was uploaded by the worker
    if (!file_exists(ABSPATH . "/backup.sql")) {
        finish("error", "Backup was not found.");
    }

    //Get the WPDB object from Wordpress
    global $wpdb;

    //Get the content of the backup file, uploaded by the worker
    $backup = file_get_contents(ABSPATH . "/backup.sql");
    $backup = splitSQL($backup);

    //Get the connection data
    $host = $wpdb->dbhost;

    $mysqli = mysqli_init();
    $mysqli->options(MYSQLI_READ_DEFAULT_GROUP, "max_allowed_packet=100M");

    //If a port has been defined, split them up and use the port to connect
    if (strpos($host, ":") !== false) {
        $host = explode(":", $host);
        $port = $host[1];
        $host = $host[0];

        if (empty($host)) {
            $host = "localhost";
        }

        $mysqli->real_connect($host, $wpdb->dbuser, $wpdb->dbpassword, $wpdb->dbname, $port);
    } else {
        if (empty($host)) {
            $host = "localhost";
        }

        $mysqli->real_connect($host, $wpdb->dbuser, $wpdb->dbpassword, $wpdb->dbname);
    }

    //If an error occurs, we're in serious trouble'
    if ($mysqli->connect_error) {
        finish("error", "({$mysqli->connect_errno}) {$mysqli->connect_error}");
    }

    foreach ($backup as &$query) {
        // If we can't seem to fully run the query, we're screwed D:
        if (!$mysqli->multi_query($query)) {
            finish("error", $mysqli->error);
        }

        clearStoredResults($mysqli);
    }

    finish("success", "Restored database");
}

function clearStoredResults($mysqli)
{
    do {
        if ($res = $mysqli->store_result()) {
            $res->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());
}

function splitSQL($sql, $maxSize = 50)
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

/**
 * Finish the reactivation process
 *
 * @param string $code    "success" or "error"
 * @param mixed $message message you want to pass
 * @return void
 */
function finish($code, $message)
{
    global $phase;
    if ($phase === 2) {
        //Unlink the backup sql file
        unlink(ABSPATH . "/backup.sql");
    }

    $result = json_encode(array(
        $code => $message
    ));

    die($result);
}
