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
    1. Detect core name
    2. Define main paths (for configuration, files, data folder, sessions path)
    3. Read platform config.ini file
    4. read core config.ini & system.ini files
    5. based on loaded configs set casebox php options, session lifetime, error_reporting and define required casebox constants

*/

/* detecting core name (project name) from SERVER_NAME */
$arr = explode('.', $_SERVER['SERVER_NAME']);
// remove www, ww2 and take the next parameter as the $coreName
if (in_array($arr[0], array( 'www', 'ww2' ))) {
    array_shift($arr);
}

define('CB\\CORENAME', $arr[0]);
/* end of detecting core name (project name) from SERVER_NAME */

/* define main paths /**/
define('CB\\DOC_ROOT', dirname(__FILE__).DIRECTORY_SEPARATOR);
define('CB\\APP_ROOT', dirname(dirname(__FILE__)).DIRECTORY_SEPARATOR);
define('CB\\CORE_ROOT', DOC_ROOT.'cores'.DIRECTORY_SEPARATOR.CORENAME.DIRECTORY_SEPARATOR);
define('CB\\CRONS_DIR', APP_ROOT.'sys'.DIRECTORY_SEPARATOR.'crons'.DIRECTORY_SEPARATOR);
define('CB\\LOGS_DIR', APP_ROOT.'logs'.DIRECTORY_SEPARATOR);
define('CB\\DATA_DIR', APP_ROOT.'data'.DIRECTORY_SEPARATOR);
define('CB\\TEMP_DIR', DATA_DIR.'tmp'.DIRECTORY_SEPARATOR);
define('CB\\UPLOAD_TEMP_DIR', TEMP_DIR.CORENAME.DIRECTORY_SEPARATOR);
define('CB\\MINIFY_CACHE_DIR', TEMP_DIR.'minify'.DIRECTORY_SEPARATOR);
/* end of define main paths /**/

if (!file_exists(CORE_ROOT)) {
    die('undefined core "'.CORENAME.'"');
}

/* update include_path and include global script */
set_include_path(
    DOC_ROOT.'libx'.PATH_SEPARATOR.
    DOC_ROOT.'libx'.DIRECTORY_SEPARATOR.'min'.DIRECTORY_SEPARATOR.'lib'. PATH_SEPARATOR.
    DOC_ROOT.'classes'.PATH_SEPARATOR.
    CORE_ROOT.'php'. PATH_SEPARATOR.
    get_include_path()
);

include 'global.php';

/* end of update include_path and include global script */

/* Reading platform system.ini file and define all parameters in main namespace*/
$filename = DOC_ROOT.'system.ini';
if (file_exists($filename)) {
    $arr = parse_ini_file($filename);
    if (is_array($arr)) {
        foreach ($arr as $key => $value) {
            if ((substr($value, 0, 2) == '\\\\') || (substr($value, 0, 2) == '//')) {
                $value = DOC_ROOT.substr($value, 2);
            }
            define('CB\\'.strtoupper($key), $value);
        }
    }
}
/* end of Reading platform system.ini file */

// define default config for Casebox
$config = array();
$filename = DOC_ROOT.'config.ini';
if (file_exists($filename)) {
    $config = array_merge($config, parse_ini_file($filename));
}

/* reading core config.ini merging values to config*/
$filename = CORE_ROOT.'config.ini';
if (file_exists($filename)) {
    $config = array_merge($config, parse_ini_file($filename));
}

/* read and apply platform config from DB and define platform languages */
if (!empty($config)) {

    require_once 'lib/DB.php';
    DB\connect($config);

    $platform_config = getPlatformDBConfig();
    foreach ($platform_config as $k => $v) {
        if (( strlen($k) == 11 ) && ( substr($k, 0, 9) == 'language_')) {
            $GLOBALS['language_settings'][substr($k, 9)] = json_decode($v, true);
        } else {
            $config[$k] = $v;
        }
    }

    /* Define Casebox available languages */
    define('CB\\LANGUAGES', implode(',', array_keys($GLOBALS['language_settings'])));

    /* read and apply core config from DB */
    $core_config = getCoreDBConfig();
    foreach ($core_config as $k => $v) {
        if ((strlen($k) == 11) && (substr($k, 0, 9) == 'language_')) {
            $GLOBALS['language_settings'][substr($k, 9)] = json_decode($v, true);
        } else {
            $config[$k] = $v;
        }
    }
}

