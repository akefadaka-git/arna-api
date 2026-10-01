<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P3 follow-up · File 4 of 5
 * =============================================================================
 *
 * File:            Resources/lang/en/status.php  (NEW)
 * Unit:            Register the Shareholder domain's translation namespace
 * Delivery type:   New file
 * Net lines:       +…
 *
 * Before:
 *   The Shareholder domain had no `Resources/lang/en/` directory. Even
 *   after File 2 (`am/status.php`) existed, an English-locale request
 *   would have fallen through to the fallback locale — also `en` — and
 *   returned the key string, because the `en` status catalogue did not
 *   exist either.
 *
 *   The visible symptom in the `en` locale: `shareholder::status.active`
 *   and `shareholder::status.inactive` rendered verbatim in the identity
 *   card and in the workspace's shareholder list and detail views.
 *
 * After:
 *   The English status catalogue is on disk at the path the Shareholder
 *   provider will register with Laravel's translator (pending File 5 of
 *   this sequence). Once both this file and File 5 are deployed,
 *   `__('shareholder::status.active', locale: 'en')` resolves to
 *   'Active'.
 *
 *   The two keys are byte-for-byte identical to File 2's keys (`active`,
 *   `inactive`). Laravel resolves translations per-locale; the key set
 *   is what must be consistent across catalogues, not the values. A key
 *   present in `am/status.php` and absent from `en/status.php` would
 *   fall through to the fallback locale and — because the fallback is
 *   also `en` — return the key string, which is the failure this file
 *   prevents.
 *
 * =============================================================================
 * Values — English-language labels
 * =============================================================================
 * The two English strings are:
 *
 *     active   → Active
 *     inactive → Inactive
 *
 * Each value is the state rendered as a single capitalised word. The
 * two-word English form ("Not active") would be a legitimate
 * alternative; the codebase uses the single-word "Inactive" for every
 * other status label (share classes, tenants, applications), and this
 * file follows that precedent for consistency.
 *
 * The English catalogue is a fallback surface, not a primary display
 * surface: the platform is Amharic-first. Its two roles match File 3's:
 *
 *   1. A request whose resolved locale is `en` returns English labels
 *      rather than key strings.
 *
 *   2. A missing key in `am/status.php` falls through to this file and
 *      returns an English label rather than the raw key. The English
 *      catalogue is therefore a safety net for Amharic gaps.
 *
 * =============================================================================
 * Relationship to the frontend's English labels
 * =============================================================================
 * As with File 3, the frontend has no English mirror for this
 * catalogue. The frontend's `features/shareholders/labels.ts` is
 * single-language: Amharic values only, regardless of the store's
 * locale. There is therefore no cross-file value alignment check for
 * this file. A future CR that adds an English UI to the shareholder
 * vertical would gain an English catalogue in that frontend file, and
 * its values would be matched against the strings here.
 *
 * =============================================================================
 * Fallback behaviour
 * =============================================================================
 * If a future CR introduces a third lifecycle state on shareholders
 * (for example, a `suspended` state distinct from `is_active = false`),
 * the resource would need a new `status_label` branch, a new key in
 * `am/status.php`, and a new key in this file. The vocabulary is
 * closed at two entries because the underlying `is_active` column is a
 * boolean.
 *
 * The recommended practice for any CR that extends the lifecycle is to
 * add the key to all three surfaces in the same commit: the resource's
 * `resolveStatusLabel()`, `am/status.php`, and `en/status.php`.
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
    | Shareholder Status — English
    |--------------------------------------------------------------------------
    |
    | The English-language catalogue for the two status values the
    | ShareholderResource emits for `status_label`. The keys are
    | identical to `am/status.php`; only the values differ.
    |
    | This catalogue is the fallback surface for the platform's
    | Amharic-first policy: an English-locale request resolves here, and
    | a key missing from the Amharic catalogue also resolves here
    | (Laravel falls through to the fallback locale).
    |
    | A future CR that extends the lifecycle adds a key here and to
    | `am/status.php` in the same change.
    |
    */

    'active'   => 'Active',
    'inactive' => 'Inactive',

];