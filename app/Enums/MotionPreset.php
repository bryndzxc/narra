<?php

namespace App\Enums;

/**
 * The Ken Burns move applied to a scene's still.
 *
 * Backing values match the `scenes.motion_preset` enum in the schema.
 */
enum MotionPreset: string
{
    case ZoomIn = 'zoom_in';
    case ZoomOut = 'zoom_out';
    case PanLeft = 'pan_left';
    case PanRight = 'pan_right';
    case Static = 'static';

    /**
     * The same kind of move, the other way.
     *
     * Used when one scene becomes two: giving the second half the mirrored move
     * makes the cut read as a cut. Repeating the parent's preset would look
     * like one continuous push interrupted by a jump, which is worse than
     * either a cut or a hold.
     *
     * `static` has no opposite — the absence of a move mirrors to itself, and a
     * held beat that was worth holding is still worth holding after a split.
     */
    public function opposite(): self
    {
        return match ($this) {
            self::ZoomIn => self::ZoomOut,
            self::ZoomOut => self::ZoomIn,
            self::PanLeft => self::PanRight,
            self::PanRight => self::PanLeft,
            self::Static => self::Static,
        };
    }

    public function isPan(): bool
    {
        return $this === self::PanLeft || $this === self::PanRight;
    }
}
