<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P3 follow-up · File 1 of 5
 * =============================================================================
 *
 * File:            Resources/lang/am/type.php  (NEW)
 * Unit:            Register the Shareholder domain's translation namespace
 * Delivery type:   New file
 * Net lines:       +…
 *
 * Before:
 *   The Shareholder domain had no Resources/lang/ directory. Every call
 *   to `__('shareholder::type.*')` — issued from
 *   ShareholderResource::resolveTypeLabel() — returned its key verbatim,
 *   because Laravel's translator had no `shareholder` namespace
 *   registered and no Amharic type catalogue to resolve against.
 *
 *   The visible symptom: a shareholder's type rendered as the literal
 *   string "shareholder::type.individual" in the identity card and in
 *   the workspace's shareholder list and detail views.
 *
 * After:
 *   The Amharic type catalogue is on disk at the path the Shareholder
 *   provider will register with Laravel's translator (pending File 5 of
 *   this sequence). Once both this file and File 5 are deployed,
 *   `__('shareholder::type.individual')` resolves to 'ግለሰብ'.
 *
 *   The six keys mirror the ShareholderType enum's six cases 1:1
 *   (D9 vocabulary): individual, company, trust, cooperative,
 *   government, religious_body. The enum's `value` is the key; the
 *   resource calls `__('shareholder::type.' . $type->value)`.
 *
 * =============================================================================
 * Values — drawn from the frontend's existing catalogue
 * =============================================================================
 * The six Amharic strings are byte-for-byte identical to the
 * `typeFilter*` values already declared in
 * mase_app/src/features/shareholders/labels.ts (v1.1.0), lines 519–528
 * of the delivered file:
 *
 *     typeFilterIndividual:    'ግለሰብ'
 *     typeFilterCompany:       'ኩባንያ'
 *     typeFilterTrust:         'አደራ'
 *     typeFilterCooperative:   'ህብረት ስራ ማህበር'
 *     typeFilterGovernment:    'መንግስት'
 *     typeFilterReligiousBody: 'ሃይማኖታዊ ድርጅት'
 *
 * The same six strings also appear in shareholderFormLabels under the
 * `type*` keys. Reusing them here means a shareholder's type renders
 * identically whether the string came from the API's `type_label` field
 * (backend, this file) or from the frontend's own label module — the
 * two surfaces cannot drift.
 *
 * A future CR that adjusts a type's Amharic rendering must change both
 * files together. The two-file coordination is documented in the
 * frontend file's header (§"Terms handled deliberately") and echoed
 * here.
 *
 * =============================================================================
 * Native-speaker review
 * =============================================================================
 * Two of the six values are flagged for native-speaker review in the
 * frontend labels file, and the same flags apply here:
 *
 *   · 'አደራ' for `trust` — Amharic has no single established legal term
 *     for the Anglo-American "trust" concept. 'አደራ' means
 *     custody/entrustment and is the closest native rendering. A
 *     reviewer may prefer the loanword 'ትረስት' or a longer descriptive
 *     phrase. Both files must change together.
 *
 *   · 'ሃይማኖታዊ ድርጅት' for `religious_body` — a reviewer may prefer
 *     'ሃይማኖታዊ ተቋም' ("religious institution"). Both are correct; the
 *     choice may depend on the registry's legal classification.
 *
 * A reviewer's decisions apply to both the frontend and the backend
 * catalogue. Neither file is a standalone authority on the vocabulary.
 *
 * =============================================================================
 * Fallback behaviour
 * =============================================================================
 * If a future CR extends the ShareholderType enum with a seventh case
 * and the case is not added here, the frontend will render
 * `shareholder::type.{new_case}` verbatim. That is a deployment gap
 * that surfaces immediately; it is not silent. The
 * ShareholderPermissionsSeeder and ShareholderService already use the
 * same fail-loud pattern (unknown enum → key returned, not a null).
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
    | Shareholder Types (D9)
    |--------------------------------------------------------------------------
    |
    | The six values permitted in `shareholders.shareholder_type`, keyed
    | by the ShareholderType enum's backed value. The ShareholderResource
    | resolves each row's `type_label` through this catalogue:
    |
    |     __('shareholder::type.' . $type->value)
    |
    | Keys MUST match ShareholderType::values() exactly. A key that does
    | not correspond to a case is dead code; a case with no key renders
    | the raw key string in the UI.
    |
    */

    'individual'     => 'ግለሰብ',
    'company'        => 'ኩባንያ',
    'trust'          => 'አደራ',
    'cooperative'    => 'ህብረት ስራ ማህበር',
    'government'     => 'መንግስት',
    'religious_body' => 'ሃይማኖታዊ ድርጅት',

];