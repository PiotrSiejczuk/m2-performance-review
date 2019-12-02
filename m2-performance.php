<?php
/*
Description:    Magento 2 - Performance Review Panel
Author:         Piotr Siejczuk - https://github.com/PiotrSiejczuk (some of the code and display logic is based on OCP Control Panel Script)
Version:        0.0.1 Alpha
Date:           29.11.2019
Note:           This is in AS-IS Form, Experimental Script, Changes should be applied under Skilled Supervision / Magento HERO :)
*/

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/*
 * Default Configuration and Recommended Values - Start
 */
$opCacheInfo    = false;
$opCacheDetails = false;
$defaultHeaders = array(
    'Configurtion Flag',
    'Current Value',
    'Recommended Value',
    'Remark'
);

$opCacheRecommendation = array(
    "opcache.enable"                        => 1,
    "opcache.enable_cli"                    => 0,
    "opcache.use_cwd"                       => '1 => Disable',
    "opcache.validate_timestamps"           => 0,
    "opcache.validate_permission"           => 'TBD',
    "opcache.validate_root"                 => 'TBD',
    "opcache.inherited_hack"                => 'TBD',
    "opcache.dups_fix"                      => '0 => Disable',
    "opcache.revalidate_path"               => '0 => Disable',
    "opcache.log_verbosity_level"           => 3,
    "opcache.memory_consumption"            => '1024M',
    "opcache.interned_strings_buffer"       => 16,
    "opcache.max_accelerated_files"         => 65406,
    "opcache.max_wasted_percentage"         => '5 => Disable',
    "opcache.consistency_checks"            => '0 => Disable',
    "opcache.force_restart_timeout"         => '180 => Disable',
    "opcache.revalidate_freq"               => '2 => Disable',
    "opcache.preferred_memory_model"        => 'TBD => Disable',
    "opcache.blacklist_filename"            => 'TBD => Disable',
    "opcache.max_file_size"                 => '0 => Disable',
    "opcache.error_log"                     => 'TBD',
    "opcache.protect_memory"                => '0 => Disable',
    "opcache.save_comments"                 => 1,
    "opcache.enable_file_override"          => 1,
    "opcache.optimization_level"            => '"0xffffffff" => Disable',
    "opcache.lockfile_path"                 => 'TBD',
    "opcache.file_cache"                    => 'TBD',
    "opcache.file_cache_only"               => 'TBD',
    "opcache.file_cache_consistency_checks" => 'TBD',
    "opcache.fast_shutdown"                 => 'This directive has been removed in PHP 7.2.0. A variant of the fast shutdown sequence has been integrated into PHP and will be automatically used if possible.'
);

$localPhpConfiguration = array(
    "memory_limit"                          => ini_get('memory_limit'),
    "date.timezone"                         => ini_get('date.timezone'),
    "always_populate_raw_post_data"         => ini_get('always_populate_raw_post_data'), //Deprecated from PHP 7.0.0
    "asp_tags"                              => ini_get('asp_tags'), //Removed in PHP 7.0.0.
    "max_input_vars"                        => ini_get('max_input_vars'),
    "max_execution_time"                    => ini_get('max_execution_time'),
    "post_max_size"                         => ini_get('post_max_size'),
    "pdo_mysql.cache_size"                  => ini_get('pdo_mysql.cache_size'),
    "output_buffering"                      => ini_get('output_buffering'),
    "realpath_cache_size"                   => ini_get('realpath_cache_size'),
    "max_input_time"						=> ini_get('max_input_time')
);

$phpRecommendation = array(
    "memory_limit"                          => '756M',
    "date.timezone"                         => 'UTC',
    "always_populate_raw_post_data"         => NULL, //Deprecated from PHP 7.0.0
    "asp_tags"                              => NULL, //Removed in PHP 7.0.0.
    "max_input_vars"                        => 4000,
    "max_execution_time"                    => 600,
    "post_max_size"                         => '8M',
    "pdo_mysql.cache_size"                  => 2000,
    "output_buffering"                      => '4096',
    "realpath_cache_size"                   => '4096K',
	"max_input_time"						=> '600'
);

