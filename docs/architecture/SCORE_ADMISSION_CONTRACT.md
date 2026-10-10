# NHK V3 Score Admission Contract

Score research candidates are transient research input. A Verified Score Edition
is an edition-scoped packet admitted through `music:score_admit`; it remains
owned by the existing canonical Music Authority and is not a new semantic owner.
The packet carries its own edition UUID/key, Music UUID and revision binding,
source/evidence provenance, arrangement/transposition, note events, phrase
segments, verification state, checksum, rights attribution/licence/notice and
ShareAlike terms, dependency closure and idempotency through Governance.

`music_score_admission` is the registered operation family. The controlled
executor validates the score packet, verifies the exact existing Music revision,
appends or idempotently replays the edition in `score_editions`, and returns the
canonical Music read-back. It never accepts a client signature; staging scope
must be server-issued and Capture-bound.

Public projection exposes only `VERIFIED_SCORE_EDITION` packets with
`rights_status=CLEARED`, and strips operational fields. Score-driven Web Audio
is synthesis, not a Recorded MediaAsset. Recorded MediaAsset playback continues
through the existing Media/MediaAsset/MediaUsage boundary.
