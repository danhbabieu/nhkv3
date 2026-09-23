<?php
declare(strict_types=1);
namespace NHK\Core\Application\Semantic;
interface SubjectStructuralContextReader { /** @param array<string,mixed> $candidate @return array<string,mixed> */ public function contextFor(array $candidate): array; }