$phpFPMRecommendation = array(
    "php-fpm_config"                        => 'N/A',
    "pm"                                    => 'static',
    "pm.max_children"                       => 'Calculation Required',
    "pm.start_servers"                      => '', //@ToDo: Double-check
    "pm.max_requests"                       => '1024', //@ToDo: Double-check
    "rlimit_core"                           => '', //@ToDo: Double-check
    "slowlog"                               => '/var/log/php-fpm/www-slow.log', //@ToDo: Double-check
    "request_slowlog_timeout"               => '10s'
);

$phpFPMRecommendation = array(
    "php-fpm_config"                        => 'N/A',
    "pm"                                    => 'static',
    "pm.max_children"                       => 'Calculation Required',
    "pm.start_servers"                      => '', //@ToDo: Double-check
    "pm.max_requests"                       => '1024', //@ToDo: Double-check
    "rlimit_core"                           => '', //@ToDo: Double-check
    "slowlog"                               => '/var/log/php-fpm/www-slow.log', //@ToDo: Double-check
    "request_slowlog_timeout"               => '10s'
);

$sysctlRecommendation = array(
    'sysctl_config'                         => 'N/A',
    'net.core.rmem_max'                     => 212992,
    'net.core.wmem_max'                     => 212992,
    'net.ipv4.tcp_rmem'                     => "4096    87380   6291456",
    'net.ipv4.tcp_wmem'                     => "4096    87380   6291456",
    'net.core.netdev_max_backlog'           => 1000,
    'net.ipv4.tcp_no_metrics_save'          => 0,
    'net.ipv4.tcp_congestion_control'       => "cubic",
    'net.ipv4.tcp_window_scaling'           => 1,
    'net.ipv4.tcp_timestamps'               => 1,
    'net.ipv4.tcp_sack'                     => 1,
    'net.core.somaxconn'                    => 4096
);

