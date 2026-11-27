<?php

declare(strict_types=1);

return [
    // Kill switch is OFF until separately approved and configured.
    'execution' => [
        'enabled' => env('ARNA_AI_EXECUTION_ENABLED', false),
        'max_input_tokens' => (int) env('ARNA_AI_MAX_INPUT_TOKENS', 2048),
        'max_output_tokens' => (int) env('ARNA_AI_MAX_OUTPUT_TOKENS', 2048),
        // Zero means no spend is authorized; a positive cap needs Owner approval.
        'monthly_budget_etb' => (float) env('ARNA_AI_MONTHLY_BUDGET_ETB', 0),
    ],
    'provider' => [
        'enabled' => false,
        'name' => null,
        'egress_enabled' => false,
    ],
];
