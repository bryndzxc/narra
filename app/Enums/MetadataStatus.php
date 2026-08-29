<?php

namespace App\Enums;

/**
 * State of a story's YouTube publish sheet.
 *
 * The sheet is generated after the render, because chapters need real
 * timestamps and those only exist once the video does.
 */
enum MetadataStatus: string
{
    /** The row exists; nothing has been generated into it yet. */
    case Pending = 'pending';

    /** Generated, awaiting the operator at Gate 4. */
    case Generated = 'generated';

    /**
     * Generated against a render that no longer exists.
     *
     * Set when Gate 2 is reopened. The chapter timestamps in a drafted sheet
     * come from act timings, and those are filled in by the render — reopening
     * means the next render produces different ones, so every timestamp in the
     * sheet is now wrong. The sheet stays visible and copyable in the meantime,
     * which is exactly the problem: Gate 4's checklist covers actions with
     * consequences outside this app, and a copied chapter list with the wrong
     * timestamps is one of them.
     *
     * Leaving this state requires a regeneration that happens AFTER a newer
     * successful render — not merely pressing save. See
     * YoutubeMetadata::hasFreshRender().
     */
    case Stale = 'stale';

    /** Title picked, description edited, checklist worked through. */
    case Approved = 'approved';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
