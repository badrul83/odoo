<?php
declare(strict_types=1);

namespace Erp\Core\Security;

final class UserContext
{
    /** @param list<int> $companyIds */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $login,
        public readonly bool $superuser,
        public readonly array $companyIds,
        public readonly ?int $defaultCompanyId,
    ) {
    }
}
