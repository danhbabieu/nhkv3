# NHK V3 Dictionary Authoring Data Contract

## 0. Mục đích và phạm vi

Tài liệu này là sổ tay chuẩn bị dữ liệu cho người biên soạn từ điển NHK V3.
Nó trả lời câu hỏi: **một mục từ cần ghi những gì để lần sau có thể tra cứu,
kiểm chứng, tái sử dụng và xuất bản đúng owner?**

Tài liệu này là hướng dẫn dữ liệu và quy trình, không phải schema mới, không
đăng ký entity/predicate/operation mới và không cấp quyền ghi dữ liệu. Tên
trường trong các worksheet/YAML ở dưới là nhãn biên tập; khi ghi thật phải
dùng repository, contract, MCP/Admin capability và Governance boundary đang
được runtime đăng ký.

Luật nền:

```text
SEARCH FIRST → RESOLVE → REUSE → CREATE PRIVATE CANDIDATE ONLY IF UNRESOLVED
```

Dictionary sở hữu ngôn ngữ (Entry, Form, Sense, Label). Authority sở hữu
entity; Knowledge sở hữu atomic claim; Source/Evidence sở hữu provenance và
support; Graph sở hữu relation; Media/MediaAsset/MediaUsage và Video giữ ranh
giới riêng; WordPress `wp_posts` sở hữu bài viết. Dictionary không sao chép
payload của các owner đó thành sự thật thứ hai.

## 1. Một trang từ điển chuẩn gồm những lớp nào?

```text
LexicalEntry
├── preferred Form                 (tên gọi chính)
├── alternate/colloquial/... Form  (cách gọi tra cứu)
└── 1..N DictionaryConcept-as-Sense
    ├── lexical definition          (giải nghĩa ngôn ngữ, không tự là Evidence)
    ├── context / usage notes       (phạm vi và cách dùng)
    ├── semantic_reference?         (tham chiếu typed tới owner hiện có)
    └── lexical attestation refs?   (đã gặp ở đâu, không phải Knowledge Evidence)

Candidate ──phát hiện/chờ duyệt──> Entry hoặc Sense
Mention   ──ghi nhận lần xuất hiện──> Entry/Sense hoặc thuật ngữ chưa giải quyết
```

Một trạng thái tối thiểu hợp lệ có thể chỉ là:

```text
Entry → Sense → semantic_reference → canonical owner
```

Không bắt buộc phải có Brand, Movement, Knowledge, Media, Video, Article hay
Graph relation ngay khi tạo mục từ. Quan hệ về sau là enrichment của owner
canonical và phải đi qua contract/Governance tương ứng.

## 2. Bộ dữ liệu bắt buộc khi soạn

| Nhóm | Dữ liệu phải ghi | Vì sao cần cho lần tra cứu sau | Không được làm |
|---|---|---|---|
| Nhận diện mục từ | `preferred_form`, locale, ngày/người soạn, trạng thái bản thảo | Tìm đúng Entry và biết ai chịu trách nhiệm | Dùng UUID kỹ thuật làm từ hiển thị |
| Nghĩa | Một Sense cho mỗi nghĩa đã phân biệt; định nghĩa ngắn | Tránh gộp hai nghĩa chỉ vì cùng chuỗi chữ | Sao chép claim Knowledge thành định nghĩa |
| Ngữ cảnh | domain, register, audience, usage scope, câu ví dụ nếu đã duyệt | Giải quyết từ đồng âm/đa nghĩa và chọn link đúng | Tự đặt taxonomy mới cho từng mục |
| Biểu thức | alternate, colloquial, technical, phonetic, locale, sense scope | Tra cứu alias và viết tự nhiên theo đối tượng đọc | Công khai `HIDDEN`/từ chưa duyệt |
| Owner | owner type, canonical ID/stable key, revision đã đọc, route hiện tại, trạng thái revalidation | Link về một sự thật canonical và phát hiện mapping cũ | Suy owner từ tên, slug, URL, ảnh hoặc AI memory |
| Provenance từ vựng | source kind, source ID/URL, locator, observed text, timestamp, context | Biết từ/nghĩa được gặp ở đâu và tái kiểm chứng | Gọi attestation là Evidence |
| Review | resolution, ambiguity, candidate state, reviewer, revision, quyết định | Biết vì sao được reuse, block, reject hay cần hỏi lại | Auto-approve theo confidence/frequency |
| Xuất bản | public eligibility, canonical mode, indexability, redirect/noindex reason | Giữ một canonical URL và không index bản nháp/ambiguous | Tạo page cạnh tranh với owner delegated |

