<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * Dispatched after shareholder creation commits; event emission is configurable and does not affect the committed record.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Events
 */
final class ShareholderRegistered
{
    use Dispatchable;

    /**
     * The persisted shareholder.
     *
     * Read-only. Listeners MUST treat the model as immutable — any
     * modification is visible to subsequent listeners and to the caller.
     * If a listener needs a modified copy, it should clone.
     *
     * @var Shareholder
     */
    public readonly Shareholder $shareholder;

    /**
     * Create a new event instance.
     *
     * @param Shareholder $shareholder  The newly-created, committed row.
     */
    public function __construct(Shareholder $shareholder)
    {
        $this->shareholder = $shareholder;
    }
}