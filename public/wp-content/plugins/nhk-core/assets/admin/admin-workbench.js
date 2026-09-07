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
            contextOutput.innerHTML = '<p><strong>' + (video.title || 'Video') + '</strong> · ' + (video.platform || '') + ' / ' + (video.external_id || '') + '</p>' +
                '<p>Trạng thái canonical: <strong>' + (video.active ? 'active' : 'chưa active') + '</strong>; proposal: <code>' + (proposal.id || 'chưa xác định') + '</code> · ' + (proposal.state || 'BLOCKED') + '</p>' +
                (data.diagnostic ? '<p class="notice notice-warning"><code>' + data.diagnostic + '</code> — cần đúng Video ingest proposal để giữ fingerprint.</p>' : '') +
                '<p>Predicate cố định: <code>' + data.predicate + '</code>; Evidence origin: <code>' + data.evidence_origin + '</code></p>';
            request(base + 'nhk/v1/graph/outgoing/video/' + encodeURIComponent(video.id) + '?predicate=about').then(function (graph) {
                var items = graph.items || [];
                if (items.length) contextOutput.innerHTML += '<p><strong>Relation hiện có:</strong> ' + items.map(function (item) { return '<code>' + item.target.type + ':' + item.target.key + '</code>'; }).join(', ') + '</p>';
                else contextOutput.innerHTML += '<p>Relation hiện có: chưa có.</p>';
            }).catch(function () {});
        }
        contextButton.addEventListener('click', function () {
            var id = String(document.getElementById('nhk-video-id').value || '').trim();
            var proposalId = String(document.getElementById('nhk-video-proposal-id').value || '').trim();
            contextOutput.textContent = 'Đang kiểm tra canonical Video và proposal...';
            request(base + 'nhk/v1/admin/video-relation/context/' + encodeURIComponent(id) + '?proposal_id=' + encodeURIComponent(proposalId)).then(showContext).catch(function (error) { contextOutput.textContent = error.message; });
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var evidence = String(document.getElementById('nhk-video-evidence-id').value || '').trim();
            if (!evidence) { resultOutput.textContent = 'EVIDENCE_REFS_REQUIRED — không thể tạo relation proposal thiếu Evidence.'; return; }
            resultOutput.textContent = 'Đang tạo proposal governed...';
            request(base + 'nhk/v1/admin/video-relation', {method: 'POST', body: JSON.stringify({
                video_id: document.getElementById('nhk-video-id').value.trim(),
                video_proposal_id: document.getElementById('nhk-video-proposal-id').value.trim(),
                target_type: document.getElementById('nhk-video-target-type').value,
                target_id: document.getElementById('nhk-video-target-id').value.trim(),
                evidence_refs: [{evidence_id: evidence}]
            })}).then(function (data) {
                resultOutput.textContent = JSON.stringify(data, null, 2) + '\nTiếp tục mở proposal này ở Governance để Submit, Approve, Eligibility và Controlled Apply.';
            }).catch(function (error) { resultOutput.textContent = error.message; });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', videoRelationWorkspace);
    else videoRelationWorkspace();
}());