$configurationRemarks = array(
    "memory_limit"                          => "Per-script limit. PHP memory_limit is the amount of memory a single PHP script is allowed to consume before it’s blocked. When blocked, the resulting error output looks something like this:
<blockquote><b>Fatal error</b>: <em>Allowed memory size of x bytes exhausted [tried to allocate x bytes] in /path/to/php/script</em></blockquote>
If two or more scripts are requested simultaneously, each is completely independent from the other.
They do not share the <em>memory_limit</em> setting. PHP is not designed for multi-threading.<br /><br /> For Projects running Huge Data Volumes: Products, Categories, Import & Export Processes the value may be increaded to <b>2GB</b>",
    "date.timezone"                         => "Default: UTC",
    "always_populate_raw_post_data"         => "Deprecated from PHP 7.0.0",
    "asp_tags"                              => "Removed in PHP 7.0.0.",
    "max_input_vars"                        => "Limits the number of input variables that may be accepted. It was introduced in PHP version 5.3.9. in order to deal with denial of service attacks which use hash collisions.<br /><br /><b>parse_str() function seems to take that parameter into account when generating array from string</b>.",
    "max_execution_time"                    => "This sets the maximum time in seconds a script is allowed to run before it is terminated by the parser. Helps prevent poorly written scripts from tying up the server",
    "max_input_time"						=> "It’s recommended to adjust <em>max_input_time</em> accordingly to <em>max_execution_time</em>. In case of PHP <em>max_execution_time = 600</em>, <em>max_input_time = 600</em>",
	"post_max_size"                         => "Sets max size of post data allowed. This setting also affects file upload. To upload large files, this value must be larger than <em>upload_max_filesize</em>. Generally speaking, memory_limit should be larger than <em>post_max_size</em>.",
    "pdo_mysql.cache_size"                  => "",
    "output_buffering"                      => "Output buffering is used by PHP to improve performance and to perform a few tricks. <ul><li>You can have PHP store all output into a buffer and output all of it at once improving network performance.</li><li>You can access the buffer content without sending it back to browser in certain situations. <em>http://php.net/manual/en/book.outcontrol.php</em>></li></ul>",
    "realpath_cache_size"                   => "Used by PHP to cache the real file system path of filenames referenced instead of looking them up each time. Every time you perform any of the various file functions or include/require a file and use a relative path, PHP has to look up where that file really exists.<br /><br /> PHP caches those values so it doesn’t have to search the current working directory and include_path for the file you are working on. If your website uses lots of relative path files, think about increasing this value. What value is required can be better estimated after monitoring by how fast the cache fills using <em>realpath_cache_size()</em> after restarting. Its contents can be viewed using <em>realpath_cache_get()</em>.",
    "opcache.enable"                        => "Enables the opcode cache for the CLI version of PHP.<br /><br />OPCache is a caching engine built into PHP.<br /><br /><em>Improves PHP performance by storing precompiled script bytecode in shared memory, thereby removing the need for PHP to load and parse scripts on each request</em>.",
    "opcache.enable_cli"                    => "Enables the opcode cache for the CLI version of PHP. This is a matter of Debate - It can be considered to be <em>Enabled / 1</em>",
    "opcache.memory_consumption"            => "The size of the shared memory storage used by OPCache, in Megabytes.",
    "opcache.interned_strings_buffer"       => "The amount of memory used to store interned strings, in megabytes. if you have the string \"foobar\" 1000 times in your code, internally PHP will store 1 immutable variable for this string and just use a pointer to it for the other 999 times you use it. This setting takes it to the next level-instead of having a pool of these immutable string for each SINGLE php-fpm process, this setting shares it across ALL of your php-fpm processes. It saves memory and improves performance, especially in big applications.",
    "opcache.max_accelerated_files"         => "Controls how many PHP files, at most, can be held in memory at once. It's important that your project has LESS FILES than whatever you set this at.",
    "opcache.validate_timestamps"           => "When this is enabled, PHP will check the file timestamp per your <em>opcache.revalidate_freq</em> value.",
    "opcache.blacklist_filename"            => "The location of the OPCache blacklist file. A blacklist file is a text file containing the names of files that should not be accelerated, one per line. Wildcards are allowed, and prefixes can also be provided. Lines starting with a semi-colon are ignored as comments.",
    "opcache.consistency_checks"            => "If non-zero, OPcache will verify the cache checksum every N requests, where N is the value of this configuration directive. This should only be enabled when debugging, as it will impair performance.",
    "opcache.fast_shutdown"                 => "Provides a faster mechanism for calling the deconstructors in your code at the end of a single request to speed up the response and recycle php workers so they're ready for the next incoming reques. <blockquote><b>This directive has been removed in PHP 7.2.0. A variant of the fast shutdown sequence has been integrated into PHP and will be automatically used if possible</b>.</blockquote>",
    "opcache.save_comments"                 => "Enable <em>opcache.save_comments</em>, which is required for Magento 2.1 and later (<em>https://devdocs.magento.com/guides/v2.3/install-gde/prereq/php-settings.html</em>).",
    "php-fpm_config"                        => "<b>Local PHP-FPM Location that has been used to Perform Review. Using first result line from: <em>find /etc/php -iname php-fpm.conf</em></b>",
    "pm"                                    => "Static is faster than dynamic",
    "pm.max_children"                       => "This needs to be calculated: <em>pm.max_children</em> = Total RAM Dedicated to the web server / Max child process size<br/><br /> Please use: <em>ps -ylC php-fpm --sort:rss</em> to get AVG php-fpm processes RAM usage.<br /><br />Example: Web Server has 60000Mb memory, php-fpm on average takes 100Mb, so max number of children is 600",
    "pm.start_servers"                      => "Should be around half of <em>max_children</em>. This parameter is for <em>pm=dynamic</em>",
    "pm.max_requests"                       => "", //@ToDo: Double-check
    "rlimit_core"                           => "This item is for enabling a core dump on a SIGSEGV, not related to Perfromance. Reduntant if APM Tools (NewRelic, Dynatrace, Datadog) are in use",
    "slowlog"                               => "Reduntant if APM Tools (NewRelic, Dynatrace, Datadog) are in use",
    "request_slowlog_timeout"               => 'Reduntant if APM Tools (NewRelic, Dynatrace, Datadog) are in use',
    "sysctl_config"                         => "<b>Local sysctl Location that has been used to Perform Review: <em>/etc/sysctl.conf</em></b>",
    "net.core.rmem_max"                     => "", //@ToDo: Double-check
    "net.core.wmem_max"                     => "", //@ToDo: Double-check
    "net.ipv4.tcp_rmem"                     => "", //@ToDo: Double-check
    "net.ipv4.tcp_wmem"                     => "", //@ToDo: Double-check
    "net.core.netdev_max_backlog"           => "The default <em>netdev_max_backlog</em> value is 1000. However, this may not be enough for multiple interfaces operating at 1Gbps, or even a single interface at 10Gbps.<br /><br /> Try doubling this value and observing the <em>/proc/net/softnet_stat</em> file. If doubling the value reduces the rate at which drops increment, double again and test again. Repeat this process until the optimum size is established and drops do not increment.",
    "net.ipv4.tcp_no_metrics_save"          => "Do not cache metrics on closing connections",
    "net.ipv4.tcp_congestion_control"       => "", //@ToDo: Double-check
    "net.ipv4.tcp_window_scaling"           => "", //@ToDo: Double-check
    "net.ipv4.tcp_timestamps"               => "", //@ToDo: Double-check
    "net.ipv4.tcp_sack"                     => "", //@ToDo: Double-check
    "net.core.somaxconn"                    => "Setting <em>net.core.somaxconn</em> to higher values is only needed on highloaded servers where new connection rate is so high/bursty that having 128"
);

