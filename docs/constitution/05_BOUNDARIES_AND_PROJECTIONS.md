# 05 — Boundaries and Projections

## 1. Authority / Knowledge / Graph

- Authority sở hữu canonical semantic entities theo runtime contract.
- Knowledge sở hữu atomic claims theo contract.
- Graph là hệ typed relations duy nhất.
- Governance sở hữu durable semantic mutations theo contract.

Không module nào được tạo duplicate truth ngoài boundary của mình.

## 2. Media

Media là first-class semantic entity. Media identity tách khỏi MediaAsset và MediaUsage.

Nguyên tắc:

- asset một lần;
- relation nhiều lần;
- usage nhiều lần;
- checksum chỉ phát hiện binary duplicate, không tự merge semantic identity.

Asset không mặc nhiên là Graph endpoint.

## 3. Video

Video có canonical identity và lifecycle riêng. Không dùng attachment hoặc Media identity để thay authority của Video.

Media và Video chỉ chia sẻ relation/policy primitives khi contract cho phép.

## 4. Source / Evidence / Citation

- Source: nguồn canonical khi runtime đã hỗ trợ;
- Evidence: đơn vị bằng chứng cho claim/relation;
- Citation: representation phục vụ trình bày.

Không tự tạo Source/Evidence field hoặc table nếu registry/contract chưa có.

## 5. Post và URL

WordPress Post giữ editorial body và URL truth theo runtime hiện hành. Semantic entities liên hệ Post qua Graph/application service.

Không tái tạo Article Authority body path và không dùng body Post làm semantic store.

Trong phạm vi Hiến pháp này, không lập kế hoạch migrate/import body bài cũ.

## 6. Specimen / Product

Specimen là hiện vật vật lý. Product là listing/offer. Product có thể trỏ tới Specimen nhưng không thay thế identity của Specimen.

## 7. Frontend / Admin / SEO

Frontend và Admin là projection/interaction surfaces, không phải nguồn canonical truth.

- UI không được invent field hoặc relation chỉ để lấp chỗ trống.
- SEO/URL projection phải dựa trên canonical/entity/Post contract hiện hành.
- Thiếu module phải ẩn hoặc fail rõ ràng thay vì bịa metrics/content.
- Projection có thể tổng hợp derived view nhưng không được materialize semantic truth trái contract.

## 8. MCP

MCP chỉ được expose operation đã governed và đã có contract. MCP không được tạo bypass cho registry, governance, revision, provenance hoặc permission rules.