### 2.1. Trường nhận diện và tra cứu

- `preferred_form`: chuỗi hiển thị có dấu, đúng chính tả biên tập. Đây là dữ
  liệu con người đọc, không phải chuỗi normalized để tạo slug.
- `locale`: ví dụ `vi-VN`; nếu là tiếng địa phương, ngoại ngữ hoặc cách gọi
  cộng đồng thì ghi rõ, không suy ra từ chữ viết.
- `normalized_lookup_forms`: các dạng phục vụ tìm kiếm do runtime tạo/kiểm
  tra. Không tự biến trường này thành Label công khai.
- `public_slug`: chỉ dùng khi Dictionary thực sự sở hữu canonical detail.
  Slug phải theo `PUBLIC_URL_SLUG_CONTRACT`; không lấy lexical UUID, owner UUID,
  database ID hay source key làm slug material.
- `entry_status` và `sense_status`: phân biệt bản thảo, đã duyệt, retired,
  ambiguous, blocked. Candidate private không phải Entry public.

### 2.2. Trường nghĩa và ngữ cảnh

Mỗi Sense cần có:

1. `definition`: giải thích ngắn, chỉ nói nghĩa của từ trong phạm vi đã biết;
2. `context.domain`: lĩnh vực như đồng hồ, linh kiện, âm nhạc… chỉ dùng domain
   đã được contract/curator chấp nhận;
3. `context.usage_scope`: phạm vi dùng như kỹ thuật, giới sưu tầm, vùng miền;
4. `context.register`: phổ thông, colloquial, technical, historical…;
5. `context.example`: câu ví dụ được biên tập, không dùng câu ví dụ để ngầm
   tạo thêm một factual claim;
6. `ambiguity_notes`: các nghĩa/owner cạnh tranh và điều kiện cần để phân biệt;
7. `definition_status`: `DRAFT`, `REVIEW_REQUIRED`, `APPROVED` hoặc
   `BLOCKED` trong worksheet. Chỉ trạng thái mà runtime contract hỗ trợ mới
   được gửi vào operation thật.

Nếu định nghĩa chứa một phát biểu có thể kiểm chứng (thương hiệu, niên đại,
cấu tạo, quan hệ, tần suất, công dụng…), đó là dấu hiệu cần tách thành
Knowledge/Authority claim. Dictionary có thể giải nghĩa thuật ngữ, nhưng
không dùng định nghĩa để lách Source/Evidence/Governance.

### 2.3. Forms và Labels

Phân loại từng cách gọi, không chỉ chép một danh sách:

| Kind | Dùng cho | Hiển thị công khai |
|---|---|---|
| `PREFERRED` | cách gọi chính của Entry | Có |
| `ALTERNATE` | biến thể/chính tả đã chấp nhận | Có |
| `COLLOQUIAL` | cách gọi cộng đồng/sưu tầm | Có nếu có context phù hợp |
| `TECHNICAL` | thuật ngữ kỹ thuật | Có nếu đã duyệt |
| `PHONETIC` | phát âm/phiên âm | Có khi hữu ích cho người đọc |
| `HIDDEN` | form phục vụ resolver/search nội bộ | Không |

Một form có nhiều nghĩa phải gắn Sense/context hoặc để `AMBIGUOUS`; không
autolink chỉ vì chuỗi chữ giống nhau. Alias Authority và Form Dictionary là
hai khái niệm khác nhau: chỉ tạo Form khi có giá trị ngôn ngữ/editorial.

## 3. Tham chiếu owner, nguồn và bằng chứng

