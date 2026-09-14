<?php

namespace App\Enums;

/**
 * When an act takes place: in the story's present, or before it.
 *
 * -------------------------------------------------------------------------
 * THE MEASUREMENT THIS COMES FROM
 * -------------------------------------------------------------------------
 *
 * Story 28's present-day betrayal lands at 20:18 of a 43-minute video. The
 * hook opens on it at 0:00 and obeys every rule it was given; then act 1
 * stages the 2015 betrayal in full (2:50-6:21) and act 2 stages the 2017
 * one in full (7:47-17:31), so two of the three escalation acts are history
 * and the present-day escalation is one act long before the departure. Story
 * 23 spends its first act on the Spring Festival table of that year and its
 * second on four years of night shifts; its betrayal is at 15:01. Stories 21
 * and 25 open in the present and stay there.
 *
 * The outline made that allocation, visibly — 28's act 2 summary says "Act 2
 * tells the second betrayal in full" — and nothing asked it whether an act
 * was set in the present. The act writer stages what the summary hands it,
 * and two instructions reward staging: "name the amounts, the dates, the
 * rooms, the exact words" and "anything vague here gets invented later".
 *
 * -------------------------------------------------------------------------
 * A DECLARATION, NOT A MEASUREMENT
 * -------------------------------------------------------------------------
 *
 * The outline writer declares this per act, from the schema, the way the
 * expression field is declared per scene: a model asked in prose for several
 * things drops the awkward one, and a field's surface measured 24x a prompt
 * rule's. It will answer honestly — it already wrote "tells the second
 * betrayal in full" in a summary — and once it has said `prior`, Gate 1 can
 * refuse before the act is bought.
 *
 * What it cannot do is prove an act marked `present` stays there. That is
 * the act writer's instruction (see `timeframeInstruction()`), and the
 * operator's read of the summaries beside the badge.
 *
 * Null is UNKNOWN: an outline generated before the field existed, or an
 * anthology act. Every story from 9 to 28 is null and reads as "not asked",
 * never as present.
 */
enum ActTimeframe: string
{
    case Present = 'present';
    case Prior = 'prior';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present day',
            self::Prior => 'Set in the past',
        };
    }
}