/* Define folder templates */

if (!empty($config['folder_templates'])) {
    $GLOBALS['folder_templates'] = explode(',', $config['folder_templates']);
    unset($config['folder_templates']);
} else {
    $GLOBALS['folder_templates'] = array();
}

if (empty($config['default_folder_template'])) {
    $config['default_folder_template'] = empty($GLOBALS['folder_templates']) ? 0 : $GLOBALS['folder_templates'][0];
}

if (empty($config['default_file_template'])) {
    $res = DB\dbQuery(
        'SELECT id
        FROM templates
        WHERE `type` = $1',
        'file'
    ) or die( DB\dbQueryError() );

    if ($r = $res->fetch_assoc()) {
        $config['default_file_template'] = $r['id'];
    } else {
        $config['default_file_template'] = 0;
    }

    $res->close();
}

/* store fetched config in CB\CONFIG namespace /**/
foreach ($config as $k => $v) {
    define('CB\\CONFIG\\'.strtoupper($k), $v);
}

/* Define Core available languages in $GLOBALS */
if (defined('CB\\CONFIG\\LANGUAGES')) {
    $GLOBALS['languages'] = explode(',', CONFIG\LANGUAGES);
    for ($i=0; $i < sizeof($GLOBALS['languages']); $i++) {
        $GLOBALS['languages'][$i] = trim($GLOBALS['languages'][$i]);
    }
}

if (defined('CB\\CONFIG\\MAX_FILES_VERSION_COUNT')) {
    __autoload('CB\\Files');
    Files::setMFVC(CONFIG\MAX_FILES_VERSION_COUNT);
}
/* end of store fetched config in CB\CONFIG namespace /**/

/* So, we have defined main paths and loaded configs. Now define and configure all other options (for php, session, etc) */

/* setting php configuration options, session lifetime and error_reporting level */
ini_set('max_execution_time', 300);
ini_set('short_open_tag', 'off');

// upload params
ini_set('upload_tmp_dir', UPLOAD_TEMP_DIR);
ini_set('upload_max_filesize', '200M');
ini_set('post_max_size', '200M');
ini_set('max_file_uploads', '20');
ini_set('memory_limit', '200M');

// session params

$sessionLifetime = getOption('session.lifetime');
if (is_null($sessionLifetime)) {
    $sessionLifetime = isDebugHost() ? 0: 43200;
} else {

}

ini_set("session.gc_maxlifetime", $sessionLifetime);
ini_set("session.gc_divisor", "100");
ini_set("session.gc_probability", "1");

session_set_cookie_params($sessionLifetime, '/', $_SERVER['SERVER_NAME'], !empty($_SERVER['HTTPS']), true);
session_name(
    str_replace(
        array(
            '.casebox.org'
            ,'.'
            ,'-'
        ),
        '',
        $_SERVER['SERVER_NAME']
    )
);

//error reporting params
error_reporting(isDebugHost() ? E_ALL : 0);
ini_set('error_log', LOGS_DIR.CORENAME.'_error_log');

// mb encoding config
mb_internal_encoding("UTF-8");
mb_detect_order('UTF-8,UTF-7,ASCII,EUC-JP,SJIS,eucJP-win,SJIS-win,JIS,ISO-2022-JP,WINDOWS-1251,WINDOWS-1250');
mb_substitute_character("none");

// timezone
date_default_timezone_set(
    empty($config['timezone'])
    ?
    'UTC'
    :
    $config['timezone']
);

/* end of setting php configuration options, session lifetime and error_reporting level */

/* define other constants used in casebox */

//relative path to ExtJs framework. Used in index.php
const EXT_PATH = '/libx/ext';
//templates folder. Basicly used for email templates. Used in Tasks notifications and password recovery processes.
define('CB\\TEMPLATES_DIR', APP_ROOT.'sys'.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR);

//used to include DB.php into PreviewExtractor scripts and in Files.php to start the extractors.
define('CB\\LIB_DIR', DOC_ROOT.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR);

// Default row count limit used for solr results
if (!defined('CB\\CONFIG\\MAX_ROWS')) {
    define('CB\\CONFIG\\MAX_ROWS', 50);
}

// custom Error log per Core, use it for debug/reporting purposes
define('DEBUG_LOG', LOGS_DIR.'cb_'.CORENAME.'_debug_log');