### 3.1. `semantic_reference` không phải URL

Khi một Sense chỉ về owner có sẵn, worksheet phải lưu đủ để revalidate:

```yaml
semantic_reference:
  type: component             # vocabulary đã có trong registry
  canonical_id: <UUID-or-key> # lấy từ owner repository, không tự sinh
  stable_key: <stable-key>    # nếu owner contract cung cấp
  observed_revision: <int>
  current_route: <absolute-or-site-relative-public-route>
  status: PENDING_REVALIDATION
  source: MAPPING | OWNER_READ | NONE
```

`current_route` chỉ là projection điều hướng; nó không chứng minh identity.
Khi đọc public phải kiểm tra type, ID, active state, revision, public
eligibility và canonical route. Mapping stale, invalid, conflicting hoặc
ambiguous phải fail closed.

### 3.2. Attestation và Evidence

Ghi provenance thành hai lớp để không lẫn vai trò:

| Lớp | Ghi gì | Ý nghĩa |
|---|---|---|
| Lexical attestation | từ nào/ nghĩa nào xuất hiện ở source nào, field/đoạn/trang nào, lúc nào | chứng minh cách dùng đã được quan sát/biên tập |
| Knowledge Evidence | Evidence record đã được owner Knowledge/Source/Evidence governed | hỗ trợ hoặc phản bác một atomic claim |

Một URL, ảnh, transcript, tần suất xuất hiện hoặc mention chỉ là tín hiệu/attestation
cho đến khi owner contract nâng nó thành Evidence. Không ghi `source_url` vào
định nghĩa rồi xem đó là chứng cứ.

Mỗi source record nên có: `source_kind`, `source_id` hoặc URL, title, publisher,
locale, accessed_at, locator (trang/đoạn/khung thời gian), exact observed text,
scope, trust/review note và lineage. Nguồn Việt Nam có thể giữ làm provenance
nội bộ; public source-display policy có thể loại nó khỏi thẻ nguồn công khai.

## 4. Quy trình biên soạn chuẩn

### Bước A — Preflight và tìm lại trước khi tạo

1. Chuẩn hoá whitespace/case để tra cứu, nhưng giữ nguyên display text.
2. Tìm approved label/form, hidden resolver form và Sense hiện có.
3. Tìm current Authority/Public Identity, Knowledge, Article owner và candidate
   bị suppress/reject.
4. Ghi exact context: locale, domain, nơi gặp, người dùng muốn nói gì.
5. Nếu có một owner phù hợp, reuse/mapping; không tạo Entry cạnh tranh.
6. Nếu không chắc, trả `UNKNOWN` hoặc `AMBIGUOUS` và tạo private Candidate,
   không tạo public definition/link.

### Bước B — Soạn Entry/Sense

1. Chọn một `preferred_form` duy nhất cho Entry.
2. Tách nghĩa thành các Sense; không gom các nghĩa chỉ để tránh tạo record.
3. Ghi định nghĩa ngắn và phạm vi dùng; đánh dấu phần cần Knowledge.
4. Thêm forms/labels kèm kind, locale, context và bằng chứng cách dùng.
5. Gắn owner đã revalidate nếu có; nếu chưa có thì giữ dedicated lexical
   candidate/Entry theo capability hiện hành.

### Bước C — Review và curation

- Reviewer phải xem observed forms, context, resolver candidates, owner
  candidates, source locators và duplicate audit trước khi duyệt.
- Revision/idempotency/read-back là bắt buộc cho curated write.
- Merge duplicate lexical labels/concepts không được rekey semantic owner.
- `AMBIGUOUS`, `REJECTED`, `IGNORED`, `DO_NOT_SUGGEST` là trạng thái riêng;
  không thay bằng một định nghĩa trống.

### Bước D — Xuất bản và kiểm tra lại

- Owner delegated: Dictionary detail không index, canonical/link trực tiếp về
  owner hiện tại; không để page Dictionary cạnh tranh.
- Dictionary-owned: chỉ index khi có definition/lexical value đủ và public
  identity hợp lệ; canonical, Open Graph, JSON-LD, sitemap và internal link
  phải cùng một path.
