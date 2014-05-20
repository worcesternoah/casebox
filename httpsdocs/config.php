<?php
/**
*	configuration file
*	@author Țurcanu Vitalie <vitalie.turcanu@gmail.com>
*	@access private
*	@package CaseBox
*	@copyright Copyright (c) 2013, HURIDOCS, KETSE
*	@version 2.0 refactoring 17 april 2013. Introduce CB namespace for casebox platform scripts
**/
namespace CB;

/*
    steps:
    1. Define main paths (for configuration, files, data folder, sessions path)
    2. Read platform config.ini file
    3. read core config.ini & system.ini files
    4. Detect core name and initialize by defining specific params
    5. based on loaded configs set casebox php options, session lifetime, error_reporting and define required casebox constants

*/

/* define main paths/**/
define('CB\\DOC_ROOT', dirname(__FILE__).DIRECTORY_SEPARATOR);
define('CB\\APP_DIR', dirname(dirname(__FILE__)).DIRECTORY_SEPARATOR);
define('CB\\PLUGINS_DIR', DOC_ROOT.'plugins'.DIRECTORY_SEPARATOR);
define('CB\\CRONS_DIR', APP_DIR.'sys'.DIRECTORY_SEPARATOR.'crons'.DIRECTORY_SEPARATOR);
define('CB\\LOGS_DIR', APP_DIR.'logs'.DIRECTORY_SEPARATOR);
define('CB\\DATA_DIR', APP_DIR.'data'.DIRECTORY_SEPARATOR);
define('CB\\TEMP_DIR', DATA_DIR.'tmp'.DIRECTORY_SEPARATOR);
define('CB\\MINIFY_CACHE_DIR', TEMP_DIR.'minify'.DIRECTORY_SEPARATOR);
//templates folder. Basicly used for email templates. Used in Tasks notifications and password recovery processes.
define('CB\\TEMPLATES_DIR', APP_DIR.'sys'.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR);
//used to include DB.php into PreviewExtractor scripts and in Files.php to start the extractors.
define('CB\\LIB_DIR', DOC_ROOT.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR);

// define casebox include path
// This path contains only CaseBox platform inclusion paths
//  and do not contain core specific paths
define(
    'CB\\INCLUDE_PATH',
    DOC_ROOT.'libx'.PATH_SEPARATOR.
    DOC_ROOT.'libx'.DIRECTORY_SEPARATOR.'min'.DIRECTORY_SEPARATOR.'lib'. PATH_SEPARATOR.
    DOC_ROOT.'classes'.PATH_SEPARATOR.
    PLUGINS_DIR.PATH_SEPARATOR.
    get_include_path()
);
/* end of define main paths /**/

/* update include_path and include global script */
set_include_path(INCLUDE_PATH);

include 'global.php';
/* end of update include_path and include global script */

//load main config so that we can connect to casebox db and read configuration for core
$cfg = Config::loadConfigFile(DOC_ROOT.'config.ini');

require_once 'lib/DB.php';
DB\connect($cfg);

//get platform default config
$cfg = array_merge($cfg, Config::loadConfigFile(DOC_ROOT.'system.ini'));

//detect core and define core specific params
$cfg['core_name'] = detectCore() or die('Cannot detect core');

//set default database name
$cfg['db_name'] = 'cb_' . $cfg['core_name'];

//loading core defined params
$cfg = array_merge($cfg, Config::getPlatformConfigForCore($cfg['core_name']));

DB\connectWithParams($cfg);

//loading full config of the core
require_once 'lib/Util.php';
$config = Config::load($cfg);

//connect other database if specified in config for core
DB\connectWithParams($config);

/**
*   So, we have defined main paths and loaded configs.
*   Now define and configure all other options (for php, session, etc)
**/

/* setting php configuration options, session lifetime and error_reporting level */
ini_set('max_execution_time', 300);
ini_set('short_open_tag', 'off');

// upload params
ini_set('upload_max_filesize', '200M');
ini_set('post_max_size', '200M');
ini_set('max_file_uploads', '20');
ini_set('memory_limit', '400M');

// session params
$sessionLifetime = (
    isDebugHost()
        ? 0
        : Config::get('session.lifetime', 180)
) * 60;

ini_set("session.gc_maxlifetime", $sessionLifetime);
ini_set("session.gc_divisor", "100");
ini_set("session.gc_probability", "1");

session_set_cookie_params($sessionLifetime, '/' . $cfg['core_name'] . '/', $_SERVER['SERVER_NAME'], !empty($_SERVER['HTTPS']), true);
session_name(
    str_replace(
        array(
            '.casebox.org'
            ,'.'
            ,'-'
        ),
        '',
        $_SERVER['SERVER_NAME']
    ).$cfg['core_name']
);

