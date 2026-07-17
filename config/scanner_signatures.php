<?php
// config/scanner_signatures.php
return [
    'version' => '2.0.0',

    // Plaintext substring signatures by category. Substring match, case-insensitive.
    'string_patterns' => [
        'backdoor' => [
            'eval(base64_decode('        => 'Base64 eval execution',
            'eval(gzinflate('            => 'Compressed eval execution',
            'eval(str_rot13('            => 'ROT13 eval execution',
            'eval(gzuncompress('         => 'Compressed eval execution',
            'eval(gzdecode('             => 'Compressed eval execution',
            'assert(base64_decode('      => 'Base64 assert execution',
            'assert(gzinflate('          => 'Compressed assert execution',
            'create_function('           => 'Dynamic function creation (deprecated)',
            'eval(curl_exec('            => 'Remote code execution via curl+eval (C2 backdoor)',
            'eval(file_get_contents('    => 'Remote code execution via URL fetch+eval',
            'eval(wp_remote_retrieve_body(' => 'Remote code execution via WP HTTP+eval',
            'move_uploaded_file($_FILES' => 'File upload backdoor',
            'copy($_FILES'               => 'File copy backdoor',
        ],
        'shell' => [
            'shell_exec($_'  => 'Shell execution from user input',
            'system($_'      => 'System call from user input',
            'passthru($_'    => 'Passthrough from user input',
            'exec($_'        => 'Exec from user input',
            'popen($_'       => 'Process open from user input',
            'proc_open($_'   => 'Process open from user input',
            'pcntl_exec('    => 'Process execution',
        ],
        'file_operation' => [
            'file_put_contents($_'          => 'File write from user input',
            'fwrite($fp, base64_decode'     => 'Base64 file write',
        ],
        'injection' => [
            'base64_decode('        => 'Base64 decode (PHP context)',
            'gzinflate(base64_decode(' => 'Compressed base64 payload',
            'str_rot13('            => 'ROT13 obfuscation',
            'chr(hexdec('           => 'Hex character obfuscation',
        ],
    ],

    // Regex patterns. Each: pattern (full PCRE with delimiters+flags), description, severity, category.
    'regex_patterns' => [
        ['pattern' => '/\$[a-z_]+\s*=\s*[\'"]([\\\\x0-9a-f]{20,})[\'"]/i', 'description' => 'Hex-encoded string assignment (obfuscation)', 'severity' => 'medium', 'category' => 'obfuscation'],
        ['pattern' => '/preg_replace\s*\(\s*[\'"].*\/e[\'"]/i', 'description' => 'preg_replace /e modifier (code execution)', 'severity' => 'critical', 'category' => 'backdoor'],
        ['pattern' => '/\$\{[\'"]?_[A-Z]+[\'"]?\}/', 'description' => 'Variable-variable superglobal access (obfuscated input)', 'severity' => 'high', 'category' => 'injection'],
    ],

    // SEO-spam keywords (post title/content scanning).
    'spam_keywords' => [
        'casino', 'gambling', 'poker', 'blackjack', 'slot machine', 'roulette',
        'spielautomaten', 'glücksspiel', 'freispiele', 'online casino',
        'beste blackjack', 'casino kostenlose', 'casino um echtes geld',
        'gama casino', 'casino mit kreditkarte',
        'gucci', 'louis vuitton', 'michael kors', 'prada', 'hermes bag',
        'replica watch', 'fake rolex', 'cheap nike',
        'viagra', 'cialis', 'levitra', 'kamagra', 'pharmacy online',
        'payday loan', 'bitcoin trading', 'crypto trading', 'forex signal',
        'buy backlinks', 'cheap seo', 'link building service',
    ],

    // Known malicious wp_options key patterns (LIKE patterns, % = wildcard).
    'suspicious_option_patterns' => [
        'wp_custom_filters', 'wp_custom_range', 'home_links_custom_%',
        'wp_check_hash', 'wp_auth_key_hash', 'wp_statistic_data',
        'wp_system_update', 'wp_recovery_data', 'core_update_check',
        '_site_transient_browser_%',
    ],

    // Suspicious cron hook name regexes.
    'suspicious_cron_regexes' => [
        '/^[a-f0-9]{8,}$/',
        '/^wp_[a-f0-9]{6,}$/',
        '/^[a-z]{1,3}_[a-f0-9]{8,}$/',
    ],

    // Fake plugin directory name regexes.
    'fake_plugin_patterns' => [
        '/^developer[-_]?tool/i', '/^wp[-_]?file[-_]?manager$/i',
        '/^cache[-_]?manager[-_]?plus$/i', '/^[a-f0-9]{8,}$/',
        '/^wp[-_]?system[-_]?update$/i', '/^maintenance[-_]?tool$/i',
        '/^db[-_]?backup[-_]?tool$/i', '/^site[-_]?health[-_]?monitor$/i',
        '/^security[-_]?patch$/i',
    ],

    // .htaccess malicious rule patterns.
    'htaccess_patterns' => [
        ['pattern' => '/RewriteCond.*HTTP_USER_AGENT.*(googlebot|bingbot|yahoo|msnbot|crawl|spider)/i', 'description' => 'User-Agent based conditional rewrite (SEO cloaking)', 'severity' => 'critical'],
        ['pattern' => '/AddHandler.*php.*\.(jpg|jpeg|png|gif|ico|bmp|svg|txt|css)/i', 'description' => 'Forcing PHP execution on non-PHP file types', 'severity' => 'critical'],
        ['pattern' => '/AddType.*php.*\.(jpg|jpeg|png|gif|ico|bmp|svg|txt|css)/i', 'description' => 'Mapping PHP MIME type to non-PHP extensions', 'severity' => 'critical'],
        ['pattern' => '/php_value.*auto_prepend_file/i', 'description' => 'PHP auto_prepend_file directive (code injection)', 'severity' => 'critical'],
        ['pattern' => '/php_value.*auto_append_file/i', 'description' => 'PHP auto_append_file directive (code injection)', 'severity' => 'critical'],
        ['pattern' => '/base64_decode|eval\s*\(/i', 'description' => 'Obfuscated code in .htaccess', 'severity' => 'critical'],
        ['pattern' => '/RewriteRule.*\.(ru|cn|tk|pw|top|xyz|cc|su|icu)\//i', 'description' => 'Redirect to suspicious TLD', 'severity' => 'high'],
        ['pattern' => '/SecFilterEngine\s+Off|SecRuleEngine\s+Off/i', 'description' => 'ModSecurity disabled via .htaccess', 'severity' => 'high'],
    ],
];
