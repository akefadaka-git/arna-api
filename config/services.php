<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'corporate_site' => [
        'url' => env('CORPORATE_SITE_URL'),
    ],

    'corporate_media_scanner' => [
        'url' => env('CORPORATE_MEDIA_SCANNER_URL'),
        'token' => env('CORPORATE_MEDIA_SCANNER_TOKEN'),
        // Base64-encoded Ed25519 public key pinned by the API for scanner verdict verification.
        'public_key' => env('CORPORATE_MEDIA_SCANNER_PUBLIC_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'arna_provider_mutations' => [
        // Both gates default off. Sandbox execution never implies production authorization.
        'enabled' => env('ARNA_PROVIDER_MUTATIONS_ENABLED', false),
        'sandbox_enabled' => env('ARNA_PROVIDER_SANDBOX_MUTATIONS_ENABLED', false),
        'certification_enabled' => env('ARNA_PROVIDER_SANDBOX_CERTIFICATION_ENABLED', false),
    ],

    // Dedicated provider-action Owner trust anchor; never reuse tenant RBAC or settings keys.
    'arna_provider_owner' => [
        'key_id' => env('ARNA_PROVIDER_OWNER_KEY_ID'),
        'public_key' => env('ARNA_PROVIDER_OWNER_PUBLIC_KEY'), // Base64 raw Ed25519 public key (32 bytes).
    ],

];