/*
 * Default Configuration and Recommended Values - End
 */

if (!defined('PHP_VERSION_ID')) {
    $version = explode('.', PHP_VERSION);
    define('PHP_VERSION_ID', ($version[0] * 10000 + $version[1] * 100 + $version[2]));

    if (PHP_VERSION_ID < 50207) {
        define('PHP_MAJOR_VERSION',   $version[0]);
        define('PHP_MINOR_VERSION',   $version[1]);
        define('PHP_RELEASE_VERSION', $version[2]);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
	<title>:: Magento 2 Performance Review Script ::</title>
	<meta name="ROBOTS" content="NOINDEX,NOFOLLOW,NOARCHIVE" />

<style type="text/css">
    body {background-color: #fff; color: #000;}
	body, td, th, h1, h2 {font-family: sans-serif;}
	pre {margin: 0px; font-family: monospace;}
	a:link,a:visited {color: #000099; text-decoration: none;}
    a:hover {text-decoration: underline;}
	table {border-collapse: collapse; width: 900px; }
	.center {text-align: center;}
	.center table { margin-left: auto; margin-right: auto; text-align: left;}
	.center th { text-align: center !important; }
	.middle {vertical-align:middle;}
	td, th { border: 1px solid #000; font-size: 75%; vertical-align: baseline; padding: 3px; }
	h1 {font-size: 150%;}
	h2 {font-size: 125%;}
    h4 {font-size: 100%; font-style: italic;}
	.p {text-align: left;}
	.e {background-color: #ccccff; font-weight: bold; color: #000; width:50%; white-space:nowrap;}
	.h {background-color: #9999cc; font-weight: bold; color: #000;}
	.v, .remark {background-color: #cccccc; color: #000;}
    .configMatch {background-color: #2A6002; color: #000;}
	.vr {background-color: #cccccc; text-align: right; color: #000; white-space: nowrap;}
	.b {font-weight:bold;}
	.white, .white a {color:#fff;}
    img {float: right; border: 0px;}
	hr {width: 900px; background-color: #cccccc; border: 0px; height: 1px; color: #000;}
	.meta, .small {font-size: 75%; }
	.meta {margin: 2em 0;}
	.meta a, th a {padding: 10px; white-space:nowrap; }
	.buttons {margin:0 0 1em;}
	.buttons a {margin:0 15px; background-color: #9999cc; color:#fff; text-decoration:none; padding:1px; border:1px solid #000; display:inline-block; width:5em; text-align:center;}
	#files td.v a {font-weight:bold; color:#9999cc; margin:0 10px 0 5px; text-decoration:none; font-size:120%;}
	#files td.v a:hover {font-weight:bold; color:#ee0000;}
	.graph {display:inline-block; width:145px; margin:1em 0 1em 1px; border:0; vertical-align:top;}
	.graph table {width:100%; height:150px; border:0; padding:0; margin:5px 0 0 0; position:relative;}
	.graph td {vertical-align:middle; border:0; padding:0 0 0 5px;}
	.graph .bar {width:25px; text-align:right; padding:0 2px; color:#fff;}
	.graph .total {width:34px; text-align:center; padding:0 5px 0 0;}
	.graph .total div {border:1px dashed #888; border-right:0; height:99%; width:12px; position:absolute; bottom:0; left:17px; z-index:-1;}
    .graph .total span {background:#fff; font-weight:bold;}
    .graph .actual {text-align:right; font-weight:bold; padding:0 5px 0 0;}
	.graph .red {background:#ee0000;}
    .graph .green {background:#00cc00;}
    .graph .brown {background:#8B4513;}
    .ok {color: green;}
    .warning {color: blue;}
    .alert {color: orange; font-weight: bold;}
    .problem {color: red; font-weight: bold;}
</style>
<!--[if lt IE 9]>
<script type="text/javascript" defer="defer">
    window.onload=function(){var i,t=document.getElementsByTagName('table');for(i=0;i<t.length;i++){if(t[i].parentNode.className=='graph')t[i].style.height=150-(t[i].clientHeight-150)+'px';}}
</script>
<![endif]-->

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
<script type="text/javascript">
    function hideRemarks() {
        $('.remark').hide();
    }
    function showRemarks() {
        $('.remark').show();
    }
</script>
</head>

<body>
<div class="center">
    <h1><a name="top" href="?">:: Magento 2 Performance Review Script ::</a></h1>
    <h4>by Piotr Siejczuk (piotr.siejczuk@gmail.com)</h4>

    <div class="buttons">
        <a href="#opcache">OPCache</a>
        <a href="#phpini">PHP.ini</a>
        <a href="#mysql">MySQL</a>
        <a href="#php-fpm">PHP-FPM</a>
        <a href="#sysctl">Sysctl</a>
        <!--
        <a href="" onclick="hideRemarks()">Remarks: Hide</a>
        <a href="" onclick="showRemarks()">Remarks: Show</a>
        -->
        <a href="?" onclick="window.location.reload(true); return false">Refresh</a>
    </div>

    <?php
        $serverLoad = sys_getloadavg();
        if ($serverLoad[1] < 0.70) {
            $level = 'ok';
        } elseif ($serverLoad[1] > 0.70 && $serverLoad[1] < 1.00) {
            $level = 'warning';
        } elseif ($serverLoad[1] > 1.00 && $serverLoad[1] < 5.00) {
            $level = 'alert';
        } else {
            $level = 'problem';
        }
    ?>
    <h2>Server Uptime: [ <?php echo getServerUptime(); ?> ] || Server Load (5min): [<span class="<?php echo $level ?>"><?php echo $serverLoad[1]; ?></span>] <?php echo 'PHP Version: ' . PHP_VERSION_ID; ?></h2>
    <h2><a name="opcache">OPCache Review</a> [<a href="#top">#top</a>]</h2>
    <?php printTable(getOpCacheConfig(), $opCacheRecommendation, $configurationRemarks, $defaultHeaders); ?>
    <h2><a name="phpini">PHP Configuration Review</a> [<a href="#top">#top</a>]</h2>
    <?php printTable($localPhpConfiguration, $phpRecommendation, $configurationRemarks, $defaultHeaders); ?>
    <h2><a name="mysql">MySQL Configuration Review</a> [<a href="#top">#top</a>]</h2>
    <?php getMySQLConfig(); ?>
    <h2><a name="php-fpm">PHP-FPM Configuration Review</a> [<a href="#top">#top</a>]</h2>
    <?php printTable(getPhpFPMConfig(), $phpFPMRecommendation, $configurationRemarks, $defaultHeaders); ?>
    <h2><a name="sysctl">Sysctl Configuration Review</a> [<a href="#top">#top</a>]</h2>
    <?php printTable(getSysctlConfig(), $sysctlRecommendation, $configurationRemarks, $defaultHeaders); ?>
</div>
</body>

<?php
function printTable($array, $recommendedValues = false, $configurationRemarks = false, $headers = false) {
    if ( empty($array) || !is_array($array) ) {return;}
    echo '<table border="0" cellpadding="3" width="900">';
    if (!empty($headers)) {
        if (!is_array($headers)) {$headers=array_keys(reset($array));}
        echo '<tr class="h">';
        foreach ($headers as $value) {
            if ($value == 'Remark') {
                echo '<th class="remark">',$value,'</th>';
            } else {
                echo '<th>',$value,'</th>';
            }
        }
        echo '</tr>';
    }
    foreach ($array as $key => $value) {
        $configMatch = false;
        $remark = false;
        if (!is_array($value)) {
            $configMatch = (array_key_exists($key, $recommendedValues) && !strcmp ($value, $recommendedValues[$key])) ? "configMatch" : "v";
            $remark = (array_key_exists($key, $configurationRemarks)) ? $configurationRemarks[$key] : "";
        }
        echo '<tr>';
        if ( !is_numeric($key) ) {
            echo '<td class="e">',$key,'</td>';
            if ( is_numeric($value) ) {
                if ( $value>1048576) { $value=round($value/1048576,1).'M'; }
                elseif ( is_float($value) ) { $value=round($value,1); }
            }
        }
        if ( is_array($value) ) {
            foreach ($value as $column) {
                echo '<td class="v">',$column,'</td>';
            }

        } else {
            echo '<td class='.$configMatch.'>',$value,'</td>';
        }
        if (array_key_exists($key, $recommendedValues)) {
            echo '<td class="v">',$recommendedValues[$key],'</td>';
            echo '<td class="remark">',$remark,'</td>';
        } else {
            echo '<td class="v"> TBD </td><td></td>';
        }
        echo '</tr>';
    }
    echo '</table>';
}

/*
 * Nasty way to get Server Uptime
 */
function getServerUptime() {
    $data       = shell_exec('uptime');
    $uptime     = explode(' up ', $data);
    $uptime     = explode(',', $uptime[1]);
    $uptime     = $uptime[0].', '.$uptime[1]; //Uptime + Users Info
    return $uptime;
}

function getOpCacheConfig() {
    $opCacheInfo    = false;
    $opCacheDetails = false;
    try {
        if (function_exists('opcache_get_status')) {
            $opCacheInfo = opcache_get_status();
        }
    } catch (\Error $exception) {
        echo 'Exception Thrown: ' . $exception;
    }

    if ($opCacheInfo) {
        /*
         * Review Usage of opcache_get_configuration()
         * https://www.php.net/manual/en/function.opcache-get-configuration.php
         */
        $opCacheData    = opcache_get_configuration();
        $opCacheDetails = $opCacheData['directives'];
    } else {
        echo '<h3 style="color: #b30000">[EPIC FAIL]: OpCache is Disabled within Your Server PHP Configuration! :(</h3>';
    }

    return $opCacheDetails;
}

function getMySQLConfig() {
    $command          = "which mysqld";
    $mySqlLocation    = shell_exec($command);
    $command          = "$mySqlLocation" . ' --verbose --help | grep -A 1 "Default options"';
    //var_dump($command);
    $configFiles      = shell_exec("'" . $command . "'");
    //var_dump(shell_exec('/usr/sbin/mysqld --verbose --help | grep -A 1 "Default options"'));
    //var_dump(shell_exec('/usr/sbin/mysqld --verbose --help | grep -A 1 "Default options"'));
    //return $location;
}

function getPhpFPMConfig() {
    $phpFPMConfig = array();
    $phpFPMLocations = explode(PHP_EOL, shell_exec('find /etc/php -iname php-fpm.conf'));
    if ($phpFPMLocations) {
        $phpFPMReadLocation = reset($phpFPMLocations);
        $phpFPMConfig['php-fpm_config']             = $phpFPMReadLocation;
        $phpFPMConfig['pm.max_children']            = shell_exec('grep -i "pm.max_children" ' . $phpFPMReadLocation);
        $phpFPMConfig['pm.start_servers']           = shell_exec('grep -i "pm.start_servers" ' . $phpFPMReadLocation);
        $phpFPMConfig['pm.max_requests']            = shell_exec('grep -i "pm.max_requests" ' . $phpFPMReadLocation);
        $phpFPMConfig['rlimit_core']                = shell_exec('grep -i "rlimit_core" ' . $phpFPMReadLocation);
        $phpFPMConfig['slowlog']                    = shell_exec('grep -i "slowlog" ' . $phpFPMReadLocation);
        $phpFPMConfig['request_slowlog_timeout']    = shell_exec('grep -i "request_slowlog_timeout" ' . $phpFPMReadLocation);
    }

    return $phpFPMConfig;
}

function getSysctlConfig() {
    $sysctlConfig           = array();
    $sysctlConfigLocation   = '/etc/sysctl.conf';
    if (file_exists($sysctlConfigLocation)) {
        $sysctlConfig['sysctl_config']                  = $sysctlConfigLocation;
        $sysctlConfig['net.core.rmem_max']              = shell_exec('grep -i "net.core.rmem_max" ' . $sysctlConfigLocation);
        $sysctlConfig['net.core.wmem_max']              = shell_exec('grep -i "net.core.wmem_max" ' . $sysctlConfigLocation);
        $sysctlConfig['net.ipv4.tcp_rmem']              = shell_exec('grep -i "net.ipv4.tcp_rmem" ' . $sysctlConfigLocation);
        $sysctlConfig['net.ipv4.tcp_wmem']              = shell_exec('grep -i "net.ipv4.tcp_wmem" ' . $sysctlConfigLocation);
        $sysctlConfig['net.core.netdev_max_backlog']    = shell_exec('grep -i "net.ipv4.netdev_max_backlog" ' . $sysctlConfigLocation);
        $sysctlConfig['net.ipv4.tcp_no_metrics_save']   = shell_exec('grep -i "net.core.tcp_no_metrics_save" ' . $sysctlConfigLocation);
        $sysctlConfig['net.ipv4.tcp_congestion_control']= shell_exec('grep -i "net.ipv4.tcp_congestion_control" ' . $sysctlConfigLocation);
        $sysctlConfig['net.ipv4.tcp_no_metrics_save']   = shell_exec('grep -i "net.ipv4.tcp_no_metrics_save" ' . $sysctlConfigLocation);
        $sysctlConfig['net.ipv4.tcp_window_scaling']    = shell_exec('grep -i "net.ipv4.tcp_window_scaling" ' . $sysctlConfigLocation);
        $sysctlConfig['net.ipv4.tcp_timestamps']        = shell_exec('grep -i "net.ipv4.tcp_timestamps" ' . $sysctlConfigLocation);
        $sysctlConfig['net.ipv4.tcp_sack']              = shell_exec('grep -i "net.ipv4.tcp_sack" ' . $sysctlConfigLocation);
        $sysctlConfig['net.core.somaxconn']             = shell_exec('grep -i "net.core.somaxconn" ' . $sysctlConfigLocation);
    }

    return $sysctlConfig;
}