@php
    /** @var \App\Models\Story $story */
    /** @var \Illuminate\Support\Collection $cast */
    /** @var \Illuminate\Support\Collection $castless */

    // Endpoint is derived from whether the frame has a cast, which is exactly
    // how FalSeedreamImageGenerator routes: a frame with people goes through
    // `edit` conditioned on their approved references, a frame with nobody
    // has no face to hold and goes through text-to-image.
    $endpointOf = fn (\App\Models\Scene $scene): string => $scene->characters_count ?? $scene->characters->count()
        ? 'edit' : 't2i';
@endphp

<x-layouts.app :title="$story->title.' — faces'">
    <div class="row" style="margin-bottom:4px">
        <h1 style="margin:0">Faces</h1>
        <span class="badge">{{ $story->status->value }}</span>
        <span class="right small">
            <a href="{{ route('stories.scenes', $story) }}">scenes</a> &middot;
            <a href="{{ route('stories.characters', $story) }}">character sheets</a> &middot;
            <a href="{{ route('renders.show', $story->slug) }}">render progress</a>
        </span>
    </div>

    <p class="muted small">
        Every still a character appears in, in story order. The question this page exists for is
        whether a face drifts &mdash; and a drift between scene 40 and scene 90 is half an hour apart
        in the video, invisible while watching and obvious side by side.
        <strong>Compare each row against its reference on the left, and against its own first and
        last frame.</strong>
    </p>

    <div class="panel" style="padding:10px; margin-bottom:14px">
        <span class="badge">edit</span>
        <span class="small muted">conditioned on the approved reference &mdash; every frame with a cast</span>
        &nbsp;&nbsp;
        <span class="badge warn">t2i</span>
        <span class="small muted">
            text-to-image, no reference &mdash; the {{ $castless->count() }} frames with nobody in them.
            Judge these for style match, not for face consistency: there is no face in them to hold.
        </span>
    </div>

    @foreach ($cast as $character)
        @continue($character->scenes->isEmpty())
        <h2 style="margin-bottom:2px">{{ $character->name }}</h2>
        <p class="muted small" style="margin-top:0">
            {{ $character->scenes->count() }} scenes &middot;
            first at #{{ $character->scenes->first()->sequence }},
            last at #{{ $character->scenes->last()->sequence }}
            @if ($character->style_notes)
                &middot; <span class="mono">{{ $character->style_notes }}</span>
            @endif
        </p>

        <div class="panel" style="padding:10px; margin-bottom:18px">
            <div style="display:flex; gap:10px; align-items:flex-start">
                {{-- The reference the whole row was generated against, pinned on
                     the left so every frame is compared to the same thing rather
                     than to its neighbour. --}}
                <div style="flex:0 0 150px; position:sticky; left:0">
                    <div class="small muted" style="margin-bottom:4px">approved reference</div>
                    @if ($character->reference_image_path)
                        <img src="{{ route('stories.characters.candidate', [$story, $character->references()->whereNotNull('selected_at')->value('id')]) }}"
                             alt="{{ $character->name }} reference"
                             style="width:150px; border-radius:4px; display:block">
                    @else
                        <div class="muted small">none</div>
                    @endif
                </div>

                <div style="flex:1; overflow-x:auto">
                    <div style="display:flex; gap:6px; padding-bottom:6px">
                        @foreach ($character->scenes as $scene)
                            <a href="{{ route('stories.still', [$story, $scene]) }}" target="_blank"
                               style="flex:0 0 auto; text-decoration:none">
                                <img src="{{ route('stories.still', [$story, $scene]) }}"
                                     loading="lazy"
                                     alt="scene {{ $scene->sequence }}"
                                     style="height:120px; border-radius:3px; display:block">
                                <div class="small mono muted" style="text-align:center">
                                    {{ $scene->sequence }}
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <h2 style="margin-bottom:2px">Frames with nobody in them</h2>
    <p class="muted small" style="margin-top:0">
        {{ $castless->count() }} of {{ $sceneCount }} scenes &mdash; establishing shots, objects, empty
        rooms. Generated <span class="badge warn">t2i</span> with no reference, because there is no
        face in them to keep consistent. What to judge here is whether they read as the same film as
        the referenced frames: palette, grain, lighting, level of stylisation.
    </p>

    <div class="panel" style="padding:10px">
        <div style="display:flex; flex-wrap:wrap; gap:6px">
            @foreach ($castless as $scene)
                <a href="{{ route('stories.still', [$story, $scene]) }}" target="_blank"
                   style="flex:0 0 auto; text-decoration:none">
                    <img src="{{ route('stories.still', [$story, $scene]) }}"
                         loading="lazy"
                         alt="scene {{ $scene->sequence }}"
                         style="height:110px; border-radius:3px; display:block">
                    <div class="small mono muted" style="text-align:center">{{ $scene->sequence }}</div>
                </a>
            @endforeach
        </div>
    </div>
</x-layouts.app>
