<?php

namespace App\Support;

use App\Models\Story;
use App\Models\YoutubeMetadata;

/**
 * The Gate 4 checklist, with the value each item is asking about.
 *
 * **A checklist that only asks is unfalsifiable.** "Category set" is a tick box
 * that cannot tell you which category, so the answer lived in the operator's
 * memory and a video was very nearly published as Gaming. The same shape as the
 * item that asked for a scheduled publish time while the app had no column to
 * hold one: an item about something the sheet cannot state is an item that
 * cannot be checked, only agreed with.
 *
 * So each item resolves to a value and the sheet prints it. The tick then means
 * "I entered THIS", which is a claim with content. Where the value is a channel
 * constant it comes from `youtube.channel` — one place to change, wrong in a
 * way somebody can see rather than wrong in a way somebody has to remember.
 *
 * Two kinds of item, and the difference matters:
 *
 *   CHANNEL items are the same on every upload and always answerable. If one
 *   is wrong it is wrong for the whole channel, and it is wrong in config.
 *
 *   PER-STORY items — the publish time, the pinned comment — are answerable
 *   only when the story carries them. An unanswerable one reports that it is
 *   unanswerable and never renders as blank, because a blank beside a tick box
 *   reads as "nothing required here".
 */
final class PublishChecklist
{
    /**
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     required: bool,
     *     value: ?string,
     *     detail: ?string,
     *     answerable: bool,
     *     checked: bool,
     *     channel_wide: bool,
     * }>
     */
    public static function items(Story $story, ?YoutubeMetadata $metadata = null): array
    {
        $state = $metadata?->checklist_state ?? [];
        $items = [];

        foreach ((array) config('youtube.checklist') as $key => $item) {
            $resolved = self::resolve((string) ($item['set_to'] ?? ''), $story, $metadata);

            $items[] = [
                'key' => $key,
                'label' => (string) $item['label'],
                'required' => (bool) ($item['required'] ?? false),
                'value' => $resolved['value'],
                'detail' => $resolved['detail'],
                'answerable' => $resolved['answerable'],
                'checked' => (bool) ($state[$key] ?? false),
                // Channel constants are the same on every upload and belong in
                // the copy-paste block; per-story values already have their own
                // sections on the sheet and would only be duplicated there.
                'channel_wide' => str_starts_with((string) ($item['set_to'] ?? ''), 'channel.'),
            ];
        }

        return $items;
    }

    /**
     * The channel-wide settings as a plain block for the copy-paste sheet.
     *
     * Built here rather than looped in the Blade: Livewire wraps every
     * `@foreach` in `<!--[if BLOCK]><![endif]-->` markers, which are invisible
     * in HTML and land in the middle of a `<pre>` an operator is about to
     * select and copy.
     *
     * Channel items only. The publish time and the pinned comment have their
     * own sections on the sheet, and repeating them here would put two copies
     * of one value in front of somebody at 1am.
     */
    public static function uploadSettingsBlock(Story $story, ?YoutubeMetadata $metadata = null): string
    {
        $rows = array_values(array_filter(
            self::items($story, $metadata),
            fn (array $i): bool => $i['channel_wide'] && $i['value'] !== null,
        ));

        if ($rows === []) {
            return '';
        }

        $width = max(array_map(fn (array $i): int => mb_strlen($i['label']), $rows)) + 2;

        return implode("\n", array_map(
            fn (array $i): string => str_pad($i['label'].':', $width).$i['value'],
            $rows,
        ));
    }

    /**
     * Items ticked against a value the sheet cannot show.
     *
     * The generalisation of the scheduled-time warning, which was written for
     * one item and is true of every per-story one. A tick against an absent
     * value is not a small inconsistency: it is the operator confirming
     * something about a field nobody filled in, which is how a publish sheet
     * comes to certify a pinned comment that does not exist.
     *
     * @return array<int, string>
     */
    public static function ticksWithNothingBehindThem(Story $story, ?YoutubeMetadata $metadata = null): array
    {
        $problems = [];

        foreach (self::items($story, $metadata) as $item) {
            if ($item['checked'] && ! $item['answerable']) {
                $problems[] = sprintf(
                    'You confirmed "%s", but the sheet has nothing to show for it: %s '
                    .'Either fill it in, or the confirmation is about something this app cannot show you.',
                    $item['label'],
                    (string) $item['detail'],
                );
            }
        }

        return $problems;
    }

    /**
     * @return array{value: ?string, detail: ?string, answerable: bool}
     */
    private static function resolve(string $reference, Story $story, ?YoutubeMetadata $metadata): array
    {
        return match ($reference) {
            'channel.category' => self::channel('category'),

            'channel.languages' => self::stated(sprintf(
                'Video: %s   Captions: %s',
                (string) config('youtube.channel.video_language'),
                (string) config('youtube.channel.caption_language'),
            )),

            // Phrased as the choice the upload form actually offers, not as a
            // boolean. "made_for_kids: false" is a config value; "No, it's not
            // made for kids" is the radio button in front of the operator.
            'channel.made_for_kids' => self::stated(
                config('youtube.channel.made_for_kids')
                    ? 'Yes, it\'s made for kids'
                    : 'No, it\'s not made for kids',
                'Getting this wrong silently turns off comments and personalised ads.',
            ),

            'channel.synthetic_content' => self::stated(
                config('youtube.channel.synthetic_content')
                    ? 'Yes — disclose altered or synthetic content'
                    : 'No disclosure',
                'The description footer states the same thing in words. Both, or neither.',
            ),

            'channel.shorts_remixing' => self::stated(
                config('youtube.channel.shorts_remixing')
                    ? 'Allow remixing'
                    : 'Don\'t allow remixing',
            ),

            'story.target_publish_at' => self::publishTime($story),

            'metadata.pinned_comment' => self::pinnedComment($metadata),

            default => ['value' => null, 'detail' => null, 'answerable' => true],
        };
    }

    /** @return array{value: ?string, detail: ?string, answerable: bool} */
    private static function channel(string $key): array
    {
        $value = config("youtube.channel.{$key}");

        return $value === null || $value === ''
            ? [
                'value' => null,
                'detail' => "No channel default is configured for {$key}.",
                'answerable' => false,
            ]
            : self::stated((string) $value);
    }

    /** @return array{value: ?string, detail: ?string, answerable: bool} */
    private static function publishTime(Story $story): array
    {
        $eastern = $story->targetPublishAtEastern();

        if ($eastern === null) {
            return [
                'value' => null,
                'detail' => 'No publish time is recorded on this story.',
                'answerable' => false,
            ];
        }

        // Both zones, because the window this channel schedules into lands in
        // the small hours in Manila and that is exactly how a time gets fumbled.
        return self::stated(
            $eastern->format('D j M Y, H:i').' ET',
            $story->targetPublishAtManila()?->format('D j M Y, H:i').' Manila',
        );
    }

    /** @return array{value: ?string, detail: ?string, answerable: bool} */
    private static function pinnedComment(?YoutubeMetadata $metadata): array
    {
        $comment = trim((string) ($metadata?->pinned_comment ?? ''));

        if ($comment === '') {
            return [
                'value' => null,
                'detail' => 'No pinned comment is written on this sheet.',
                'answerable' => false,
            ];
        }

        return self::stated($comment, 'Post it, then pin it — posting alone does not pin.');
    }

    /** @return array{value: string, detail: ?string, answerable: bool} */
    private static function stated(string $value, ?string $detail = null): array
    {
        return ['value' => $value, 'detail' => $detail, 'answerable' => true];
    }
}
