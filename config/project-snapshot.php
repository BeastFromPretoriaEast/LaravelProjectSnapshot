<?php

return [
    /**
     * Output filename (relative to storage/app) OR an absolute path.
     */
    'output' => 'project.snapshot.md',

    /**
     * Directories/files (relative to project root) to scan.
     */
    'include' => [
        'app',
        'routes',
        'config',
        'database',
        'resources/views',
        'resources/js',
        'resources/css',
    ],

    /**
     * Exclude by directory/prefix (relative paths).
     * If a file path starts with any of these prefixes, it is excluded.
     */
    'exclude_paths' => [
        'vendor',
        'node_modules',
        'storage',
        'bootstrap/cache',
        '.git',
        '.idea',
        '.vscode',
    ],

    /**
     * Exclude by file pattern (glob). Matched against:
     *  - the relative path (e.g. "config/services.php")
     *  - the basename (e.g. ".env")
     *
     * Examples:
     *  - ".env*" excludes .env, .env.example, .env.production, etc.
     *  - "oauth-*.key" excludes oauth-private.key and oauth-public.key
     */
    'exclude_files' => [
        '.env*',
        'oauth-*.key',
        '*.pem',
        '*.pfx',
        '*.p12',
        '*id_rsa*',
        '*id_ed25519*',
    ],

    /**
     * Only include these extensions (whitelist).
     */
    'allowed_extensions' => [
        'php', 'js', 'ts', 'css', 'scss', 'json', 'md', 'yml', 'yaml',
    ],

    /**
     * Snapshot metadata block settings.
     */
    'metadata' => [
        'enabled'   => true,
        'generator' => 'infopixel/laravel-project-snapshot',
        // You can override the displayed project name; otherwise app.name will be used.
        'project_name' => null,
    ],

    /**
     * Secret scrubbing (tight + sensitive-only).
     */
    'scrub' => [
        'enabled'   => true,
        'redaction' => '***REDACTED***',

        'patterns' => [
            // Authorization: Bearer <token>
            '/\bAuthorization\s*:\s*Bearer\s+([A-Za-z0-9\-\._~\+\/]+=*)/i',

            // JWT
            '/\b(eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,})\b/',

            // AWS Access Key ID
            '/\b(AKIA[0-9A-Z]{16})\b/',

            // Stripe secret keys
            '/\b(sk_(?:live|test)_[A-Za-z0-9]{16,})\b/',

            // token="...", password: "...", secret = "..."
            '/\b(api[_-]?key|secret|token|password|passphrase|private[_-]?key)\b\s*[:=]\s*[\'"]([^\'"]{12,})[\'"]/i',
        ],

        'key_allowlist' => [
            'APP_KEY',
            'STRIPE_SECRET',
            'MAIL_PASSWORD',
            'AWS_ACCESS_KEY_ID',
            'AWS_SECRET_ACCESS_KEY',
            'JWT_SECRET',
            'API_KEY',
            'SECRET',
            'TOKEN',
            'PASSWORD',
            'PRIVATE_KEY',
        ],
    ],
];
