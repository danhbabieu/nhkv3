<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

/**
 * Presentation-only registry for NHK V3 Admin navigation.
 *
 * This registry describes existing owners and safe destinations. It does not
 * authorize operations, resolve canonical identity or persist domain data.
 */
final class AdminWorkbenchRegistry
{
    /** @return list<array{id:string,slug:string,label:string,description:string,owner:string,capability:string,href:string,kind:string}> */
    public function sections(): array
    {
        return [
            [
                'id' => 'overview',
                'slug' => 'nhk-v3',
                'label' => 'Tổng quan',
                'description' => 'Điểm bắt đầu theo công việc, quyền hiện có và ranh giới dữ liệu.',
                'owner' => 'Admin Adapter',
                'capability' => 'manage_options',
                'href' => 'admin.php?page=nhk-v3',
                'kind' => 'workbench',
            ],
            [
                'id' => 'content',
                'slug' => 'nhk-v3-content',
                'label' => 'Nội dung',
                'description' => 'Bài viết và biên tập do WordPress sở hữu; semantic readiness là lớp riêng.',
                'owner' => 'WordPress',
                'capability' => 'edit_posts',
                'href' => 'edit.php',
                'kind' => 'native',
            ],
            [
                'id' => 'media',
                'slug' => 'nhk-v3-media',
                'label' => 'Media',
                'description' => 'Quản lý attachment trong WordPress; identity, asset và usage vẫn theo Media V3.',
                'owner' => 'Media + WordPress',
                'capability' => 'upload_files',
                'href' => 'upload.php',
                'kind' => 'native',
            ],
            [
                'id' => 'video',
                'slug' => 'nhk-v3-video',
                'label' => 'Video',
                'description' => 'Kiểm tra và điều phối Video qua luồng governed hiện có, không tạo writer mới.',
                'owner' => 'Video + Governance',
                'capability' => 'nhk_create_proposals',
                'href' => 'admin.php?page=nhk-v3-advanced#video',
                'kind' => 'advanced',
            ],
            [
                'id' => 'knowledge',
                'slug' => 'nhk-v3-knowledge',
                'label' => 'Tri thức',
                'description' => 'Tra cứu canonical entity, Knowledge, Source, Evidence và quan hệ đã đăng ký.',
                'owner' => 'Knowledge + Source/Evidence',
                'capability' => 'nhk_view_governance',
                'href' => 'admin.php?page=nhk-v3-advanced#semantic-read',
                'kind' => 'advanced',
            ],
            [
                'id' => 'governance',
                'slug' => 'nhk-v3-governance',
                'label' => 'Duyệt thay đổi',
                'description' => 'Theo dõi Proposal, approval, eligibility, Controlled Apply và read-back.',
                'owner' => 'Governance',
                'capability' => 'nhk_view_governance',
                'href' => 'admin.php?page=nhk-v3-advanced#governance',
                'kind' => 'advanced',
            ],
            [
                'id' => 'dictionary',
                'slug' => 'nhk-v3-dictionary',
                'label' => 'Từ điển',
                'description' => 'Duyệt thuật ngữ và candidate lexical; không tự nâng thành semantic truth.',
                'owner' => 'Dictionary',
                'capability' => 'nhk_curate_dictionary',
                'href' => 'admin.php?page=nhk-v3-dictionary',
                'kind' => 'workbench',
            ],
            [
                'id' => 'coverage',
                'slug' => 'nhk-v3-dossier-coverage',
                'label' => 'Hồ sơ dữ liệu',
                'description' => 'Audit read-only về coverage của entity, quan hệ, tri thức, ảnh, Video và Article.',
                'owner' => 'Read-only Projection',
                'capability' => 'manage_options',
                'href' => 'admin.php?page=nhk-v3-dossier-coverage',
                'kind' => 'workbench',
            ],
            [
                'id' => 'system',
                'slug' => 'nhk-v3-system',
                'label' => 'Hệ thống',
                'description' => 'Health, migration status và diagnostics kỹ thuật ở chế độ kiểm soát.',
                'owner' => 'Runtime / Infrastructure',
                'capability' => 'manage_options',
                'href' => 'admin.php?page=nhk-v3-advanced#system',
                'kind' => 'advanced',
            ],
            [
                'id' => 'advanced',
                'slug' => 'nhk-v3-advanced',
                'label' => 'Nâng cao',
                'description' => 'Công cụ kỹ thuật hiện có dành cho tra cứu sâu và thao tác Governance.',
                'owner' => 'NHK V3 Control Plane',
                'capability' => 'manage_options',
                'href' => 'admin.php?page=nhk-v3-advanced',
                'kind' => 'advanced',
            ],
        ];
    }

    /** @return array{id:string,slug:string,label:string,description:string,owner:string,capability:string,href:string,kind:string}|null */
    public function section(string $id): ?array
    {
        foreach ($this->sections() as $section) {
            if ($section['id'] === $id) return $section;
        }

        return null;
    }
}
