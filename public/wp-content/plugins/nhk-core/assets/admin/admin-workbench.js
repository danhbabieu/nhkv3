(function () {
    'use strict';

    var aliases = {
        'system': 'nhk-migration-ledger-heading',
        'semantic-read': 'nhk-semantic-lookup-heading',
        'governance': 'nhk-proposal-lookup-heading',
        'video': 'nhk-proposal-composer-heading'
    };

    function focusHashTarget() {
        var hash = window.location.hash.replace(/^#/, '');
        if (!hash) return;

        var target = document.getElementById(hash) || document.getElementById(aliases[hash] || '');
        if (!target) return;

        target.setAttribute('tabindex', '-1');
        target.classList.add('nhk-admin-focus-target');
        target.focus({ preventScroll: true });
        target.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        target.addEventListener('blur', function cleanup() {
            target.classList.remove('nhk-admin-focus-target');
            target.removeAttribute('tabindex');
            target.removeEventListener('blur', cleanup);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', focusHashTarget);
    } else {
        focusHashTarget();
    }

    window.addEventListener('hashchange', focusHashTarget);

    function videoRelationWorkspace() {
        var form = document.getElementById('nhk-video-relation-form');
        if (!form) return;
        var nonce = window.nhkV3Admin && window.nhkV3Admin.nonce ? window.nhkV3Admin.nonce : '';
        var base = (window.nhkV3Admin && window.nhkV3Admin.root) || (window.location.origin + '/wp-json/');
        var contextButton = document.getElementById('nhk-video-context');
        var contextOutput = document.getElementById('nhk-video-context-result');
        var resultOutput = document.getElementById('nhk-video-relation-result');
        function request(url, options) {
            options = options || {};
            options.headers = Object.assign({'X-WP-Nonce': nonce, 'Content-Type': 'application/json'}, options.headers || {});
            return fetch(url, options).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) throw new Error((data.message || (data.data && data.data.message) || data.code || 'REQUEST_FAILED') + ' [' + (data.code || 'ADMIN_ERROR') + ']');
                    return data;
                });
            });
        }
        function showContext(data) {
            var video = data.video || {};
            var proposal = data.video_proposal || {};
            var provenance = data.provenance || {};
            contextOutput.innerHTML = '<p><strong>' + (video.title || 'Video') + '</strong> · ' + (video.platform || '') + ' / ' + (video.external_id || '') + '</p>' +
                '<p>Trạng thái canonical: <strong>' + (video.active ? 'active' : 'chưa active') + '</strong>; proposal: <code>' + (proposal.id || 'chưa xác định') + '</code> · ' + (proposal.state || 'BLOCKED') + '</p>' +
                (data.diagnostic ? '<p class="notice notice-warning"><code>' + data.diagnostic + '</code></p>' : '') +
                '<p>Predicate cố định: <code>' + data.predicate + '</code>; Evidence origin: <code>' + data.evidence_origin + '</code></p>' +
                '<p><strong>Evidence sẽ dùng:</strong> provenance <code>' + (provenance.platform || '') + '/' + (provenance.external_id || '') + '</code>, locator <code>' + (provenance.locator || '') + '</code>, visibility <code>' + (provenance.visibility || '') + '</code>.</p>';
            request(base + 'nhk/v1/graph/outgoing/video/' + encodeURIComponent(video.id) + '?predicate=about').then(function (graph) {
                var items = graph.items || [];
                if (items.length) contextOutput.innerHTML += '<p><strong>Relation hiện có:</strong> ' + items.map(function (item) { return '<code>' + item.target.type + ':' + item.target.key + '</code>'; }).join(', ') + '</p>';
                else contextOutput.innerHTML += '<p>Relation hiện có: chưa có.</p>';
            }).catch(function () {});
        }
        contextButton.addEventListener('click', function () {
            var id = String(document.getElementById('nhk-video-id').value || '').trim();
            contextOutput.textContent = 'Đang kiểm tra canonical Video và proposal...';
            request(base + 'nhk/v1/admin/video-relation/context/' + encodeURIComponent(id)).then(showContext).catch(function (error) { contextOutput.textContent = error.message; });
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            resultOutput.textContent = 'Đang resolve provenance và tạo proposal governed...';
            request(base + 'nhk/v1/admin/video-relation', {method: 'POST', body: JSON.stringify({
                video_id: document.getElementById('nhk-video-id').value.trim(),
                target_type: document.getElementById('nhk-video-target-type').value,
                target_id: document.getElementById('nhk-video-target-id').value.trim()
            })}).then(function (data) {
                resultOutput.textContent = JSON.stringify(data, null, 2) + '\nEvidence đã được resolve; tiếp tục Submit, Approve, Eligibility, Controlled Apply và đọc lại canonical Video.';
            }).catch(function (error) { resultOutput.textContent = error.message; });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', videoRelationWorkspace);
    else videoRelationWorkspace();

    function workspaceSearch() {
        var forms = document.querySelectorAll('[data-nhk-search]');
        if (!forms.length) return;
        var base = (window.nhkV3Admin && window.nhkV3Admin.root) || (window.location.origin + '/wp-json/');
        forms.forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var query = String(new FormData(form).get('q') || '').trim();
                var workspace = form.getAttribute('data-nhk-search');
                var output = document.getElementById('nhk-' + workspace + '-results') || document.getElementById('nhk-content-results');
                if (!output) return;
                if (query.length < 2) { output.textContent = 'Nhập ít nhất 2 ký tự để tìm kiếm.'; return; }
                output.textContent = 'Đang tìm...';
                var domain = workspace === 'media' ? 'media' : workspace === 'knowledge' ? 'all' : 'all';
                fetch(base + 'nhk/v1/admin/workbench/search?q=' + encodeURIComponent(query) + '&domain=' + domain, {headers: {'X-WP-Nonce': (window.nhkV3Admin && window.nhkV3Admin.nonce) || ''}})
                    .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || data.code || 'Không thể tìm kiếm.'); return data; }); })
                    .then(function (data) { renderSearchResults(output, workspace, data.groups || {}); })
                    .catch(function (error) { output.textContent = error.message; });
            });
        });
    }

    function renderSearchResults(output, workspace, groups) {
        output.textContent = '';
        var keys = workspace === 'media' ? ['media', 'videos'] : workspace === 'knowledge' ? ['entities', 'knowledge'] : ['posts', 'videos', 'entities', 'media', 'knowledge'];
        var count = 0;
        keys.forEach(function (key) {
            (groups[key] || []).forEach(function (item) {
                count++;
                var card = document.createElement('article');
                card.className = 'nhk-admin-result-card';
                var title = document.createElement('h3');
                title.textContent = item.title || item.name || 'Không có tiêu đề';
                card.appendChild(title);
                var meta = document.createElement('p');
                meta.textContent = [item.type || key, item.platform || '', item.external_id || ''].filter(Boolean).join(' · ');
                card.appendChild(meta);
                if (item.type === 'video' && item.id) {
                    var open = document.createElement('button'); open.type = 'button'; open.className = 'button button-secondary'; open.textContent = 'Mở chi tiết'; open.addEventListener('click', function () { loadVideoDetail(item.id); }); card.appendChild(open);
                } else if (item.type === 'media' && item.id) {
                    var mediaOpen = document.createElement('button'); mediaOpen.type = 'button'; mediaOpen.className = 'button button-secondary'; mediaOpen.textContent = 'Mở chi tiết'; mediaOpen.addEventListener('click', function () { loadMediaDetail(item.id); }); card.appendChild(mediaOpen);
                } else if (item.url) { var link = document.createElement('a'); link.href = item.url; link.textContent = 'Xem trên web'; link.target = '_blank'; link.rel = 'noopener'; card.appendChild(link); }
                output.appendChild(card);
            });
        });
        if (!count) { var empty = document.createElement('p'); empty.className = 'nhk-admin-empty'; empty.textContent = 'Không tìm thấy dữ liệu phù hợp hoặc runtime chưa khả dụng.'; output.appendChild(empty); }
    }

    function loadVideoDetail(id) {
        var output = document.getElementById('nhk-video-detail');
        if (!output) return;
        output.textContent = 'Đang tải Video canonical...';
        var base = (window.nhkV3Admin && window.nhkV3Admin.root) || (window.location.origin + '/wp-json/');
        fetch(base + 'nhk/v1/admin/workbench/video/' + encodeURIComponent(id), {headers: {'X-WP-Nonce': (window.nhkV3Admin && window.nhkV3Admin.nonce) || ''}})
            .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || 'Không đọc được Video.'); return data; }); })
            .then(function (data) {
                var video = data.video || {}, metadata = data.metadata || {}, iframe = document.createElement('iframe'); iframe.width = '560'; iframe.height = '315'; iframe.loading = 'lazy'; iframe.title = video.title || 'Video YouTube'; iframe.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(video.external_id || ''); iframe.allowFullscreen = true;
                output.textContent = ''; output.appendChild(iframe);
                var title = document.createElement('h3'); title.textContent = video.title || 'Video'; output.appendChild(title);
                var meta = document.createElement('p'); meta.textContent = [video.platform, video.external_id, video.id, 'revision ' + video.revision].join(' · '); output.appendChild(meta);
                function block(label, value) { var section = document.createElement('section'); var heading = document.createElement('h4'); heading.textContent = label; section.appendChild(heading); var body = document.createElement('p'); body.textContent = value; section.appendChild(body); output.appendChild(section); }
                var editorial = metadata.editorial || {};
                block('Metadata', [editorial.title || video.title || '', editorial.summary || '', metadata.category && metadata.category.primary ? (metadata.category.primary.label || metadata.category.primary.key || '') : ''].filter(Boolean).join(' · ') || 'Chưa có metadata.');
                var relations = data.relations || [];
                block('Relation target', relations.length ? relations.map(function (item) { return [item.target_type, item.target_key, item.predicate].filter(Boolean).join(':'); }).join(', ') : 'Chưa có relation active được đọc từ Graph.');
                var evidence = data.evidence || [];
                block('Source / Claim / Evidence', evidence.length ? evidence.map(function (item) { return [item.source || item.source_id, item.claim || item.claim_id, item.evidence_id, item.relation].filter(Boolean).join(' · '); }).join('\n') : 'Chưa có Evidence chain được đọc lại.');
                var governance = data.governance || {};
                block('Governance', governance.state ? [governance.state, governance.eligible === true ? 'eligible' : governance.eligible === false ? 'blocked' : 'chưa kiểm tra', (governance.blockers || []).join(', ')].filter(Boolean).join(' · ') : 'Chưa có proposal Governance cho Video.');
                var projection = data.frontend_projection || {};
                block('Frontend projection', projection.eligible ? 'Hợp lệ · ' + (projection.path || 'đã sẵn sàng') : 'Chưa hợp lệ · ' + ((projection.blockers || []).join(', ') || 'chưa đủ điều kiện'));
                if (projection.eligible && projection.path) { var link = document.createElement('a'); link.className = 'button'; link.href = projection.path; link.textContent = 'Xem trên web'; output.appendChild(link); }
                if (video.url) { var source = document.createElement('a'); source.className = 'button button-secondary'; source.href = video.url; source.textContent = 'Mở nguồn gốc'; source.target = '_blank'; source.rel = 'noopener noreferrer'; output.appendChild(source); }
            }).catch(function (error) { output.textContent = error.message; });
    }

    function loadMediaDetail(id) {
        var output = document.getElementById('nhk-image-detail');
        if (!output) return;
        output.textContent = 'Đang tải Media canonical...';
        var base = (window.nhkV3Admin && window.nhkV3Admin.root) || (window.location.origin + '/wp-json/');
        fetch(base + 'nhk/v1/admin/workbench/media/' + encodeURIComponent(id), {headers: {'X-WP-Nonce': (window.nhkV3Admin && window.nhkV3Admin.nonce) || ''}})
            .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || 'Không đọc được Media.'); return data; }); })
            .then(function (data) {
                var media = data.media || {};
                output.textContent = '';
                var title = document.createElement('h3'); title.textContent = media.title || 'Hình ảnh'; output.appendChild(title);
                var facts = document.createElement('p'); facts.textContent = [media.stable_key, media.readiness, 'revision ' + media.revision].filter(Boolean).join(' · '); output.appendChild(facts);
                var state = document.createElement('p'); state.textContent = 'Frontend: ' + (data.frontend_state || 'missing') + ' · Vai trò: ' + (data.semantic_role || 'chưa xác định') + ' · Sử dụng: ' + (data.usage_count || 0); output.appendChild(state);
                var usage = document.createElement('p'); usage.textContent = (data.usages || []).map(function (item) { return [item.role, item.endpoint_type, item.endpoint_key, item.alt].filter(Boolean).join(' · '); }).join('\n') || 'Chưa có usage.'; output.appendChild(usage);
                var provenance = document.createElement('p'); provenance.textContent = 'Provenance: ' + JSON.stringify(data.provenance || {}); output.appendChild(provenance);
            }).catch(function (error) { output.textContent = error.message; });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', workspaceSearch);
    else workspaceSearch();
}());