- Draft/ambiguous/rejected/ignored/suppressed/duplicate/redirected luôn
  `noindex` hoặc không xuất hiện trong public surface.
- Runtime unavailable phải hiện “chưa khả dụng”, không giả làm empty success.

## 5. Worksheet chuẩn cho mỗi mục từ

> Đây là biểu mẫu biên tập, **không phải payload runtime mới**.

```yaml
authoring:
  worksheet_version: dictionary-authoring-v1
  prepared_at: YYYY-MM-DD
  prepared_by: <editor>
  review_state: DRAFT | REVIEW_REQUIRED | APPROVED | BLOCKED

entry:
  preferred_form: <display text>
  locale: vi-VN
  public_slug: <only when Dictionary owns canonical detail>
  lifecycle: DRAFT | APPROVED | RETIRED

forms:
  - text: <visible or resolver form>
    kind: PREFERRED | ALTERNATE | COLLOQUIAL | TECHNICAL | PHONETIC | HIDDEN
    locale: <locale>
    context: <why this form is valid>
    public: true | false

senses:
  - sense_key: <editorial temporary key>
    definition: <concise lexical definition or null while blocked>
    definition_status: DRAFT | REVIEW_REQUIRED | APPROVED | BLOCKED
    context:
      domain: <approved bounded domain>
      usage_scope: [<scope>]
      register: <register>
      examples: [<approved reader-facing examples>]
    ambiguity_notes: <competing readings and disambiguation condition>
    semantic_reference:
      type: <registered owner type or null>
      canonical_id: <read-back identity or null>
      stable_key: <read-back key or null>
      observed_revision: <int or null>
      current_route: <read-back public route or null>
      status: VALIDATED | PENDING_REVALIDATION | AMBIGUOUS | INVALID | ABSENT

attestations:
  - source_kind: ARTICLE | KNOWLEDGE | MEDIA | VIDEO | EXTERNAL_RESEARCH | HUMAN
    source_id_or_url: <bounded source reference>
    locator: <page/field/paragraph/time range>
    observed_text: <exact wording or short bounded excerpt>
    observed_at: <timestamp>
    scope: <what this source actually supports>
    public_display: ALLOW | INTERNAL_ONLY | EXCLUDE

publication:
  canonical_mode: DELEGATED | DEDICATED | BLOCKED
  canonical_url: <read-back projection>
  dictionary_url: <only if a persisted public identity exists>
  indexable: true | false
  sitemap: true | false
  reason: <human-readable gate reason>
  last_read_back_at: <timestamp>

review:
  resolution: REUSE_EXISTING | ATTACH | CREATE_DRAFT | AMBIGUOUS | REJECT | IGNORE
  duplicate_audit: COMPLETE | REQUIRED | BLOCKED
  reviewer: <editor or null>
  revision: <optimistic revision or null>
  idempotency_key: <operation key or null>
  read_back_receipt: <reference or null>
```

## 6. Mẫu `Côn hoa thị` — dùng để học cách ghi, không phải dữ liệu xuất bản

`Côn hoa thị` là **`EXAMPLE_ONLY / NO_LIVE_WRITE`**. Audit read-only hiện ghi
Component canonical owner và route:

- stable key tham chiếu: `nhk:component:con-hoa-thi`;
- route hiện tại được audit ghi nhận: `/linh-kien/con-hoa-thi/`;
- exact canonical UUID phải được đọc lại từ Authority khi thực hiện workflow;
  không copy UUID cũ từ tài liệu vào một mutation packet;
- Dictionary không được tạo một route indexable cạnh tranh nếu Entry/Sense
  delegated tới Component này.

Worksheet minh hoạ:

