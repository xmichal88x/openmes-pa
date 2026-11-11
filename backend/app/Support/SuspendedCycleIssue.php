<?php

namespace App\Support;

use App\Models\IssueType;

/**
 * The "Machine Cycle Suspended" issue type needs a description: it is the only
 * record of what was on the machine and since when, which is what the next
 * operator needs to resume the cycle. One place for the rule, shared by the
 * operator dialog, the Issues page and the API.
 */
final class SuspendedCycleIssue
{
    public const CODE = 'MACHINE_CYCLE_SUSPENDED';

    public const MIN_DESCRIPTION = 10;

    public static function typeIs(mixed $issueTypeId): bool
    {
        if ($issueTypeId === null || $issueTypeId === '') {
            return false;
        }

        return IssueType::whereKey($issueTypeId)->where('code', self::CODE)->exists();
    }

    public static function descriptionIsValid(?string $description): bool
    {
        return mb_strlen(trim((string) $description)) >= self::MIN_DESCRIPTION;
    }

    public static function errorMessage(): string
    {
        return __('Describe what is on the machine, which sheet, and since when (at least 10 characters).');
    }
}
