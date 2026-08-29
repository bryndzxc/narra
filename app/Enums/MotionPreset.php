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

    public function isPan(): bool
    {
        return $this === self::PanLeft || $this === self::PanRight;
    }
}