// define solr_core as db_name if none is specified in config
if (!defined('CB\\CONFIG\\SOLR_CORE')) {
    define('CB\\CONFIG\\SOLR_CORE', '/solr/'.CONFIG\DB_NAME);
}

// path to photos folder
define('CB\\PHOTOS_PATH', DOC_ROOT.'photos'.DIRECTORY_SEPARATOR.CORENAME.DIRECTORY_SEPARATOR);
// path to files folder
define('CB\\FILES_DIR', DATA_DIR.'files'.DIRECTORY_SEPARATOR.CORENAME.DIRECTORY_SEPARATOR);

/* path to incomming folder. In this folder files are stored when just uploaded
and before checking existance in target.
If no user intervention is required then files are stored in db. */
define('CB\\INCOMMING_FILES_DIR', UPLOAD_TEMP_DIR.'incomming'.DIRECTORY_SEPARATOR);
/* path to preview folder. Generated previews are stored for some filetypes */
define('CB\\FILES_PREVIEW_DIR', FILES_DIR.'preview'.DIRECTORY_SEPARATOR);

// define default core language constant
define(
    'CB\\LANGUAGE',
    (defined('CB\\CONFIG\\DEFAULT_LANGUAGE')
        ? CONFIG\DEFAULT_LANGUAGE
        : $GLOBALS['languages'][0]
    )
);
/* USER_LANGUAGE is defined after starting session */

/* config functions section */

/**
 * Check server side operation system
 */
function isWindows()
{
    return (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN');
}

/**
 * Get platform config from database
 */
function getPlatformDBConfig()
{
    $rez = array();
    $res = DB\dbQuery(
        'SELECT param
            ,`value`
        FROM casebox.config
        WHERE pid IS NOT NULL'
    ) or die( DB\dbQueryError() );

    while ($r = $res->fetch_assoc()) {
        $rez[$r['param']] = $r['value'];
    }
    $res->close();

    return $rez;
}

/**
 * Get core config from database
 */
function getCoreDBConfig()
{
    $rez = array();
    $res = DB\dbQuery(
        'SELECT param
            ,`value`
        FROM config'
    ) or die( DB\dbQueryError() );

    while ($r = $res->fetch_assoc()) {
        $rez[$r['param']] = $r['value'];
    }
    $res->close();

    return $rez;
}

/**
 * Get custom core config for css, js, listeners
 */
function getCustomConfig()
{
    $customConfig = array();
    if (is_file(CORE_ROOT.'config.php')) {
        $customConfig = (require CORE_ROOT.'config.php');
    }

    return $customConfig;
}

/**
 * returns true if scripts run on a Devel server
 * @return boolean
 */
function isDevelServer()
{
    return (
        (strpos($_SERVER['SERVER_NAME'], '.d.') !== false)
        || ($_SERVER['SERVER_ADDR'] == '46.165.252.15')
    );
}

/**
 * Check if the client machine is debuging host
 * @return boolean
 */
function isDebugHost()
{
    return (
        empty($_SERVER['SERVER_NAME'])
        ||
        in_array(
            $_SERVER['REMOTE_ADDR'],
            array(
                'localhost'
                ,'127.0.0.1'
                ,'195.22.253.6'
                ,'193.226.64.181'
                ,'188.240.73.107'
                ,'92.115.133.211'
            )
        )
    );
}

function debug($msg)
{
    error_log($msg."\n", 3, DEBUG_LOG);
}
/**
 * Fire server side event
 *
 * This function calls every defined listener for fired event
 */
function fireEvent($eventName, &$params)
{
    $cfg = getCustomConfig();
    if (empty($cfg['listeners'][$eventName])) {
        return;
    }
    foreach ($cfg['listeners'][$eventName] as $className => $methods) {
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
                debug('Event Exception for '.$class.'->'.$method);
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
function getOption($optionName)
{
    if (!empty($_SESSION['user']['cfg'][$optionName])) {
        return $_SESSION['user']['cfg'][$optionName];
    }
    if (defined('CB\\CONFIG\\'.mb_strtoupper($optionName))) {
        return constant('CB\\CONFIG\\'.mb_strtoupper($optionName));
    }
    if (!empty($GLOBALS[$optionName])) {
        return $GLOBALS[$optionName];
    }

    return null;
}
