<?php
/**
 * This file contains global settings,
 * pseudo-constant variables and utility functions.
 *
 * Some of the settings and "constants" can be overridden in config.php.
 */


/**
 * Global settings
 */
error_reporting(E_STRICT | E_ALL ^ E_NOTICE);
date_default_timezone_set('Europe/Tallinn');


/**
 * Class autoloader
 */
function __autoload($class_name)
{
    require_once($class_name . '.php');
}


/**
 * Pseudo-constants
 */
$PROG_ID = 'wLex 4.1.2';
$NOTICE = '© 2002-2010 <a href="http://peeterpaul.motskula.net/">Peeter P. Mõtsküla</a>';
$HOME = substr($_SERVER['PHP_SELF'], 0, -10);
$TITLE = 'wLex';
$TAGLINE = ' - parem (kui) Riigi Teataja';
$BANNER = '';
$ERT_HOME = 'https://www.riigiteataja.ee/ert';
$ACTS_DB = 'data/acts.db';
$SCAT_DB = 'data/scat.db';
$ABBR_DB = 'abbr.db';
$CACHE_DB = 'data/cache.sqlite';
$MANPAGE = 'man.htm';
$SITEMAP = 'sitemap.xml';
$NUMA   = "[0-9]+";
$NUMR   = "[IVXLCDM]+";
$NSUP   = "(?:<sup>$NUMA</sup>)";
$SNUM   = "(?:$NUMA$NSUP?|$NUMR$NSUP?)";
$BR     = "(?:<br(?: /)?>)";
$PBR    = "(?:$BR|</p>)";
$SSEP   = "(?:\. ?)?(?:\. |$BR\n|(?:</p>\n<p>))";
$PARA   = "(?:§ ?|Paragrahv ?)";
$DATE   = "(?:$NUMA\. ?$NUMA\. ?$NUMA)";


/**
 * Utility functions
 */


/**
 * int dmytodate(string $str)
 *
 * Converts 'd.m.Y'-formatted $str to Unix timestamp.
 * Returns FALSE if unsuccessful.
 */
function dmytodate($str)
{
    $tmp = explode('.', $str);
    if (strlen($tmp[0]) == 1) {
        $str = "0$str";
    }
    $test = mktime(0, 0, 0, $tmp[1]+0, $tmp[0]+0, $tmp[2]+0);
    return (datetodmy($test) == $str ? $test : FALSE);
}


/**
 * int ymdtodate(string $str)
 *
 * Converts 'Y-m-d'-formatted $str to Unix timestamp
 * Returns FALSE if unsuccessful.
 */
function ymdtodate($str)
{
    $tmp = explode('-', $str);
    $test = mktime(0, 0, 0, $tmp[1]+0, $tmp[2]+0, $tmp[0]+0);
    return (datetoymd($test) == $str ? $test : FALSE);
}


/**
 * string datetodmy(int $ts)
 *
 * Converts Unix timestamp $date to 'd.m.Y'-formatted string
 */
function datetodmy($ts)
{
    return date('d.m.Y', $ts);
}


/**
 * string datetoymd(int $ts)
 *
 * Converts Unix timestamp $date to 'd.m.Y'-formatted string
 */
function datetoymd($ts)
{
    return date('Y-m-d', $ts);
}


/**
 * Finally, load custom config if present
 */
if (file_exists('config.php')) {
    require_once('config.php');
}

?>
