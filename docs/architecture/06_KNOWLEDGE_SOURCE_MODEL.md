# Knowledge và Source

Knowledge là claim/fact/research statement có thể được nhiều Post sử dụng.
Source là thực thể authority để truy nguyên claim và quan hệ nghiên cứu. Một
Post có thể liên hệ nhiều Knowledge; một Knowledge có thể liên hệ nhiều Post.

P7 persists claims, sources and evidence as separate canonical records with
UUID identity, stable keys, state, optimistic revision and provenance/metadata.
Evidence requires existing claim and source endpoints and records whether the
source supports, contradicts or qualifies the claim. `PostKnowledgeLinkService`
connects a WordPress Post to a Knowledge claim through the single Graph using
the `about` predicate; it does not copy claim text into the Post body and does
not create an Article Authority.

Public read boundaries require active records and fail closed when persisted
Source or Evidence metadata explicitly declares a non-`PUBLIC` visibility
(including `PRIVATE` and `HIDDEN`). Public serializers omit the persisted
Source/Evidence metadata blobs and Knowledge claim provenance blob. A missing
visibility value preserves the existing V3-compatible default, but does not
constitute approval of imported V2 provenance; the final publication policy
remains a cutover gate.
