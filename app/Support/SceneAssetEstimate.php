<?php

namespace App\Support;

/**
 * What generating a story's scene assets is about to cost, itemised, before it
 * does.
 *
 * The sibling of CharacterSheetEstimate, and by a wide margin the larger
 * number: this is the 150-250 stills the spec calls the dominant line item at
 * ~70% of a video's cost, plus a TTS call and a transcription per scene. It is
 * the biggest single authorisation an operator makes in this product.
 *
 * Which is why it is itemised by stage rather than shown as one total. The
 * three stages bill in three different units against three different providers,
 * and after a partial run the number that matters is "five stills", not "the
 * video". An operator shown one aggregate figure every time stops reading it.
 *
 * Everything here counts the OUTSTANDING work only. A scene whose still is
 * already on disk and whose image prompt has not changed since approval costs
 * nothing to leave alone, and including it in the total would teach the
 * operator that the number is theatre — which matters most on the retry press,
 * where the honest answer is a few cents and a padded one would look like a
 * second full bill.
 */
final class SceneAssetEstimate
{
    public function __construct(
        public readonly int $scenesTotal,
        public readonly int $imagesPending,
        public readonly int $narrationsPending,
        public readonly int $transcriptionsPending,
        /** Characters of narration text across the pending narrations. */
        public readonly int $speechCharacters,
        /**
         * What the provider will actually BILL for those characters.
         *
         * Separate from the character count because they are not the same
         * number and the difference is the vendor's, not ours: TTS bills in
         * credits, and `eleven_multilingual_v2` on this account charges 0.5 of
         * them per character. Story 21's narration was 42,017 characters and
         * 21,193 billed units, and the estimate quoted the first while the
         * ledger recorded the second.
         *
         * Both are shown on the sheet. Neither is redundant: the character
         * count is what will be READ, and this is what will be CHARGED, and an
         * operator watching an allowance needs the second one.
         */
        public readonly float $speechBillableUnits,
        /** Words across the pending transcriptions, for the audio-minutes projection. */
        public readonly int $transcriptionWords,
        public readonly float $usdPerImage,
        public readonly float $usdPerThousandSpeechCharacters,
        public readonly float $usdPerTranscribedMinute,
        public readonly int $wordsPerMinute,
        public readonly string $imageProvider,
        public readonly string $speechProvider,
        public readonly string $transcriberProvider,
        public readonly ?string $imageModel,
        public readonly bool $rateIsDeclared,
        /**
         * Stages served by a stand-in rather than a vendor, by name.
         *
         * On the object because it has to reach the screen. A run can be
         * entirely simulated while every figure on the page reads like a bill —
         * that is not hypothetical, it is what happened, and the only signal at
         * the time was the word "fake" in a table column nobody had reason to
         * read.
         *
         * @var array<int, string>
         */
        public readonly array $simulatedStages = [],
        /** The subset this run is restricted to, described for the screen, or null. */
        public readonly ?string $selection = null,
        /**
         * Outstanding scenes NOT in this run because the selection excluded them.
         *
         * On the estimate because the operator authorising a limited run needs
         * to see that it is limited. A five-scene quote that looked like a
         * whole-story quote would be read as "the story is nearly done".
         */
        public readonly int $deferred = 0,
    ) {}

    /** Whether this run is a deliberate subset rather than everything outstanding. */
    public function isLimited(): bool
    {
        return $this->selection !== null;
    }

    /** Whether any stage will produce a placeholder rather than a real asset. */
    public function hasSimulatedStage(): bool
    {
        return $this->simulatedStages !== [];
    }

    /** Whether EVERY stage is a stand-in, so the run bills nothing at all. */
    public function isEntirelySimulated(): bool
    {
        return count($this->simulatedStages) === 3;
    }

    public function usdImages(): float
    {
        return round($this->imagesPending * $this->usdPerImage, 4);
    }

    public function usdNarration(): float
    {
        return round($this->speechCharacters / 1000 * $this->usdPerThousandSpeechCharacters, 4);
    }

    /**
     * Whether the provider charges in something other than characters.
     *
     * Used to decide whether the sheet says both numbers or one. Saying
     * "42,017 characters, 42,017 billable" would be noise; saying only
     * "42,017 characters" on a run that bills 21,193 is the defect this
     * replaces.
     */
    public function speechBillsInCharacters(): bool
    {
        return abs($this->speechBillableUnits - $this->speechCharacters) < 0.5;
    }

    /**
     * Transcription is billed per minute of audio, and the audio does not exist
     * yet — so the minutes are projected from the word count at the same
     * words-per-minute constant the word target and the runtime estimate use.
     *
     * One constant, shared, deliberately: if this used its own rate then a
     * story written to hit 35 minutes would be quoted against some other
     * length, and the two would drift with nothing to catch it.
     */
    public function projectedAudioMinutes(): float
    {
        return round($this->transcriptionWords / max(1, $this->wordsPerMinute), 4);
    }

