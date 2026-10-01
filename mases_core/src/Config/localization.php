<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ethiopian Calendar Configuration
    |--------------------------------------------------------------------------
    |
    | Defines the mapping between Ethiopian and Gregorian calendars.
    |
    */
    'ethiopian' => [
        // Year offset (Ethiopian year = Gregorian year - 7 or 8 depending on month)
        'year_offset' => env('MASES_ETHIOPIAN_YEAR_OFFSET', 7),

        // Month names in Amharic
        'month_names' => [
            'am' => ['መስከረም', 'ጥቅምት', 'ህዳር', 'ታህሳስ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'],
            'en' => ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazia', 'Ginbot', 'Sene', 'Hamle', 'Nehasse', 'Pagume'],
        ],

        // Day names in Amharic
        'day_names' => [
            'am' => ['እሑድ', 'ሰኞ', 'ማክሰኞ', 'ረቡዕ', 'ሐሙስ', 'አርብ', 'ቅዳሜ'],
            'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Date/Time Formatting
    |--------------------------------------------------------------------------
    |
    | Default formats for displaying dates and times in Ethiopian context.
    |
    */
    'formats' => [
        'date' => env('MASES_DATE_FORMAT', 'd/m/Y'),
        'datetime' => env('MASES_DATETIME_FORMAT', 'd/m/Y H:i'),
        'time' => env('MASES_TIME_FORMAT', 'H:i'),
        'ethiopian_date' => env('MASES_ETHIOPIAN_DATE_FORMAT', 'd F Y'), // e.g., 12 Meskerem 2017
    ],

    /*
    |--------------------------------------------------------------------------
    | Number Formatting
    |--------------------------------------------------------------------------
    |
    | Locale-specific number formatting.
    |
    */
    'number' => [
        'decimal_separator' => env('MASES_DECIMAL_SEPARATOR', '.'),
        'thousands_separator' => env('MASES_THOUSANDS_SEPARATOR', ','),
        'currency_symbol' => env('MASES_CURRENCY_SYMBOL', 'Br'),
        'currency_position' => env('MASES_CURRENCY_POSITION', 'before'), // 'before' or 'after'
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale Mapping
    |--------------------------------------------------------------------------
    |
    | Map short codes to full locale names.
    |
    */
    'locales' => [
        'am' => 'am_ET',
        'en' => 'en_US',
        'om' => 'om_ET',
        'ti' => 'ti_ET',
    ],

    /*
    |--------------------------------------------------------------------------
    | Transliteration
    |--------------------------------------------------------------------------
    |
    | Rules for transliterating Amharic to Latin script.
    |
    */
    'transliteration' => [
        'enabled' => env('MASES_TRANSLITERATION_ENABLED', true),
        'table' => [
            // Simple mapping example; full table should be defined in a service class
            // This is just a placeholder; actual transliteration will be handled by a service.
        ],
    ],
];