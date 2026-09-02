# YouTube Video Source Contract

> Non-normative implementation contract under the sole NHK V3 Constitution.

YouTube is an external source adapter, not the NHK semantic authority. The
adapter accepts only HTTPS YouTube hosts and the supported watch, short, embed
and short-host forms. It normalizes all forms to `platform=youtube` plus the
11-character external video ID and canonical watch URL.

When configured, metadata is acquired through the official YouTube Data API.
The API key is environment configuration only, never persisted or returned.
Requests have a bounded timeout. Missing configuration, rate limits, malformed
responses and unavailable items remain distinguishable source outcomes.

`YouTubeSourceSnapshot` preserves channel, title, description, publication,
duration, thumbnail references, tags, language, caption availability,
embeddability, availability, live state, fetch time and a deterministic hash.
External text and collection sizes are bounded. Source thumbnails remain
remote references unless the governed Media pipeline separately authorizes a
local asset.

Caption availability does not imply transcript access. A transcript is stored
only under `AUTHORIZED_YOUTUBE_TRANSCRIPT` or `USER_SUPPLIED_TRANSCRIPT`; the
default is `NO_TRANSCRIPT`, with no fabricated timing or text.

Timestamp chapters are optional. They are accepted only when parsed from a
source description line with a valid increasing timestamp and are retained
with `YOUTUBE_DESCRIPTION` evidence; no chapter or key moment is inferred from
the title, tags or model output.