    public function usdTranscription(): float
    {
        return round($this->projectedAudioMinutes() * $this->usdPerTranscribedMinute, 4);
    }

    public function usdTotal(): float
    {
        return round($this->usdImages() + $this->usdNarration() + $this->usdTranscription(), 4);
    }

    /** Jobs this run would dispatch, across all three stages. */
    public function jobsTotal(): int
    {
        return $this->imagesPending + $this->narrationsPending + $this->transcriptionsPending;
    }

    /** Scenes that keep everything they already have. */
    /**
     * Scenes that are finished and are not being paid for again.
     *
     * Deferred scenes are subtracted as well as pending ones, and the two are
     * genuinely different things: a preserved scene is DONE, a deferred scene is
     * outstanding and simply not in this run. Counting the second as the first
     * made a five-scene run print "181 scene(s) keep the assets they already
     * have" directly above "181 other scene(s) still need work" — two lines
     * describing the same 181 scenes in opposite terms, on the screen whose
     * entire job is to be believed before money is spent.
     */
    public function preserved(): int
    {
        return $this->scenesTotal - $this->scenesPending() - $this->deferred;
    }

    /**
     * Distinct scenes needing at least one of the three, not the sum of the
     * three — a scene missing both its still and its narration is one scene.
     */
    public function scenesPending(): int
    {
        return max($this->imagesPending, $this->narrationsPending, $this->transcriptionsPending);
    }

    public function billsAnything(): bool
    {
        return $this->jobsTotal() > 0;
    }

    /**
     * The itemised lines the operator reads before pressing.
     *
     * Stills lead, and carry the share, because that is the line that decides
     * whether this press is worth making — the other two together are rounding
     * against it, and an operator who does not know that cannot judge the total.
     *
     * @return array<int, string>
     */
    public function summary(): array
    {
        if (! $this->billsAnything()) {
            return [];
        }

        $lines = [];

        // First line, before any figure. A stand-in produces a flat-fill PNG
        // and a silent WAV; if the operator reads nothing else, they must read
        // this, because every number below it is zero for a reason that has
        // nothing to do with a good deal.
        if ($this->hasSimulatedStage()) {
            $lines[] = sprintf(
                'SIMULATED: %s %s served by a stand-in, not a vendor. Nothing is billed and the '
                .'output is placeholder, not real artwork. Set the provider in .env before spending.',
                implode(' and ', $this->simulatedStages),
                count($this->simulatedStages) === 1 ? 'is' : 'are',
            );
        }

        if ($this->imagesPending > 0) {
            $lines[] = sprintf(
                '%d still%s at $%s each — $%s (%s%s)',
                $this->imagesPending,
                $this->imagesPending === 1 ? '' : 's',
                number_format($this->usdPerImage, 4),
                number_format($this->usdImages(), 4),
                $this->imageProvider,
                $this->imageModel !== null ? ', '.$this->imageModel : '',
            );
        }

        if ($this->narrationsPending > 0) {
            $lines[] = sprintf(
                '%d narration%s, %s characters%s at $%s/1k — $%s (%s)',
                $this->narrationsPending,
                $this->narrationsPending === 1 ? '' : 's',
                number_format($this->speechCharacters),
                // The billable figure, said out loud whenever it differs. An
                // operator watching a monthly allowance is watching THIS
                // number, and quoting only the character count reported a
                // scarcity that was not there.
                $this->speechBillsInCharacters()
                    ? ''
                    : sprintf(' (%s billable units)', number_format($this->speechBillableUnits)),
                number_format($this->usdPerThousandSpeechCharacters, 4),
                number_format($this->usdNarration(), 4),
                $this->speechProvider,
            );
        }

        if ($this->transcriptionsPending > 0) {
            $lines[] = sprintf(
                '%d transcription%s, ~%s minutes at $%s/min — $%s (%s)',
                $this->transcriptionsPending,
                $this->transcriptionsPending === 1 ? '' : 's',
                number_format($this->projectedAudioMinutes(), 1),
                number_format($this->usdPerTranscribedMinute, 4),
                number_format($this->usdTranscription(), 4),
                $this->transcriberProvider,
            );
        }

        $lines[] = sprintf('Total: $%s across %d job(s).', number_format($this->usdTotal(), 4), $this->jobsTotal());

        if ($this->preserved() > 0) {
            $lines[] = sprintf(
                '%d scene(s) keep the assets they already have and are not billed again.',
                $this->preserved(),
            );
        }

        return $lines;
    }
}
