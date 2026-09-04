<?php

namespace App\Actions;

use App\Models\Scene;
use App\Models\Story;
use App\Support\AssetRateCard;
use App\Support\SceneAssetEstimate;
use App\Support\SceneChangeSet;
use App\Support\SceneSelection;
use Illuminate\Support\Collection;

/**
 * Price a story's outstanding scene assets before any of them are generated.
 *
 * The counterpart to EstimateCharacterSheets, for the far larger spend behind
 * Gate 2's other button. "Nothing is regenerated silently" has a corollary that
 * only bites once assets exist: nothing is GENERATED silently either, and at
 * 186 stills that is the difference between a decision and a discovery.
 *
 * The outstanding set is read from SceneChangeSet rather than recomputed here,
 * and that is the point rather than a convenience. SceneChangeSet is what the
 * Gate 2 approval confirmation already reads, and it is what DispatchAssetGeneration
 * dispatches from. One computation, three readers: the number on the button,
 * the jobs that run, and the bill. Two implementations of "what still needs
 * generating" would eventually disagree, and the failure mode is quoting an
 * operator one figure and charging them another.
 */
class EstimateSceneAssets
{
    public function __construct(private readonly AssetRateCard $rates) {}

    /**
     * @param  SceneSelection|null  $only  Price only this subset, for a limited run.
     */
    public function handle(Story $story, ?SceneSelection $only = null): SceneAssetEstimate
    {
        $changes = SceneChangeSet::for($story, $only);

        return new SceneAssetEstimate(
            scenesTotal: $changes->sceneCount,
            imagesPending: $changes->needsImage->count(),
            narrationsPending: $changes->needsNarration->count(),
            transcriptionsPending: $changes->needsTranscription->count(),
            // Characters of the text that will actually be sent, not of the
            // whole story: TTS bills for what it reads, and the scenes keeping
            // their existing audio are not read again.
            speechCharacters: $this->characters($changes->needsNarration),
            // What the vendor will actually charge for those characters, asked
            // of the provider rather than assumed to equal them. A credit is
            // not a character: at 0.5 credits per character this is half the
            // figure above, and quoting the character count as the bill
            // over-stated story 21's narration by exactly 2x.
            speechBillableUnits: $this->billableUnits($changes->needsNarration),
            transcriptionWords: $this->words($changes->needsTranscription),
            usdPerImage: $this->rates->usdPerImage(),
            usdPerThousandSpeechCharacters: $this->rates->usdPerThousandSpeechCharacters(),
            usdPerTranscribedMinute: $this->rates->usdPerTranscribedMinute(),
            wordsPerMinute: (int) config('render.narration.words_per_minute'),
            imageProvider: $this->rates->imageProvider(),
            speechProvider: $this->rates->speechProvider(),
            transcriberProvider: $this->rates->transcriberProvider(),
            imageModel: $this->rates->imageModel(),
            rateIsDeclared: $this->rates->isDeclaredRatherThanBilled(),
            simulatedStages: $this->rates->simulatedStages(),
            selection: $changes->selection?->describe(),
            deferred: $changes->deferred,
        );
    }

    /**
     * @param  Collection<int, Scene>  $scenes
     */
    private function characters(Collection $scenes): int
    {
        return (int) $scenes->sum(fn (Scene $scene): int => mb_strlen((string) $scene->narration_text));
    }

    /**
     * What the speech provider will bill for these scenes, in its own unit.
     *
     * Summed per scene rather than over the concatenated text, because that is
     * how it will be billed: one call per scene, each rounded by the vendor on
     * its own. Summing first and converting once would quote a number no
     * invoice will ever show.
     *
     * @param  Collection<int, Scene>  $scenes
     */
    private function billableUnits(Collection $scenes): float
    {
        return (float) $scenes->sum(
            fn (Scene $scene): float => $this->rates->speechBillableUnitsFor((string) $scene->narration_text)
        );
    }

    /**
     * @param  Collection<int, Scene>  $scenes
     */
    private function words(Collection $scenes): int
    {
        return (int) $scenes->sum(fn (Scene $scene): int => str_word_count((string) $scene->narration_text));
    }
}
