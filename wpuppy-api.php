<?php
namespace WPuppy;

if (!defined('ABSPATH')) {
    die('No script kiddies please');
}

require_once(__DIR__ . "/traits/wpuppy-api.php");

class WPuppyApi extends ApiCalls
{
    private $key;
    private $action;

    public function __construct()
    {
        error_reporting(E_ALL);
        header('Content-type: application/json; charset=utf-8');
        header("access-control-allow-origin: *");
        
        if (!defined("WP_DEBUG_DISPLAY")) {
            define('WP_DEBUG_DISPLAY', false);
        }
        
        if (!defined("WP_DEBUG")) {
            //Turn off debug logging
            define("WP_DEBUG", false);
        }

        set_error_handler(array($this, "handleError"));
        register_shutdown_function(array($this, "handleShutdown"));
    }

    private function checkApiKey()
    {
        global $wpuppy;

        if (!$wpuppy->checkApiKey($this->key)) {
            return false;
        }

        return true;
    }

    public function setup()
    {
        if (($this->key = filter_input(INPUT_GET, "key")) === null) {
            return false;
        }

        $this->action = filter_input(INPUT_GET, "action");

        return $this->checkApiKey();
    }

    public function run()
    {
        if (!$this->setup()) {
            return $this->error("Api key is invalid");
        }

        if (!is_callable(array($this, $this->action))) {
            return $this->error("Unrecognized action");
        }

        $response = call_user_func(array($this, $this->action));
        return $this->respond($response);
    }

    public function respond($response)
    {
        return json_encode($response);
    }

    public function error($response)
    {
        return json_encode(
            array(
                "type" => "error",
                "message" => $response
            )
        );
    }

    public function handleError($errNo, $errStr, $errFile, $errLine, $errContext)
    {
        if ($errNo === E_USER_ERROR || $errNo === E_ERROR) {
            die(
                json_encode(
                    array(
                        "type" => "error",
                        "message" => $errStr,
                        "file" => $errFile,
                        "line" => $errLine,
                        "context" => $errContext
                    )
                )
            );
        }

        return true;
    }

    public function handleShutdown() {
        $isError = false;
    
        if ($error = error_get_last()) {
            switch($error["type"]) {
                case E_ERROR:
                case E_CORE_ERROR:
                case E_COMPILE_ERROR:
                case E_USER_ERROR:
                    $isError = true;
                    break;
            }
        }
    
        if ($isError) {
            die(
                json_encode(
                    array(
                        "type" => "error",
                        "message" => $error["message"],
                        "file" => $error["file"],
                        "line" => $error["line"]
                    )
                )
            );
        }

        return true;
    }
}

$api = new WPuppyApi();
echo $api->run();
exit;
