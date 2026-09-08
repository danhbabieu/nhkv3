<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Projection;

/**
 * Read-only schema probe for the derived projection read model.
 *
 * This deliberately does not invoke migrations. The result is cached for the
 * lifetime of the request so a missing optional projection cannot create a
 * query storm while the canonical entity page is being rendered.
 */
final class WpdbProjectionSchema
{
    /** @var array{status:string,reason:?string,missing_tables:list<string>}|null */
    private ?array $result = null;

    public function __construct(private object $database) {}

    /** @return array{status:string,reason:?string,missing_tables:list<string>} */
    public function status(): array
    {
        if ($this->result !== null) return $this->result;

        $missing = [];
        foreach (['nhk_claim_projection_revisions', 'nhk_claim_projection_dependencies'] as $suffix) {
            $table = (string) $this->database->prefix . $suffix;
            if ($this->database->get_var($this->database->prepare('SHOW TABLES LIKE %s', $table)) !== $table) $missing[] = $table;
        }

        return $this->result = [
            'status' => $missing === [] ? 'available' : 'unavailable',
            'reason' => $missing === [] ? null : 'MIGRATION_016_SCHEMA_UNAVAILABLE',
            'missing_tables' => $missing,
        ];
    }
}
