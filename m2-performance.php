<?php
/*
Description:    Magento 2 - Performance Review Panel
Author:         Piotr Siejczuk - https://github.com/PiotrSiejczuk (some of the code and display logic is based on OCP Control Panel Script)
Version:        0.0.2 Alpha
Date:           10.12.2019
Note:           This Script is in AS-IS Form, Experimental Script, Changes should be applied under Skilled Supervision / Magento HERO :)
*/

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/*
 * Default Configuration and Recommended Values - Start
 */
define('STATUS_DISABLE', 'Disable');
define('STATUS_TBD', 'TBD');

$opCacheInfo    = false;
$opCacheDetails = false;
$defaultHeaders = array(
    'Configurtion Flag',
    'Current Value',
    'Recommended Value',
    'Remark'
);

$opCacheRecommendation      = array(
    "opcache.enable"                        => 1,
    "opcache.enable_cli"                    => 0,
    "opcache.use_cwd"                       => 1,
    "opcache.validate_timestamps"           => 0,
    "opcache.validate_permission"           => STATUS_TBD,
    "opcache.validate_root"                 => STATUS_TBD,
    "opcache.inherited_hack"                => STATUS_TBD,
    "opcache.dups_fix"                      => 0,
    "opcache.revalidate_path"               => 0,
    "opcache.log_verbosity_level"           => 3,
    "opcache.memory_consumption"            => '1024M',
    "opcache.interned_strings_buffer"       => 16,
    "opcache.max_accelerated_files"         => 65406,
    "opcache.max_wasted_percentage"         => 5,
    "opcache.consistency_checks"            => 0,
    "opcache.force_restart_timeout"         => 180,
    "opcache.revalidate_freq"               => 2,
    "opcache.preferred_memory_model"        => STATUS_TBD,
    "opcache.blacklist_filename"            => STATUS_TBD,
    "opcache.max_file_size"                 => 0,
    "opcache.error_log"                     => STATUS_TBD,
    "opcache.protect_memory"                => 0,
    "opcache.save_comments"                 => 1,
    "opcache.enable_file_override"          => 1,
    "opcache.optimization_level"            => '0xffffffff',
    "opcache.lockfile_path"                 => STATUS_TBD,
    "opcache.file_cache"                    => STATUS_TBD,
    "opcache.file_cache_only"               => STATUS_TBD,
    "opcache.file_cache_consistency_checks" => STATUS_TBD,
    "opcache.fast_shutdown"                 => 'This directive has been removed in PHP 7.2.0. A variant of the fast shutdown sequence has been integrated into PHP and will be automatically used if possible.'
);
$localPhpConfiguration      = array(
    "display_errors"                        => ini_get('display_errors'),
    "log_errors"                            => ini_get('log_errors'),
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
$phpRecommendation          = array(
    "display_errors"                        => 0,
    "log_errors"                            => 1,
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
$phpFPMRecommendation       = array(
    "php-fpm_config"                        => 'N/A',
    "pm"                                    => 'static',
    "pm.max_children"                       => 'Calculation Required',
    "pm.start_servers"                      => '', //@ToDo: Double-check
    "pm.max_requests"                       => '1024', //@ToDo: Double-check
    "rlimit_core"                           => '', //@ToDo: Double-check
    "slowlog"                               => '/var/log/php-fpm/www-slow.log', //@ToDo: Double-check
    "request_slowlog_timeout"               => '10s'
);
$phpFPMConfigurationFlags   = array(
    'pm.max_children',
    'pm.start_servers',
    'pm.max_requests',
    'rlimit_core',
    'slowlog',
    'request_slowlog_timeout'
);
$sysctlRecommendation       = array(
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
$sysctlConfigurationFlags   = array(
    'net.core.rmem_max',
    'net.core.wmem_max',
    'net.ipv4.tcp_rmem',
    'net.ipv4.tcp_wmem',
    'net.core.netdev_max_backlog',
    'net.ipv4.tcp_no_metrics_save',
    'net.ipv4.tcp_congestion_control',
    'net.ipv4.tcp_window_scaling',
    'net.ipv4.tcp_timestamps',
    'net.ipv4.tcp_sack',
    'net.core.somaxconn'
);
$redisRecommendation        = array(
    'redis_config'                                              => 'N/A',
    'session.redis.timeout'                                     => 10,
    'session.redis.disable_locking'                             => 1,
    'session.redis.min_lifetime'                                => 1200,
    'session.redis.max_lifetime'                                => 2592000,
    'cache.frontend.default.backend_options.read_timeout'       => 2.5,
    'cache.frontend.default.backend_options.connect_retries'    => 3
);
$redisConfigurationFlags    = array(
    'session.redis.timeout',
    'session.redis.disable_locking',
    'session.redis.min_lifetime',
    'session.redis.max_lifetime',
    'cache.frontend.default.backend_options.read_timeout',
    'cache.frontend.default.backend_options.connect_retries'
);
$mySqlConfigurationFlags    = array(
    'innodb-thread-concurrency',
    'innodb-flush-log-at-trx-commit',
    'thread-concurrency',
    'thread-cache-size',
    'table-cache',
    'table-open-cache',
    'query-cache-size',
    'query-cache-limit',
    'max-connections',
    'query-cache-type',
    'performance-schema',
    'join-buffer-size',
    'key-buffer',
    'innodb-autoextend-increment',
    'max-allowed-packet',
    'read-buffer-size',
    'read-rnd-buffer-size',
    'bulk-insert-buffer-size',
    'myisam-sort-buffer-size',
    'myisam-max-sort-file-size',
    'myisam-max-extra-sort-file-size',
    'myisam-repair-threads',
    'innodb-file-per-table',
    'innodb-log-file-size',
    'innodb-buffer-pool-instances',
    'innodb-buffer-pool-size',
    'mam-heap-table',
    'tmp-table-size',
    'max-heap-table-size',
    'character-set-server',
    'div-precision-increment',
    'eq-range-index-dive-limit',
    'event-scheduler',
    'group-concat-max-len',
    'init-connect',
    'innodb-adaptive-hash-index',
    'innodb-file-format',
    'innodb-file-per-table',
    'innodb-io-capacity',
    'innodb-lock-wait-timeout',
    'innodb-old-blocks-pct',
    'innodb-old-blocks-time',
    'innodb-online-alter-log-max-size',
    'innodb-read-ahead-threshold',
    'innodb-read-io-threads',
    'innodb-thread-concurrency',
    'innodb-thread-sleep-delay',
    'innodb-write-io-threads',
    'interactive-timeout',
    'join-buffer-size',
    'log-bin-trust-function-creators',
    'log-queries-not-using-indexes',
    'log-slow-admin-statements',
    'log-slow-slave-statements',
    'log-throttle-queries-not-using-indexes',
    'long-query-time',
    'lower-case-table-names',
    'max-allowed-packet',
    'max-connect-errors',
    'max-length-for-sort-data',
    'min-examined-row-limit',
    'net-read-timeout',
    'net-write-timeout',
    'server-id',
    'slave-compressed-protocol',
    'slave-net-timeout',
    'slow-query-log',
    'tmp-table-size',
    'table-open-cache',
    'time-zone',
    'tx-isolation',
    'wait-timeout',
    'default-storage-engine'
);
$mySQLRecommendation        = array(
    'innodb-thread-concurrency'                                 => 0,
    'innodb-flush-log-at-trx-commit'                            => 1,
    'thread-concurrency'                                        => 0,
    'thread-cache-size'                                         => "",
    'table-cache'                                               => "",
    'table-open-cache'                                          => "LEAST({DBInstanceClassMemory/1179121}, 6000)",
    'query-cache-size'                                          => "256M",
    'query-cache-limit'                                         => 2097152,
    'max-connections'                                           => "GREATEST({log(DBInstanceClassMemory/805306368)*45},{log(DBInstanceClassMemory/8187281408)*1000})",
    'query-cache-type'                                          => "ON",
    'performance-schema'                                        => 0,
    'join-buffer-size'                                          => 262144,
    'key-buffer'                                                => 16777216,
    'innodb-autoextend-increment'                               => 64,
    'max-allowed-packet'                                        => 67108864,
    'read-buffer-size'                                          => 262144,
    'read-rnd-buffer-size'                                      => 524288,
    'bulk-insert-buffer-size'                                   => 8388608,
    'myisam-sort-buffer-size'                                   => 8388608,
    'myisam-max-sort-file-size'                                 => 9223372036853730000,
    'myisam-max-extra-sort-file-size'                           => "",
    'myisam-repair-threads'                                     => 1,
    'innodb-file-per-table'                                     => 1,
    'innodb-flush-method'                                       => "O_DIRECT",
    'innodb-log-file-size'                                      => 50331648,
    'innodb-buffer-pool-instances'                              => "",
    'innodb-buffer-pool-size'                                   => "Should be 80% memory of MySQL Server",
    'innodb-data-file-path'                                     => "ibdata1:12M:autoextend",
    'mam-heap-table'                                            => "",
    'tmp-table-size'                                            => 268435456,
    'max-heap-table-size'                                       => 268435456,
    "character-set-server"                                      => "",
    "div-precision-increment"                                   => "",
    "eq-range-index-dive-limit"                                 => "",
    "event-scheduler"                                           => "",
    "group-concat-max-len"                                      => "",
    "init-connect"                                              => "",
    "innodb-adaptive-hash-index"                                => "",
    "innodb-file-format"                                        => "",
    "innodb-io-capacity"                                        => "",
    "innodb-lock-wait-timeout"                                  => "",
    "innodb-old-blocks-pct"                                     => "",
    "innodb-old-blocks-time"                                    => "",
    "innodb-online-alter-log-max-size"                          => "",
    "innodb-read-ahead-threshold"                               => "",
    "innodb-read-io-threads"                                    => "",
    "innodb-thread-sleep-delay"                                 => "",
    "innodb-write-io-threads"                                   => "",
    "interactive-timeout"                                       => "",
    "log-bin-trust-function-creators"                           => "",
    "log-queries-not-using-indexes"                             => "",
    "log-slow-admin-statements"                                 => "",
    "log-slow-slave-statements"                                 => "",
    "log-throttle-queries-not-using-indexes"                    => "",
    "long-query-time"                                           => "",
    "lower-case-table-names"                                    => "",
    "max-connect-errors"                                        => "",
    "max-length-for-sort-data"                                  => "",
    "min-examined-row-limit"                                    => "",
    "net-read-timeout"                                          => "",
    "net-write-timeout"                                         => "",
    "server-id"                                                 => "",
    "slave-compressed-protocol"                                 => "",
    "slave-net-timeout"                                         => "",
    "slow-query-log"                                            => "",
    "time-zone"                                                 => "",
    "tx-isolation"                                              => "",
    "wait-timeout"                                              => "",
    "default-storage-engine"                                    => ""
);
$configurationRemarks       = array(
    "display_errors"                        => "Errors Display can be Enabled for NON-Production Server Instances. It is HIGHLY NOT RECOMMENDED to Display Errors within Production Instances - Due to Security Risks",
    "log_errors"                            => "Tells whether script error messages should be logged to the server's error log or error_log. This option is thus server-specific. <blockquote>You're strongly advised to use error logging in place of error displaying on production web sites</blockquote>",
    "memory_limit"                          => "Per-script limit. PHP memory_limit is the amount of memory a single PHP script is allowed to consume before it’s blocked. When blocked, the resulting error output looks something like this:
<blockquote><b>Fatal error</b>: <em>Allowed memory size of x bytes exhausted [tried to allocate x bytes] in /path/to/php/script</em></blockquote>
If two or more scripts are requested simultaneously, each is completely independent from the other.
They do not share the <em>memory_limit</em> setting. PHP is not designed for multi-threading.<br /><br /> <b>For Projects running Huge Data Volumes: Products, Categories, Import & Export Processes the value may be increased to 2GB</b>",
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
    "opcache.use_cwd"                       => STATUS_DISABLE,
    "opcache.dups_fix"                      => STATUS_DISABLE,
    "opcache.revalidate_path"               => STATUS_DISABLE,
    "opcache.memory_consumption"            => "The size of the shared memory storage used by OPCache, in Megabytes.",
    "opcache.interned_strings_buffer"       => "The amount of memory used to store interned strings, in megabytes. if you have the string \"foobar\" 1000 times in your code, internally PHP will store 1 immutable variable for this string and just use a pointer to it for the other 999 times you use it. This setting takes it to the next level-instead of having a pool of these immutable string for each SINGLE php-fpm process, this setting shares it across ALL of your php-fpm processes. It saves memory and improves performance, especially in big applications.",
    "opcache.max_accelerated_files"         => "Controls how many PHP files, at most, can be held in memory at once. It's important that your project has LESS FILES than whatever you set this at.",
    "opcache.max_wasted_percentage"         => STATUS_DISABLE,
    "opcache.force_restart_timeout"         => STATUS_DISABLE,
    "opcache.revalidate_freq"               => STATUS_DISABLE,
    "opcache.preferred_memory_model"        => STATUS_DISABLE,
    "opcache.validate_timestamps"           => "When this is enabled, PHP will check the file timestamp per your <em>opcache.revalidate_freq</em> value.",
    "opcache.blacklist_filename"            => "The location of the OPCache blacklist file. A blacklist file is a text file containing the names of files that should not be accelerated, one per line. Wildcards are allowed, and prefixes can also be provided. Lines starting with a semi-colon are ignored as comments.",
    "opcache.max_file_size"                 => STATUS_DISABLE,
    "opcache.consistency_checks"            => "If non-zero, OPcache will verify the cache checksum every N requests, where N is the value of this configuration directive. This should only be enabled when debugging, as it will impair performance.",
    "opcache.fast_shutdown"                 => "Provides a faster mechanism for calling the deconstructors in your code at the end of a single request to speed up the response and recycle php workers so they're ready for the next incoming reques. <blockquote><b>This directive has been removed in PHP 7.2.0. A variant of the fast shutdown sequence has been integrated into PHP and will be automatically used if possible</b>.</blockquote>",
    "opcache.protect_memory"                 => STATUS_DISABLE,
    "opcache.save_comments"                 => "Enable <em>opcache.save_comments</em>, which is required for Magento 2.1 and later (<em>https://devdocs.magento.com/guides/v2.3/install-gde/prereq/php-settings.html</em>).",
    "opcache.optimization_level"            => STATUS_DISABLE,
    "php-fpm_config"                        => "<b>Local PHP-FPM Location that has been used to Perform Review. Using first result line from: <em>find /etc/php -iname php-fpm.conf</em></b>",
    "pm"                                    => "Static is faster than dynamic",
    "pm.max_children"                       => "This needs to be calculated: <em>pm.max_children</em> = Total RAM Dedicated to the web server / Max child process size<br/><br /> Please use: <em>ps -ylC php-fpm --sort:rss</em> to get AVG php-fpm processes RAM usage.<br /><br />Example: Web Server has 60000Mb memory, php-fpm on average takes 100Mb, so max number of children is 600",
    "pm.start_servers"                      => "Should be around half of <em>max_children</em>. This parameter is for <em>pm=dynamic</em>",
    "pm.max_requests"                       => "", //@ToDo: Double-check
    "rlimit_core"                           => "This item is for enabling a core dump on a SIGSEGV, not related to Perfromance. Reduntant if APM Tools (NewRelic, Dynatrace, Datadog) are in use",
    "slowlog"                               => "Reduntant if APM Tools (NewRelic, Dynatrace, Datadog) are in use.<br /><br/><b>Disable</b> slow query log logging in PHP-FPM – it affects performance and the information is already provided by APM Tools",
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
    "net.core.somaxconn"                    => "Setting <em>net.core.somaxconn</em> to higher values is only needed on highloaded servers where new connection rate is so high/bursty that having 128",
    "redis_config"                          => "<b>Local Redis Location that has been used to Perform Review: <em>/etc/redis/redis.conf</em></b>",
    "session.redis.timeout"                 => "", //@ToDo: Double-check
    "session.redis.disable_locking"         => "", //@ToDo: Double-check
    "session.redis.min_lifetime"            => "", //@ToDo: Double-check
    "session.redis.max_lifetime"            => "", //@ToDo: Double-check
    "cache.frontend.default.backend_options.read_timeout"       => "", //@ToDo: Double-check
    "cache.frontend.default.backend_options.connect_retries"    => "", //@ToDo: Double-check
    "innodb-thread-concurrency"                                 => 0,
    "innodb-flush-log-at-trx-commit"                            => 1,
    "thread-concurrency"                                        => 0,
    "thread-cache-size"                                         => "",
    "table-cache"                                               => "",
    "table-open-cache"                                          => "LEAST({DBInstanceClassMemory/1179121}, 6000)",
    "query-cache-size"                                          => "256M",
    "query-cache-limit"                                         => 2097152,
    "max-connections"                                           => "GREATEST({log(DBInstanceClassMemory/805306368)*45},{log(DBInstanceClassMemory/8187281408)*1000})",
    "query-cache-type"                                          => "ON",
    "performance-schema"                                        => 0,
    "join-buffer-size"                                          => 262144,
    "key-buffer"                                                => 16777216,
    "innodb-autoextend-increment"                               => 64,
    "max-allowed-packet"                                        => 67108864,
    "read-buffer-size"                                          => 262144,
    "read-rnd-buffer-size"                                      => 524288,
    "bulk-insert-buffer-size"                                   => '8M',
    "myisam-sort-buffer-size"                                   => "Usage of MyISAM Engine is Strongly NOT Recommended",
    "myisam-max-sort-file-size"                                 => "Usage of MyISAM Engine is Strongly NOT Recommended",
    "myisam-max-extra-sort-file-size"                           => "Usage of MyISAM Engine is Strongly NOT Recommended",
    "myisam-repair-threads"                                     => "Usage of MyISAM Engine is Strongly NOT Recommended",
    "innodb-file-per-table"                                     => 1,
    "innodb-flush-method"                                       => "O_DIRECT",
    "innodb-log-file-size"                                      => 50331648,
    "innodb-buffer-pool-instances"                              => "",
    "innodb-buffer-pool-size"                                   => "Should be 80% memory of MySQL server",
    "innodb-data-file-path"                                     => "ibdata1:12M:autoextend",
    "mam-heap-table"                                            => "",
    "tmp-table-size"                                            => 268435456,
    "max-heap-table-size"                                       => 268435456,
    "character-set-server"                                      => "",
    "div-precision-increment"                                   => "",
    "eq-range-index-dive-limit"                                 => "",
    "event-scheduler"                                           => "",
    "group-concat-max-len"                                      => "",
    "init-connect"                                              => "",
    "innodb-adaptive-hash-index"                                => "",
    "innodb-file-format"                                        => "",
    "innodb-io-capacity"                                        => "",
    "innodb-lock-wait-timeout"                                  => "",
    "innodb-old-blocks-pct"                                     => "",
    "innodb-old-blocks-time"                                    => "",
    "innodb-online-alter-log-max-size"                          => "",
    "innodb-read-ahead-threshold"                               => "",
    "innodb-read-io-threads"                                    => "",
    "innodb-thread-sleep-delay"                                 => "",
    "innodb-write-io-threads"                                   => "",
    "interactive-timeout"                                       => "",
    "log-bin-trust-function-creators"                           => "",
    "log-queries-not-using-indexes"                             => "",
    "log-slow-admin-statements"                                 => "",
    "log-slow-slave-statements"                                 => "",
    "log-throttle-queries-not-using-indexes"                    => "",
    "long-query-time"                                           => "",
    "lower-case-table-names"                                    => "",
    "max-connect-errors"                                        => "",
    "max-length-for-sort-data"                                  => "",
    "min-examined-row-limit"                                    => "",
    "net-read-timeout"                                          => "",
    "net-write-timeout"                                         => "",
    "server-id"                                                 => "",
    "slave-compressed-protocol"                                 => "",
    "slave-net-timeout"                                         => "",
    "slow-query-log"                                            => "",
    "time-zone"                                                 => "",
    "tx-isolation"                                              => "",
    "wait-timeout"                                              => "",
    "default-storage-engine"                                    => ""
);
$customConfigLocation       = false;

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
	a:link,a:visited {color: orangered; text-decoration: none;}
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
	.h {background-color: #777; font-weight: bold; color: #000;}
    th.remark {background-color: #777;}
	.v, .remark {background-color: #cccccc; color: #000;}
    .remark.tbd {background-color: orange;}
    .remark.disable {background-color: orangered;}
    .configMatch {background-color: #00cc00; color: #000;}
	.vr {background-color: #cccccc; text-align: right; color: #000; white-space: nowrap;}
	.b {font-weight:bold;}
	.white, .white a {color:#fff;}
    img {float: right; border: 0px;}
	hr {width: 900px; background-color: #cccccc; border: 0px; height: 1px; color: #000;}
	.meta, .small {font-size: 75%; }
	.meta {margin: 2em 0;}
	.meta a, th a {padding: 10px; white-space:nowrap; }
	.buttons {margin:0 0 1em;}
	.buttons a {margin:0 15px; background-color:orange; color:#fff; text-decoration:none; padding:1px; border-top:1px solid #000; border-rght:1px solid #000; border-bottom:1px solid #000; border-left:8px solid orangered; display:inline-block; width:5em; text-align:center;}
    .ok {color: green;}
    .warning {color: blue;}
    .alert {color: orange; font-weight: bold;}
    .problem {color: red; font-weight: bold;}
    blockquote {
        background:#EDEDED;
        border-left:8px solid orangered;
        box-shadow:0 3px 15px rgba(0,0,0,.15);
        color:#666;
        font-size:1.1em;
        font-style:italic;
        font-weight:300;
        line-height:1.2;
        margin:10px auto;
        padding:1.2em 30px 1.2em 30px;
        position:relative;
        vertical-align:super;
        width:60%;
        z-index: 0;
        overflow: hidden;
    }
    .collapsible {
        background-color: #777;
        border-left:8px solid orangered;
        border-top: none;
        border-bottom: none;
        color: white;
        cursor: pointer;
        padding: 18px;
        width: 100%;
        text-align: left;
        outline: none;
        font-size: 15px;
    }

    .active, .collapsible:hover {
        background-color: #555;
    }

    .collapsible:after {
        content: '[\002B]';
        color: white;
        font-weight: bold;
        float: right;
        margin-left: 5px;
    }

    .active:after {
        content: "[\2212]";
    }

    .content {
        padding: 0 18px;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.2s ease-out;
        background-color: #f1f1f1;
    }

    #opCacheChart, #phpChart, #mySqlChart, #phpFPMChart, #sysctlChart, #redisChart {
        width: 1024px;
        text-align: left;
        margin-left: auto;
        margin-right: auto;
    }

    .form-style{
        font: 95% Arial, Helvetica, sans-serif;
        max-width: 600px;
        margin: 10px auto;
        padding: 16px;
        background: #F7F7F7;
    }
    .form-style h1{
        background: orange;
        padding: 8px 0;
        font-size: 140%;
        /*font-weight: 300;*/
        text-align: center;
        color: #fff;
        margin: -16px -16px 16px -16px;
        font-weight: bold;
    }
    .form-style input[type="text"],
    .form-style input[type="date"],
    .form-style input[type="datetime"],
    .form-style input[type="email"],
    .form-style input[type="number"],
    .form-style input[type="search"],
    .form-style input[type="time"],
    .form-style input[type="url"],
    .form-style textarea,
    .form-style select
    {
        -webkit-transition: all 0.30s ease-in-out;
        -moz-transition: all 0.30s ease-in-out;
        -ms-transition: all 0.30s ease-in-out;
        -o-transition: all 0.30s ease-in-out;
        outline: none;
        box-sizing: border-box;
        -webkit-box-sizing: border-box;
        -moz-box-sizing: border-box;
        width: 100%;
        background: #fff;
        margin-bottom: 4%;
        border: 1px solid;
        padding: 3%;
        font: 95% Arial, Helvetica, sans-serif;
    }
    .form-style input[type="text"]:focus,
    .form-style input[type="date"]:focus,
    .form-style input[type="datetime"]:focus,
    .form-style input[type="email"]:focus,
    .form-style input[type="number"]:focus,
    .form-style input[type="search"]:focus,
    .form-style input[type="time"]:focus,
    .form-style input[type="url"]:focus,
    .form-style textarea:focus,
    .form-style select:focus
    {
        box-shadow: 0 0 5px orange;
        padding: 3%;
        border: 1px solid orange;
    }

    .form-style input[type="submit"],
    .form-style input[type="button"] {
        box-sizing: border-box;
        -webkit-box-sizing: border-box;
        -moz-box-sizing: border-box;
        width: 100%;
        padding: 3%;
        background: orange;
        border-left: 8px solid orangered;
        border-top-style: none;
        border-right-style: none;
        border-bottom-style: none;
        /*border-left-style: none;*/
        color: #fff;
        font-weight: bold;
    }
    .form-style input[type="submit"]:hover,
    .form-style input[type="button"]:hover {
        background: darkorange;
        border-left: 8px solid orangered;
    }

    .form-style label{
        display: block;
        margin: 0px 0px 15px 0px;
    }
    .form-style label > span{
        width: 100px;
        font-weight: bold;
        float: left;
        padding-top: 8px;
        padding-right: 5px;
    }

    .form-style input.input-field, .form-style .select-field{
        width: 48%;
    }
</style>
<!--[if lt IE 9]>
<script type="text/javascript" defer="defer">
    window.onload=function(){var i,t=document.getElementsByTagName('table');for(i=0;i<t.length;i++){if(t[i].parentNode.className=='graph')t[i].style.height=150-(t[i].clientHeight-150)+'px';}}
</script>
<![endif]-->
</head>

<body>
<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
<div class="center">
    <h1><a name="top" href="?">:: Magento 2 Performance Review Script ::</a></h1>

    <div class="buttons">
        <a href="#opcache">OPCache</a>
        <a href="#phpini">PHP.ini</a>
        <a href="#mysql">MySQL</a>
        <a href="#php-fpm">PHP-FPM</a>
        <a href="#sysctl">Sysctl</a>
        <a href="#redis">Redis</a>
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

        if (isset($_POST) && !empty($_POST)) {
            $customConfigLocation = getCustomConfigLocation();
        } else {
            ?>
            <div class="form-style">
                <form action="#" method="post">
                    <h1>:: Custom Config Locations ::</h1>
                    <label for="phpfpm"><span><em>PHP-FPM</em>:</span> <input type="text" class="input-field" name="phpfpm" value="/etc/php/7.3/fpm/php-fpm.conf" /></label>
                    <label for="sysctl"><span><em>Sysctl</em>:</span> <input type="text" class="input-field" name="sysctl" value="/etc/sysctl.conf" /></label>
                    <label for="redis"><span><em>Redis</em>:</span> <input type="text" class="input-field" name="redis" value="/etc/redis/redis.conf" /></label>
                    <input type="submit" name="submit" value="Submit" />
                </form>
            </div>
            <?php
        }
    ?>
    <h2>Server Uptime: [ <?php echo getServerUptime(); ?> ] || Server Load (5min): [<span class="<?php echo $level ?>"><?php echo $serverLoad[1]; ?></span>] <?php echo 'PHP Version: ' . PHP_VERSION_ID; ?></h2>
    <button type="button" class="collapsible"><a name="opcache">OPCache Review</a> [<a href="#top">#top</a>]</button>
    <div class="content">
        <p><?php $opCacheMatch = printTable(getOpCacheConfig($customConfigLocation), $opCacheRecommendation, $configurationRemarks, $defaultHeaders); ?></p>
        <div id="opCacheChart"></div>
        <script type="text/javascript">
            // Load google charts
            google.charts.load('current', {'packages':['corechart']});
            google.charts.setOnLoadCallback(opCacheChart);

            // Draw the chart and set the chart values
            function opCacheChart() {
                var data = google.visualization.arrayToDataTable([
                    ['ID', 'Value'],
                    ['Matched', <?php echo $opCacheMatch['match']; ?>],
                    ['Unmatched', <?php echo $opCacheMatch['total'] - $opCacheMatch['match']; ?>],
                    ['Disable', <?php echo $opCacheMatch['disable']; ?>],
                    ['TBD', <?php echo $opCacheMatch['tbd']; ?>]
                ]);

                // Optional; add a title and set the width and height of the chart
                var options = {'title':'OPCache Review', 'width':1024, 'height':550};

                // Display the chart inside the <div> element with id="piechart"
                var opCacheChart = new google.visualization.PieChart(document.getElementById('opCacheChart'));
                opCacheChart.draw(data, options);
            }
        </script>
    </div>
    <button type="button" class="collapsible"><a name="phpini">PHP Configuration Review</a> [<a href="#top">#top</a>]</button>
    <div class="content">
        <p><?php $phpMatch = printTable($localPhpConfiguration, $phpRecommendation, $configurationRemarks, $defaultHeaders); ?></p>
        <div id="phpChart"></div>
        <script type="text/javascript">
            // Load google charts
            google.charts.load('current', {'packages':['corechart']});
            google.charts.setOnLoadCallback(phpChart);

            // Draw the chart and set the chart values
            function phpChart() {
                var data = google.visualization.arrayToDataTable([
                    ['ID', 'Value'],
                    ['Matched', <?php echo $phpMatch['match']; ?>],
                    ['Unmatched', <?php echo $phpMatch['total'] - $phpMatch['match']; ?>],
                    ['Disable', <?php echo $phpMatch['disable']; ?>],
                    ['TBD', <?php echo $phpMatch['tbd']; ?>]
                ]);

                // Optional; add a title and set the width and height of the chart
                var options = {'title':'PHP Review', 'width':1024, 'height':550};

                // Display the chart inside the <div> element with id="piechart"
                var phpChart = new google.visualization.PieChart(document.getElementById('phpChart'));
                phpChart.draw(data, options);
            }
        </script>
    </div>
    <button type="button" class="collapsible"><a name="mysql">MySQL Configuration Review</a> [<a href="#top">#top</a>]</button>
    <div class="content">
        <p><?php $mySQLMatch = printTable(getMySQLConfig($mySqlConfigurationFlags, $customConfigLocation), $mySQLRecommendation, $configurationRemarks, $defaultHeaders, "Output of <u>/usr/sbin/mysqld --help --verbose</u> has been used for this review.<br />Please Check Local MySQL Variables within MySQL Database Itself for Consistency: <u>mysql> show variables like '%innodb_file_per_table%';</u><br /><br />Recommend to scan the MySQL setting with <a href='https://raw.githubusercontent.com/major/MySQLTuner-perl/master/mysqltuner.pl' target='_blank'>MySQLTunner Script</a>"); ?></p>
        <div id="mySqlChart"></div>
        <script type="text/javascript">
            // Load google charts
            google.charts.load('current', {'packages':['corechart']});
            google.charts.setOnLoadCallback(mySqlChart);

            // Draw the chart and set the chart values
            function mySqlChart() {
                var data = google.visualization.arrayToDataTable([
                    ['ID', 'Value'],
                    ['Matched', <?php echo $mySQLMatch['match']; ?>],
                    ['Unmatched', <?php echo $mySQLMatch['total'] - $mySQLMatch['match']; ?>],
                    ['Disable', <?php echo $mySQLMatch['disable']; ?>],
                    ['TBD', <?php echo $mySQLMatch['tbd']; ?>]
                ]);

                // Optional; add a title and set the width and height of the chart
                var options = {'title':'MySQL Review', 'width':1024, 'height':550};

                // Display the chart inside the <div> element with id="piechart"
                var mySqlChart = new google.visualization.PieChart(document.getElementById('mySqlChart'));
                mySqlChart.draw(data, options);
            }
        </script>
    </div>
    <button type="button" class="collapsible"><a name="php-fpm">PHP-FPM Configuration Review</a> [<a href="#top">#top</a>]</button>
    <div class="content">
        <p><?php $phpFPMMatch = printTable(getPhpFPMConfig($phpFPMConfigurationFlags, $customConfigLocation), $phpFPMRecommendation, $configurationRemarks, $defaultHeaders, "Local PHP-FPM Location that has been used to Perform Review. Using first result line from: find <u>/etc/php -iname php-fpm.conf</u>"); ?></p>
        <div id="phpFPMChart"></div>
        <script type="text/javascript">
            // Load google charts
            google.charts.load('current', {'packages':['corechart']});
            google.charts.setOnLoadCallback(phpFPMChart);

            // Draw the chart and set the chart values
            function phpFPMChart() {
                var data = google.visualization.arrayToDataTable([
                    ['ID', 'Value'],
                    ['Matched', <?php echo $phpFPMMatch['match']; ?>],
                    ['Unmatched', <?php echo $phpFPMMatch['total'] - $phpFPMMatch['match']; ?>],
                    ['Disable', <?php echo $phpFPMMatch['disable']; ?>],
                    ['TBD', <?php echo $phpFPMMatch['tbd']; ?>]
                ]);

                // Optional; add a title and set the width and height of the chart
                var options = {'title':'PHP-FPM Review', 'width':1024, 'height':550};

                // Display the chart inside the <div> element with id="piechart"
                var phpFPMChart = new google.visualization.PieChart(document.getElementById('phpFPMChart'));
                phpFPMChart.draw(data, options);
            }
        </script>
    </div>
    <button type="button" class="collapsible"><a name="sysctl">Sysctl Configuration Review</a> [<a href="#top">#top</a>]</button>
    <div class="content">
        <p><?php $sysctlMatch = printTable(getSysctlConfig($sysctlConfigurationFlags, $customConfigLocation), $sysctlRecommendation, $configurationRemarks, $defaultHeaders, "Local sysctl Location that has been used to Perform Review: <u>/etc/sysctl.conf</u>"); ?></p>
        <div id="sysctlChart"></div>
        <script type="text/javascript">
            // Load google charts
            google.charts.load('current', {'packages':['corechart']});
            google.charts.setOnLoadCallback(sysctlChart);

            // Draw the chart and set the chart values
            function sysctlChart() {
                var data = google.visualization.arrayToDataTable([
                    ['ID', 'Value'],
                    ['Matched', <?php echo $sysctlMatch['match']; ?>],
                    ['Unmatched', <?php echo $sysctlMatch['total'] - $sysctlMatch['match']; ?>],
                    ['Disable', <?php echo $sysctlMatch['disable']; ?>],
                    ['TBD', <?php echo $sysctlMatch['tbd']; ?>]
                ]);

                // Optional; add a title and set the width and height of the chart
                var options = {'title':'sysctl Review', 'width':1024, 'height':550};

                // Display the chart inside the <div> element with id="piechart"
                var sysctlChart = new google.visualization.PieChart(document.getElementById('sysctlChart'));
                sysctlChart.draw(data, options);
            }
        </script>
    </div>
    <button type="button" class="collapsible"><a name="redis">Redis Configuration Review</a> [<a href="#top">#top</a>]</button>
    <div class="content">
        <p><?php $redisMatch = printTable(getRedisConfig($redisConfigurationFlags, $customConfigLocation), $redisRecommendation, $configurationRemarks, $defaultHeaders, "Local Redis Location that has been used to Perform Review: <u>/etc/redis/redis.conf</u>"); ?></p>
        <div id="redisChart"></div>
        <script type="text/javascript">
            // Load google charts
            google.charts.load('current', {'packages':['corechart']});
            google.charts.setOnLoadCallback(redisChart);

            // Draw the chart and set the chart values
            function redisChart() {
                var data = google.visualization.arrayToDataTable([
                    ['ID', 'Value'],
                    ['Matched', <?php echo $redisMatch['match']; ?>],
                    ['Unmatched', <?php echo $redisMatch['total'] - $redisMatch['match']; ?>],
                    ['Disable', <?php echo $redisMatch['disable']; ?>],
                    ['TBD', <?php echo $redisMatch['tbd']; ?>]
                ]);

                // Optional; add a title and set the width and height of the chart
                var options = {'title':'Redis Review', 'width':1024, 'height':550};

                // Display the chart inside the <div> element with id="piechart"
                var redisChart = new google.visualization.PieChart(document.getElementById('redisChart'));
                redisChart.draw(data, options);
            }
        </script>
    </div>
</div>

<script>
    var coll = document.getElementsByClassName("collapsible");
    var i;

    for (i = 0; i < coll.length; i++) {
        coll[i].addEventListener("click", function() {
            this.classList.toggle("active");
            var content = this.nextElementSibling;
            if (content.style.maxHeight){
                content.style.maxHeight = null;
            } else {
                content.style.maxHeight = content.scrollHeight + "px";
            }
        });
    }
</script>
</body>

<?php
function printTable($array, $recommendedValues = false, $configurationRemarks = false, $headers = false, $experimentalNote = false) {
    if ( empty($array) || !is_array($array) ) {return;}
    if ($experimentalNote) {
        echo "<blockquote>$experimentalNote</blockquote>";
    }
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
    $matches = array(
        'match'     => 0,
        'disable'   => 0,
        'tbd'       => 0,
        'total'     => count($array)
    );

    foreach ($array as $key => $value) {
        $configMatch    = 'v';
        $remark         = false;
        $remarkCSS      = 'remark';
        if (!is_array($value)) {
            $configMatch    = (array_key_exists($key, $recommendedValues) && !strcmp ($value, $recommendedValues[$key])) ? "configMatch" : "v";
            if (array_key_exists($key, $recommendedValues) && !strcmp ($value, $recommendedValues[$key])) {
                $configMatch = "configMatch";
                $matches['match']++;
            } else {
                $configMatch = "v";
            }

            $remark = (array_key_exists($key, $configurationRemarks)) ? $configurationRemarks[$key] : "TBD";

            if (!strcmp($remark, STATUS_DISABLE)) {
                $remarkCSS = "remark disable";
                $matches['disable']++;
            } elseif (!strcmp($remark, STATUS_TBD)) {
                $remarkCSS = "remark tbd";
                $matches['tbd']++;
            }
        }
        echo '<tr>';
        if (!is_numeric($key)) {
            echo '<td class="e">',$key,'</td>';
            if (is_numeric($value)) {
                if ($value>1048576) { $value = $value . ' (' .round($value/1048576,1).'M)'; }
                elseif (is_float($value)) { $value = $value . ' (' .round($value,1).')'; }
            }
        }
        if (is_array($value)) {
            foreach ($value as $column) {
                echo '<td class="v">',$column,'</td>';
            }

        } else {
            echo '<td class='.$configMatch.'>',$value,'</td>';
        }
        if (array_key_exists($key, $recommendedValues)) {
            if (is_numeric($value)) {
                if ($recommendedValues[$key] > 1048576) {
                    $recommendedValues[$key] = $recommendedValues[$key] . ' (' . round($recommendedValues[$key] / 1048576, 1) . 'M)';
                }
            }
            echo '<td class="v">', $recommendedValues[$key], '</td>';
            echo '<td class="'.$remarkCSS.'">' , $remark , '</td>';
        } else {
            echo '<td class="v">' ,$remark, '</td><td></td>';
        }
        echo '</tr>';
    }
    echo '</table><br />';

    echo '<table border="0" cellpadding="2" width="900">';
        echo  '<tr class="h">';
            echo '<th>What</th>';
            echo '<th>Status</th>';
        echo  '</tr>';
    echo  '<tr>';
        echo '<td class="e">Match</td>';
        echo '<td class="v">[',$matches['match'],'/<b>',$matches['total'],'</b>]</td>';
    echo  '</tr>';
    echo  '<tr>';
        echo '<td class="e">Unmatched</td>';
        echo '<td class="v">[',$matches['total'] - $matches['match'],'/<b>',$matches['total'],'</b>]</td>';
    echo  '</tr>';
    echo  '<tr>';
        echo '<td class="e">Disabled</td>';
        echo '<td class="v">[',$matches['disable'],'/<b>',$matches['total'],'</b>]</td>';
    echo  '</tr>';
    echo  '<tr>';
        echo '<td class="e">TBD</td>';
        echo '<td class="v">[',$matches['tbd'],'/<b>',$matches['total'],'</b>]</td>';
    echo  '</tr>';
    echo '</table>';

    return $matches;
}

function getCustomConfigLocation() {
    $customConfig = array(
        'phpfpm'    => false,
        'sysctl'    => false,
        'redis'     => false,
    );

    if (isset($_POST)) {
        $customConfig['phpfpm']     = (isset($_POST['phpfpm'])) ? filter_var($_POST['phpfpm'], FILTER_SANITIZE_STRIPPED) : false;
        $customConfig['sysctl']     = (isset($_POST['sysctl'])) ? filter_var($_POST['sysctl'], FILTER_SANITIZE_STRIPPED) : false;
        $customConfig['redis']      = (isset($_POST['redis'])) ? filter_var($_POST['redis'], FILTER_SANITIZE_STRIPPED) : false;
    }

    return $customConfig;
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

function getMySQLConfig($mySqlConfigurationFlags, $customConfigLocation = false) {
    $mySqlConfig            = array();
    $statusCommand          = "service mysql status | grep 'active (running)'";
    $mySqlStatus            = shell_exec($statusCommand);
    if ($mySqlStatus) {
        $fetchConfigCommand = "/usr/sbin/mysqld --help --verbose | grep -n 'Variables (--variable-name=value)'"; //Locate Line Number for "Variables (--variable-name=value) Section"
        $lineNumberResult   = shell_exec($fetchConfigCommand);
        if ($lineNumberResult) {
            $lineNumber     = explode(":", $lineNumberResult);
            foreach ($mySqlConfigurationFlags as $configFlag) {
                $mySqlConfig[$configFlag] = getMySQLConfigExactValue(shell_exec("/usr/sbin/mysqld --help --verbose | sed -n '$lineNumber[0],\$p' | grep -iw '^$configFlag'"));
            }
        }
    }

    return $mySqlConfig;
}

function getMySQLConfigExactValue($inputString = false) {
    $configValue = false;
    if ($inputString) {
        //Cleaning of Input, Getting the Last Element as it actually Contains Configuration Value
        $inputString = trim($inputString);
        $inputValues = explode(' ', $inputString);
        $configValue = array_pop($inputValues);
    }
    return $configValue;
}

function getPhpFPMConfig($phpFPMConfigurationFlags, $customConfigLocation = false) {
    $phpFPMConfig           = array();
    $defaultFPMLocation     = ($customConfigLocation['phpfpm']) ? $customConfigLocation['phpfpm'] : '/etc/php';
    if (file_exists($defaultFPMLocation)) {
        $phpFPMLocations = ($customConfigLocation['phpfpm']) ? $customConfigLocation['phpfpm'] : explode(PHP_EOL, shell_exec("find $defaultFPMLocation -iname php-fpm.conf"));
        if ($phpFPMLocations) {
            $phpFPMReadLocation = ($customConfigLocation['phpfpm']) ? $customConfigLocation['phpfpm'] : reset($phpFPMLocations);
            $phpFPMConfig['php-fpm_config'] = $phpFPMReadLocation;
            foreach ($phpFPMConfigurationFlags as $configFlag) {
                $configValueString              = shell_exec('grep -iw "'.$configFlag.'" ' . $phpFPMReadLocation);
                $phpFPMConfig[$configFlag]      = false;
                if ($configValueString) {
                    $configValue                = explode(" = ", $configValueString);
                    $phpFPMConfig[$configFlag]  = $configValue[1]; //Pattern: ConfigName = ConfigValue
                }
            }
        }
    }

    return $phpFPMConfig;
}

function getSysctlConfig($sysctlConfigurationFlags, $customConfigLocation = false) {
    $sysctlConfig           = array();
    $sysctlConfigLocation   = ($customConfigLocation['sysctl']) ? $customConfigLocation['sysctl'] : '/etc/sysctl.conf';
    if (file_exists($sysctlConfigLocation)) {
        $sysctlConfig['sysctl_config']              = $sysctlConfigLocation;
        foreach ($sysctlConfigurationFlags as $configFlag) {
            $configValueString              = shell_exec('grep -iw "'.$configFlag.'" ' . $sysctlConfigLocation);
            $sysctlConfig[$configFlag]      = false;
            if ($configValueString) {
                $configValue                = explode(" = ", $configValueString);
                $sysctlConfig[$configFlag]  = $configValue[1];
            }
        }
    }

    return $sysctlConfig;
}

function getRedisConfig($redisConfigurationFlags, $customConfigLocation = false) {
    $redisConfig           = array();
    $redisConfigLocation   = ($customConfigLocation['redis']) ? $customConfigLocation['redis'] : '/etc/redis/redis.conf';
    if (file_exists($redisConfigLocation)) {
        $redisConfig['redis_config']                = $redisConfigLocation;
        foreach ($redisConfigurationFlags as $configFlag) {
            $configValueString          = shell_exec('grep -iw "'.$configFlag.'" ' . $redisConfigLocation);
            $redisConfig[$configFlag]   = false;
            if ($configValueString) {
                $configValue                = explode(" = ", $configValueString);
                $redisConfig[$configFlag]   = $configValue[1];
            }
        }
    }

    return $redisConfig;
}