```yaml
authoring:
  worksheet_version: dictionary-authoring-v1
  review_state: BLOCKED
  example_status: EXAMPLE_ONLY_NO_LIVE_WRITE

entry:
  preferred_form: Côn hoa thị
  locale: vi-VN
  public_slug: null  # owner Component giữ canonical route
  lifecycle: REVIEW_REQUIRED

forms: []  # chưa công nhận cách gọi quốc tế/đồng nghĩa tương đương

senses:
  - sense_key: con-hoa-thi-review-1
    definition: null
    definition_status: BLOCKED
    context:
      domain: đồng hồ / linh kiện  # phạm vi làm việc, cần owner review
      usage_scope: [cách gọi tiếng Việt cần xác định chính xác]
      register: REVIEW_REQUIRED
      examples: []
    ambiguity_notes: >-
      Chưa đủ dữ liệu để khẳng định phạm vi vật lý và chưa chứng minh “Côn hoa
      thị” là từ tương đương của một thuật ngữ quốc tế cụ thể.
    semantic_reference:
      type: component
      canonical_id: VERIFY_FROM_AUTHORITY_READ_BACK
      stable_key: nhk:component:con-hoa-thi
      observed_revision: VERIFY_FROM_AUTHORITY_READ_BACK
      current_route: /linh-kien/con-hoa-thi/
      status: PENDING_REVALIDATION

attestations:
  - source_kind: EXTERNAL_RESEARCH
    source_id_or_url: docs/audits/2026-10-06-con-hoa-thi-public-dossier-audit.md
    locator: Executive result; claim/source matrix; international research boundary
    observed_text: "Côn hoa thị"
    observed_at: 2026-10-06
    scope: lexical/audit context only
    public_display: INTERNAL_ONLY
  - source_kind: EXTERNAL_RESEARCH
    source_id_or_url: <international source record after governed capture>
    locator: <exact page/paragraph/patent section>
    observed_text: <bounded wording only>
    observed_at: <timestamp>
    scope: <physical/configuration claim actually supported>
    public_display: EXCLUDE

publication:
  canonical_mode: DELEGATED
  canonical_url: /linh-kien/con-hoa-thi/
  dictionary_url: /tu-dien/con-hoa-thi/  # compatibility/read path only
  indexable: false
  sitemap: false
  reason: >-
    Owner route is canonical; literal international equivalence and the public
    factual sentence remain unsupported or require governed Knowledge/Evidence.
  last_read_back_at: null

review:
  resolution: AMBIGUOUS
  duplicate_audit: REQUIRED
  reviewer: null
  revision: null
  idempotency_key: null
  read_back_receipt: null
```

### 6.1. Điều mẫu này được phép và không được phép nói

Được phép ghi trong hồ sơ biên tập:

- thuật ngữ đang được nghiên cứu;
- Component route/stable key sau khi read-back;
- nguồn nào đã quan sát wording và phần nào nguồn đó thực sự hỗ trợ;
- trạng thái `PENDING_REVALIDATION`, `AMBIGUOUS`, `BLOCKED`;
- yêu cầu tiếp theo: xác định wording vật lý, duplicate audit và governed
  Knowledge/Evidence nếu cần công bố claim.

Không được xuất bản từ mẫu này:

- “Côn hoa thị” chắc chắn là synonym/translation của thuật ngữ quốc tế;
- “được ghi nhận trên các mẫu đồng hồ Junghans” như một fact khi chỉ có nguồn
  Việt Nam chưa đạt public evidence policy;
- quan hệ tới Brand, Movement, Music, Classification, Specimen, Product,
  Article, Media hay Video chỉ vì có tên/ảnh/đường dẫn;
- UUID, stable key, revision, candidate confidence, source ID hoặc ghi chú
  review nội bộ;
- một Dictionary page indexable cạnh tranh với Component owner.

## 7. Checklist trước khi bấm duyệt

### Identity và duplicate

- [ ] Đã exact/alias lookup trước khi tạo Entry mới.
- [ ] Đã kiểm tra cùng chuỗi chữ có nhiều Sense/context hay không.
- [ ] Đã kiểm tra Authority/Public Identity, Knowledge, Article và candidate
      queue; không suy owner từ slug/title/URL.
- [ ] Nếu có duplicate, đã giữ cả lineage và có quyết định curation rõ ràng.

### Lexical quality

