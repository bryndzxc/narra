"""
Forced alignment of known narration text against its audio.

This is NOT transcription and the difference is the whole point of the file.

The narration text is already known — written at Gate 1, edited and approved at
Gate 2, and spoken verbatim by a paid TTS call moments ago. Whisper is never
loaded and no ASR runs. The word list is an INPUT; the only thing the model
decides is where the boundaries between those words fall.

Transcribing instead would re-derive the words from the audio and would sometimes
get them wrong: a proper noun misheard, a homophone swapped, a contraction
expanded. Those errors do not stay small here. The .ass karaoke line is built
word by word from this output, so one substituted word desynchronises the
highlight for the remainder of the scene and puts a word on screen that the
operator never approved. Alignment makes that failure structurally impossible.

Protocol: a single JSON object on stdin, a single JSON object on stdout, and
nothing else on stdout ever. Progress and model-loading chatter from torch and
transformers goes to stderr, where the PHP caller reads it only when something
fails. A stray print() here corrupts the response.

Input:
    {
      "audio_path":  str,
      "text":        str,
      "language":    str,          # e.g. "en"
      "device":      str,          # "cpu" | "cuda"
      "align_model": str | null,   # explicit wav2vec2 checkpoint, or null
      "cache_dir":   str | null
    }

Output on success:
    {
      "ok": true,
      "duration_ms": int,
      "sample_rate": int,
      "words": [{"word": str, "start_ms": int, "end_ms": int}, ...],
      "unaligned": int,            # words the model could not place
      "device": str,
      "align_model": str
    }

Output on failure:
    {"ok": false, "error": str, "kind": str}
"""

import json
import sys


# Every import that can emit to stdout is done inside main() after stdout has
# been captured. transformers and pyannote both print at import time under some
# versions, and a single stray byte makes the response unparseable.
def main() -> int:
    try:
        request = json.load(sys.stdin)
    except Exception as exc:  # noqa: BLE001
        emit_error(f"could not parse the request JSON: {exc}", "bad_request")
        return 2

    audio_path = request.get("audio_path")
    text = (request.get("text") or "").strip()
    language = request.get("language") or "en"
    device = request.get("device") or "cpu"
    align_model_name = request.get("align_model") or None
    cache_dir = request.get("cache_dir") or None

    if not audio_path:
        emit_error("no audio_path given", "bad_request")
        return 2

    if not text:
        emit_error(
            "no text given. This is forced alignment: the words are an input, "
            "not something to be discovered from the audio.",
            "bad_request",
        )
        return 2

    # stdout is redirected for the whole import-and-align section. Anything a
    # library prints lands on stderr instead of corrupting the JSON response.
    import contextlib
    import io as _io

    chatter = _io.StringIO()

    try:
        with contextlib.redirect_stdout(chatter):
            import whisperx  # noqa: PLC0415

            audio = whisperx.load_audio(audio_path)
            sample_rate = 16000  # whisperx.load_audio always resamples to 16k
            duration_s = len(audio) / sample_rate

            model, metadata = whisperx.load_align_model(
                language_code=language,
                device=device,
                model_name=align_model_name,
                model_dir=cache_dir,
            )

            # ONE segment spanning the whole clip, carrying the known text.
            #
            # This is what makes it alignment rather than transcription: the
            # segment list is normally whisper's output, and here it is
            # hand-built from text we already have. The model is handed the
            # words and asked only where they land.
            segments = [{"text": text, "start": 0.0, "end": duration_s}]

            aligned = whisperx.align(
                segments,
                model,
                metadata,
                audio,
                device,
                return_char_alignments=False,
            )
    except Exception as exc:  # noqa: BLE001
        sys.stderr.write(chatter.getvalue())
        emit_error(f"{type(exc).__name__}: {exc}", "align_failed")
        return 1

    sys.stderr.write(chatter.getvalue())

    words = []
    unaligned = 0
    # A word the model could not place carries no start/end. Rather than
    # dropping it — which would silently shorten the karaoke line and shift
    # every word after it — it inherits a zero-length slot at the previous
    # word's end. The count is returned so the caller can refuse a scene where
    # this happened at scale.
    cursor_ms = 0

    for word in aligned.get("word_segments", []):
        token = (word.get("word") or "").strip()

        if not token:
            continue

        start = word.get("start")
        end = word.get("end")

        if start is None or end is None:
            unaligned += 1
            words.append({"word": token, "start_ms": cursor_ms, "end_ms": cursor_ms})
            continue

        start_ms = int(round(float(start) * 1000))
        end_ms = int(round(float(end) * 1000))

        # Contiguity, enforced here rather than hoped for. Gaps and overlaps in
        # {\k} durations desynchronise everything after them in the scene, so a
        # word never starts before the previous one ended.
        start_ms = max(start_ms, cursor_ms)
        end_ms = max(end_ms, start_ms)

        words.append({"word": token, "start_ms": start_ms, "end_ms": end_ms})
        cursor_ms = end_ms

    json.dump(
        {
            "ok": True,
            "duration_ms": int(round(duration_s * 1000)),
            "sample_rate": sample_rate,
            "words": words,
            "unaligned": unaligned,
            "device": device,
            "align_model": align_model_name or metadata.get("model", "default"),
        },
        sys.stdout,
    )
    sys.stdout.flush()

    return 0


def emit_error(message: str, kind: str) -> None:
    json.dump({"ok": False, "error": message, "kind": kind}, sys.stdout)
    sys.stdout.flush()


if __name__ == "__main__":
    raise SystemExit(main())
