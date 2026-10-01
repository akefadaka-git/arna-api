<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P3 follow-up · File 2 of 5
 * =============================================================================
 *
 * File:            Resources/lang/am/status.php  (NEW)
 * Unit:            Register the Shareholder domain's translation namespace
 * Delivery type:   New file
 * Net lines:       +…
 *
 * Before:
 *   The Shareholder domain had no Resources/lang/ directory. Every call
 *   to `__('shareholder::status.*')` — issued from
 *   ShareholderResource::resolveStatusLabel() — returned its key
 *   verbatim, because Laravel's translator had no `shareholder`
 *   namespace registered and no Amharic status catalogue to resolve
 *   against.
 *
 *   The visible symptom: a shareholder's active/inactive status
 *   rendered as the literal string "shareholder::status.active" in the
 *   identity card and in the workspace's shareholder list and detail
 *   views.
 *
 * After:
 *   The Amharic status catalogue is on disk at the path the Shareholder
 *   provider will register with Laravel's translator (pending File 5 of
 *   this sequence). Once both this file and File 5 are deployed,
 *   `__('shareholder::status.active')` resolves to 'ንቁ'.
 *
 *   The resource calls `__('shareholder::status.active')` or
 *   `__('shareholder::status.inactive')` based on the shareholder's
 *   `is_active` boolean. The two keys are the complete vocabulary the
 *   resource consults; there is no third state.
 *
 * =============================================================================
 * Values — drawn from the frontend's existing catalogue
 * =============================================================================
 * The two Amharic strings are byte-for-byte identical to the values
 * already declared in mase_app/src/features/shareholders/labels.ts
 * (v1.1.0), in two separate label objects:
 *
 *   shareholderListViewLabels (lines ~526–527):
 *     activeFilterActive:   'ንቁ'
 *     activeFilterInactive: 'ንቁ ያልሆነ'
 *
 *   shareClassListViewLabels (lines ~594–595):
 *     statusActive:   'ንቁ'
 *     statusInactive: 'ንቁ ያልሆነ'
 *
 * The same two strings also appear in the shell and launcher label
 * sets, where the same Amharic terms render the "active" concept for
 * tenants and applications. Reusing them here means the status renders
 * identically whether the string came from the API's `status_label`
 * field (backend, this file) or from the frontend's own label
 * module — the two surfaces cannot drift.
 *
 * A future CR that adjusts the rendering of active/inactive must
 * change this file and the two frontend label objects together.
 *
 * =============================================================================
 * Native-speaker review
 * =============================================================================
 * Neither of the two values is flagged for review. 'ንቁ' (active) and
 * 'ንቁ ያልሆነ' (inactive) are the standard Amharic terms for this concept
 * and are consistent with the shell's tenant-active label and the
 * launcher's application-active label. No known alternate rendering is
 * in use in the codebase.
 *
 * =============================================================================
 * Fallback behaviour
 * =============================================================================
 * If a future CR introduces a third lifecycle state on shareholders
 * (for example, a `suspended` state distinct from `is_active = false`),
 * the resource would need a new `status_label` branch and a
 * corresponding key here. The current catalogue is closed at two
 * entries because the underlying `is_active` column is a boolean.
 *
 * Files modified:            none
 * Files added:               this file only
 * Files deleted:             none
 * Namespace registered by:   ShareholderServiceProvider (File 5 of this
 *                            sequence adds the loadTranslationsFrom()
 *                            call)
 * =============================================================================
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Shareholder Status
    |--------------------------------------------------------------------------
    |
    | The two values the ShareholderResource emits for `status_label`,
    | resolved by ShareholderResource::resolveStatusLabel() against the
    | shareholder's `is_active` boolean:
    |
    |     $shareholder->is_active
    |         ? __('shareholder::status.active')
    |         : __('shareholder::status.inactive')
    |
    | The vocabulary is closed at two entries because the underlying
    | column is a boolean. A future lifecycle state would require both a
    | schema change and a new key here.
    |
    */

    'active'   => 'ንቁ',
    'inactive' => 'ንቁ ያልሆነ',

];