- [ ] Preferred form có dấu và đúng cách viết hiển thị.
- [ ] Mỗi alternate/colloquial/technical/phonetic form có kind, locale,
      context và lý do.
- [ ] Hidden form không rơi ra archive, search result hay JSON-LD.
- [ ] Definition chỉ giải nghĩa từ; factual claim đã handoff cho Knowledge/
      Authority đúng owner.
- [ ] Ambiguity không bị che bằng một định nghĩa chung.

### Owner, provenance, public route

- [ ] semantic reference là mapping đã persist và revalidate, không chỉ là
      owner hint hoặc URL.
- [ ] Source locator đủ để người khác mở lại và hiểu scope; attestation không
      bị gọi là Evidence.
- [ ] Delegated route link thẳng owner; Dictionary route noindex/sitemap false.
- [ ] Dedicated route có public identity, một canonical path và indexability
      được contract cho phép.
- [ ] Read-back sau curated write ghi revision/idempotency/result; replay không
      tạo duplicate.

### Public presentation

- [ ] Có title, preferred form, definition/context phù hợp.
- [ ] Related owner/content chỉ hiện khi public projection đủ điều kiện.
- [ ] `AVAILABLE_EMPTY`, `UNAVAILABLE`, `BLOCKED` được phân biệt bằng copy dễ
      hiểu; không hiển thị internal status token.
- [ ] Không có operational ID, raw predicate, source ID, private candidate,
      confidence hay review note trong HTML/JSON-LD.
- [ ] Không có claim, media hay relation nào được suy ra chỉ từ reachability.

## 8. Tra cứu về sau cần tìm ở đâu?

| Câu hỏi | Nguồn chuẩn cần tra |
|---|---|
| Từ này đã có chưa? | Dictionary approved Entry/Form/Sense và hidden lookup forms |
| Nghĩa nào đang dùng? | Sense + locale/domain/register/usage scope + ambiguity notes |
| Từ này trỏ tới đâu? | Persisted semantic reference rồi đọc owner Public Identity hiện tại |
| Ai chứng minh cách dùng? | Lexical attestation/source locator; không nhầm với Knowledge Evidence |
| Claim vật lý/lịch sử nằm đâu? | Knowledge claim + Source/Evidence governed read model |
| Ảnh/video liên quan có phải bằng chứng không? | Media/Video owner và projection; mặc định chỉ là minh hoạ |
| Có được index không? | Dictionary SEO decision + current canonical route/read-back |
| Vì sao chưa link/xuất bản? | Review state, resolution, ambiguity, duplicate audit và blocked reason |
| Có thể sửa lại không? | lexical UUID/owner ID, current revision, idempotency key và read-back receipt |

## 9. Tài liệu liên quan và giới hạn hiện tại

- `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` — lexical
  objects, resolver, source trust, SEO, storage và acceptance law.
- `docs/architecture/DICTIONARY_ENTRY_SENSE_ARCHITECTURE.md` — Entry/Form/
  Sense target và delegated detail.
- `docs/architecture/DICTIONARY_SEMANTIC_ENRICHMENT_PROJECTION_CONTRACT.md` —
  owner projection, relation/facet boundaries và Côn hoa thị canary law.
- `docs/architecture/DICTIONARY_ENRICHMENT_AUDIT_OPERATIONS.md` — audit → plan
  → review → apply → read-back, với apply vẫn bounded/internal.
- `docs/audits/2026-10-06-con-hoa-thi-public-dossier-audit.md` — bằng chứng
  read-only hiện có và những claim chưa được phép xuất bản.
- `docs/seo/PUBLIC_URL_SLUG_CONTRACT.md` — public identity/slug ownership.

Tại thời điểm viết tài liệu này, Entry/Sense mapping-first read path đã có
code-side coverage; preferred-wording synchronization, context-qualified sense
filtering, đầy đủ Knowledge destination lifecycle và governed Dictionary Media
binding vẫn là implementation gap. Không được đọc tài liệu này như tuyên bố
rằng runtime/staging đã có đủ dữ liệu hoặc đã chấp nhận `Côn hoa thị`.