//error reporting params
error_reporting(isDebugHost() ? E_ALL : 0);

// mb encoding config
mb_internal_encoding("UTF-8");
mb_detect_order('UTF-8,UTF-7,ASCII,EUC-JP,SJIS,eucJP-win,SJIS-win,JIS,ISO-2022-JP,WINDOWS-1251,WINDOWS-1250');
mb_substitute_character("none");

// timezone
date_default_timezone_set('UTC');

/* end of setting php configuration options, session lifetime and error_reporting level */

/* define other constants used in casebox */

//relative path to ExtJs framework. Used in index.php
define('CB\\EXT_PATH', '/libx/ext');

//clear debug_log for each request when on debug host
if (isDebugHost()) {
    @unlink(Config::get('debug_log'));
}

/* config functions section */

/**
 * Check server side operation system
 */
function isWindows()
{
    return (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN');
}

/**
 * returns true if scripts run on a Devel server
 * @return boolean
 */
function isDevelServer()
{
    return (
        (strpos($_SERVER['SERVER_NAME'], '.d.') !== false) ||
        Config::isInListValue('devel_hosts', $_SERVER['REMOTE_ADDR'])
    );
}

/**
 * Check if the client machine is debuging host
 * @return boolean
 */
function isDebugHost()
{
    return (
        empty($_SERVER['SERVER_NAME']) ||
        Config::isInListValue('debug_hosts', $_SERVER['REMOTE_ADDR'])
    );
}

/**
 * detect core from enviroment
 * @return varchar | false
 */
function detectCore()
{
    $rez = false;

    if (isset($_GET['core'])) {
        $rez = $_GET['core'];
    } else {
        $arr = explode('.', $_SERVER['SERVER_NAME']);
        // remove www, ww2 and take the next parameter as the $coreName
        if (in_array($arr[0], array( 'www', 'ww2' ))) {
            array_shift($arr);
        }
        $arr = explode('-', $arr[0]);
        if (in_array($arr[sizeof($arr)-1], array('local', 'd'))) {
            array_pop($arr);
        }
        $arr = implode('-', $arr);
        $arr = explode('_', $arr);

        $rez = $arr[0];
    }

    return $rez;
}

/**
 * debug message to DBUG_LOG file
 * @param  variant $msg
 * @return void
 */
function debug($msg)
{
    if (!is_scalar($msg)) {
        $msg = var_export($msg, 1);
    }

    $debugFile = Config::get('debug_log');

    if (empty($debugFile)) {
        $debugFile = LOGS_DIR.'cb_debug_log';
    }
    // echo $debugFile;
    error_log(date('Y-m-d H:i:s').': '.$msg."\n", 3, $debugFile);
}

/**
 * Fire server side event
 *
 * This function calls every defined listener for fired event
 */
function fireEvent($eventName, &$params)
{
    //skip trigering events from other triggers
    if (!empty($GLOBALS['running_trigger'])) {
        return;
    }

    $listeners = Config::getListeners();
    if (empty($listeners[$eventName])) {
        return;
    }

    foreach ($listeners[$eventName] as $className => $methods) {
        $className = str_replace('_', '\\', $className);
        $class = new $className();
        if (!is_array($methods)) {
            $methods = array($methods);
        }
        foreach ($methods as $method) {
            $GLOBALS['running_trigger'] = true;
            try {
                $class->$method($params);
            } catch (\Exception $e) {
                debug(
                    'Event Exception for '.$className.'->'.$method."\n".
                    $e->getMessage()."\n".
                    $e->getTraceAsString()
                );
            }
            unset($GLOBALS['running_trigger']);
        }
        unset($class);
    }
}

/**
 * get an option value from config
 *
 * config options could be defined in:
 *     user config
 *     core config
 *     default casebox config
 *
 * user config is stored in session
 *
 * default casebox config is merged with core config file and
 *     with database configuration values from config table
 * The meged result is declared in CB\CONFIG namespace
 *
 * there are also some configuration variables stored in $GLOBALS
 * (because there are no scalar values) like:
 *    language_settings - settings if defined for each language
 *    folder_templates - array of folder templates
 *    languages - avalilable languages for core
 *
 * so the value of specified option is returned from first config where is defined
 *     user config form session
 *     merged config from CB\CONFIG namespace
 *     $GLOBALS
 * If not defined in any config then null is returned
 *
 * @param  varchar $optionName name of the option to get
 * @return variant | null
 */
function getOption($optionName, $defaultValue = null)
{
    if (!empty($_SESSION['user']['cfg'][$optionName])) {
        return $_SESSION['user']['cfg'][$optionName];
    }

    return Config::get($optionName, $defaultValue);
}
