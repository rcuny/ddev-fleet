<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * The per-project access rules AutheliaConfigRenderer puts after the fixed rules. The implementation reads them from
 * the database (ProjectAccessRules); a host without any returns none.
 */
interface AccessRuleSource
{
    /**
     * @return list<AccessRule> one per project, ordered by project name
     */
    public function rules(): array;
}
