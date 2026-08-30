# Media model

Media là semantic entity độc lập. Media identity tách khỏi media usage: cùng
một binary có thể dùng cho nhiều Post, gallery Model hoặc Component mà không
nhân bản Media. Checksum chỉ phát hiện duplicate binary, không tự merge semantic
identity. Nguyên tắc: asset một lần, relation nhiều lần, usage nhiều lần.
