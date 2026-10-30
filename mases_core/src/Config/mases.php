<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Platform Name
    |--------------------------------------------------------------------------
    |
    | The human-readable name of the platform. Used in emails, notifications,
    | and UI titles where the application name is displayed.
    |
    */
    'name' => env('MASES_APP_NAME', 'MASES'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The current application environment. This determines which configuration
    | settings are active (e.g., production, staging, local). It should be
    | set in the .env file as APP_ENV.
    |
    */
    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Tenancy Configuration
    |--------------------------------------------------------------------------
    |
    | MASES is a multi-tenant platform. These settings control how tenant
    | context is resolved and enforced across all tenant-aware services.
    |
    | - default_tenant_id: The tenant to use when no context can be resolved
    |   (e.g., for system-level operations). Set to null to require explicit
    |   tenant resolution for all tenant-sensitive operations.
    |
    | - context_resolver: The class responsible for resolving the current
    |   tenant context from the request (domain, header, or session).
    |   This must implement TenantContextResolverInterface.
    |
    | - isolation_enabled: When true, all tenant-sensitive queries will
    |   automatically be scoped to the current tenant. Disabling this
    |   effectively turns off tenant isolation (not recommended for production).
    |
    */
    'tenant' => [
        'default_tenant_id' => null,
        'context_resolver' => \MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver::class,
        'isolation_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Registry
    |--------------------------------------------------------------------------
    |
    | This registry defines all MASES applications that are part of the
    | ecosystem. Each entry includes the application's unique slug, display
    | name, domain, and optional description. This registry is used by the
    | launcher and for authorization checks (application access).
    |
    | The 'slug' is the canonical identifier used in database relations and
    | permission checks. It must be unique and immutable.
    |
    | Applications listed here are NOT the same as business domains.
    | They are user-facing application interfaces (e.g., Finance UI, HR UI).
    |
    */
    'applications' => [
        [
            'slug' => 'portal',
            'name' => 'Shareholder Portal',
            'domain' => 'portal.maalearna.com.et',
            'description' => 'Shareholder information and engagement portal.',
        ],
        ...\\MaaleArna\\MASES\\Core\\Domains\\Finance\\Config\\FinanceApplicationCatalogue::legacyConfigRows(),
        [
            'slug' => 'hr',
            'name' => 'Human Resources',
            'domain' => 'hr.maalearna.com.et',
            'description' => 'HR management, payroll, and personnel.',
        ],
        [
            'slug' => 'admin',
            'name' => 'Administration',
            'domain' => 'admin.maalearna.com.et',
            'description' => 'Platform administration and configuration.',
        ],
        [
            'slug' => 'arna',
            'name' => 'ARNA AI Assistant',
            'domain' => 'arna.maalearna.com.et',
            'description' => 'AI-powered assistant for users and public.',
        ],
        [
            'slug' => 'app',
            'name' => 'MASES Workspace',
            'domain' => 'app.maalearna.com.et',
            'description' => 'Central workspace and unified dashboard.',
        ],
        [
            'slug' => 'launcher',
            'name' => 'Application Launcher',
            'domain' => 'launcher.maalearna.com.et',
            'description' => 'Gateway to authorized applications.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Platform-wide security settings that govern authentication, session
    | management, and defensive measures.
    |
    | - rate_limiting: Default number of requests per minute for API routes
    |   (can be overridden per route).
    |
    | - cors: Default CORS configuration for API responses. Allowed origins
    |   should be restricted to known frontend domains in production.
    |
    | - session_lifetime: User session duration in minutes.
    |
    | - password_reset_expiry: Password reset token lifetime in minutes.
    |
    */
    'security' => [
        'rate_limiting' => (int) env('MASES_RATE_LIMIT', 60),
        'cors' => [
            'allowed_origins' => array_filter(explode(',', env('MASES_CORS_ALLOWED_ORIGINS', ''))),
            'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'X-Requested-With', 'X-CSRF-TOKEN', 'Authorization'],
            'max_age' => 86400,
        ],
        'session_lifetime' => (int) env('MASES_SESSION_LIFETIME', 120),
        'password_reset_expiry' => (int) env('MASES_PASSWORD_RESET_EXPIRY', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization Defaults
    |--------------------------------------------------------------------------
    |
    | MASES is Amharic-first but supports multiple locales. These settings
    | define the default and fallback locales, as well as the system timezone.
    |
    | - default_locale: The primary locale (Amharic).
    | - fallback_locale: Used when a translation is missing in the default.
    | - timezone: The default timezone for display and business logic.
    |   (Database timestamps are stored in UTC; this only affects display.)
    |
    | The Ethiopian calendar is handled separately in the localization package.
    |
    */
    'localization' => [
        'default_locale' => env('MASES_DEFAULT_LOCALE', 'am'),
        'fallback_locale' => env('MASES_FALLBACK_LOCALE', 'en'),
        'timezone' => env('APP_TIMEZONE', 'Africa/Addis_Ababa'),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Service Defaults
    |--------------------------------------------------------------------------
    |
    | Core file-service settings. These govern upload limits, allowed types,
    | and storage disks. Actual business logic (versioning, sharing, etc.)
    | will be built in later phases.
    |
    | - max_upload_size: Maximum file size in megabytes.
    | - allowed_extensions: List of allowed file extensions.
    | - disk: Default storage disk ('local', 's3', etc.).
    |
    */
    'files' => [
        'max_upload_size' => (int) env('MASES_MAX_UPLOAD_SIZE', 100),
        'allowed_extensions' => [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'jpg', 'jpeg', 'png', 'gif', 'svg',
            'txt', 'csv', 'zip', 'rar',
        ],
        'disk' => env('MASES_FILE_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable or disable platform features. This allows gradual rollout of
    | new capabilities and provides a way to disable features in production
    | without code changes.
    |
    | Each flag should be set via environment variable for runtime control.
    |
    */
    'features' => [
        'audit_enabled' => env('MASES_FEATURE_AUDIT', true),
        'mfa_required' => env('MASES_FEATURE_MFA_REQUIRED', false),
        'tenant_aware_logging' => env('MASES_FEATURE_TENANT_LOGGING', true),
        'api_versioning' => env('MASES_FEATURE_API_VERSIONING', false),
    ],
];