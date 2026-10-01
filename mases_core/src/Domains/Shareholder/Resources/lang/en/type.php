<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P3 follow-up · File 3 of 5
 * =============================================================================
 *
 * File:            Resources/lang/en/type.php  (NEW)
 * Unit:            Register the Shareholder domain's translation namespace
 * Delivery type:   New file
 * Net lines:       +…
 *
 * Before:
 *   The Shareholder domain had no Resources/lang/ directory. Even after
 *   an `am/` catalogue existed, an English-locale request would have
 *   fallen through to Laravel's `fallback_locale` — which is also `en`
 *   — and returned the key string unchanged, because the `en`
 *   catalogue did not exist either.
 *
 *   The visible symptom in the `en` locale is the same as in `am`:
 *   `shareholder::type.individual` rendered verbatim in the identity
 *   card and in the workspace's shareholder list and detail views.
 *
 * After:
 *   The English type catalogue is on disk at the path the Shareholder
 *   provider will register with Laravel's translator (pending File 5 of
 *   this sequence). Once both this file and File 5 are deployed,
 *   `__('shareholder::type.individual', locale: 'en')` resolves to
 *   'Individual'.
 *
 *   The six keys are byte-for-byte identical to File 1's keys. Laravel
 *   resolves translations per-locale; the key set is what must be
 *   consistent across catalogues, not the values. A key present in
 *   `am/type.php` and absent from `en/type.php` would fall through to
 *   the fallback locale and — because the fallback is also `en` —
 *   return the key string, which is the failure this file prevents.
 *
 * =============================================================================
 * Values — English-language labels
 * =============================================================================
 * The six English strings are:
 *
 *     individual     → Individual
 *     company        → Company
 *     trust          → Trust
 *     cooperative    → Cooperative
 *     government     → Government
 *     religious_body → Religious body
 *
 * Each value is the ShareholderType case rendered as a human-readable
 * label: the enum's `value` string, capitalised on its first letter,
 * with the underscore in `religious_body` expanded to a space.
 *
 * The English catalogue is a fallback surface, not a primary display
 * surface: the platform is Amharic-first. It exists so that:
 *
 *   1. A request whose resolved locale is `en` returns English labels
 *      rather than key strings. The SPA's locale store may be set to
 *      `en` by a user who has chosen English, and the API must
 *      respond in kind.
 *
 *   2. A missing key in `am/type.php` (a future CR adds a seventh type
 *      to the enum and forgets the Amharic string) falls through to
 *      the `en` catalogue and returns an English label rather than the
 *      raw key. The English catalogue is therefore a safety net for
 *      Amharic gaps, not merely a translation.
 *
 * =============================================================================
 * Relationship to the frontend's English labels
 * =============================================================================
 * The frontend does not have an English mirror of this catalogue. The
 * frontend's `features/shareholders/labels.ts` is single-language:
 * Amharic values only, regardless of the store's locale. Its header
 * documents this (§"Purpose": "The shareholder vertical's user-facing
 * strings, in Amharic"). If the platform later adds an English UI for
 * the shareholder vertical, that file gains an English catalogue in a
 * future CR; the backend catalogues delivered here are the reference
 * for it.
 *
 * Consequently there is no cross-file value alignment check for this
 * file. The English labels are purely backend; the frontend consumes
 * them via the API's `type_label` field only when the resolved locale
 * is `en`, and in that case it renders the backend's string verbatim.
 *
 * =============================================================================
 * Fallback behaviour
 * =============================================================================
 * If a future CR extends the ShareholderType enum with a seventh case
 * and the case is added to `am/type.php` but not here, an English-
 * locale request renders the key string. If the case is added here but
 * not to `am/type.php`, an Amharic-locale request falls through to this
 * file (via `fallback_locale = en`) and renders the English label —
 * a graceful degradation, but still a gap for the reviewer to close.
 *
 * The recommended practice for any CR that extends the enum is to add
 * the key to all three files in the same commit: the enum, `am/type.php`,
 * and `en/type.php`.
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
    | Shareholder Types (D9) — English
    |--------------------------------------------------------------------------
    |
    | The English-language catalogue for the six ShareholderType cases.
    | The keys are identical to `am/type.php`; only the values differ.
    |
    | This catalogue is the fallback surface for the platform's
    | Amharic-first policy: an English-locale request resolves here, and
    | a key missing from the Amharic catalogue also resolves here
    | (Laravel falls through to the fallback locale).
    |
    | A future CR that extends the enum adds a key here and to
    | `am/type.php` in the same change.
    |
    */

    'individual'     => 'Individual',
    'company'        => 'Company',
    'trust'          => 'Trust',
    'cooperative'    => 'Cooperative',
    'government'     => 'Government',
    'religious_body' => 'Religious body',

];