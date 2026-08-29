<?php

namespace App\Enums;

/**
 * The four human gates.
 *
 * These are the product. Everything between them is automated; none of them
 * may ever be automated away, and there is no "generate and upload" path that
 * skips them. Crossing one is an explicit, operator-initiated act — see
 * Story::approveGate().
 */
enum Gate: int
{
    /** The operator writes or edits the premise and approves the act outline. */
    case Outline = 1;

    /**
     * The operator reviews every scene's narration and image prompt.
     *
     * The money line: no paid asset generation may begin before this gate is
     * passed. Behind it sit 150-250 image generations, ~70% of a video's cost.
     */
    case Scenes = 2;

    /** The operator watches the render. */
    case Preview = 3;

    /** The operator picks the title, edits the description, approves the rest. */
    case Metadata = 4;

    /**
     * The status a story sits at while this gate waits on its operator.
     */
    public function waitsAt(): StoryStatus
    {
        return match ($this) {
            self::Outline => StoryStatus::Outlined,
            self::Scenes => StoryStatus::ScenesDrafted,
            self::Preview => StoryStatus::Rendered,
            self::Metadata => StoryStatus::MetadataReady,
        };
    }

    /**
     * The status approving this gate moves the story to.
     */
    public function opensTo(): StoryStatus
    {
        return match ($this) {
            self::Outline => StoryStatus::Scripted,
            self::Scenes => StoryStatus::ScenesApproved,
            self::Preview => StoryStatus::MetadataReady,
            self::Metadata => StoryStatus::Published,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Outline => 'Gate 1 — Outline',
            self::Scenes => 'Gate 2 — Scenes',
            self::Preview => 'Gate 3 — Preview',
            self::Metadata => 'Gate 4 — Metadata',
        };
    }
}
