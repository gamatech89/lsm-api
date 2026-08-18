<?php

namespace App\Mcp\Concerns;

use Laravel\Mcp\Response;

/**
 * Gate an MCP backup primitive on the backup feature master switch
 * (config('backup.enabled'), BACKUP_ENABLED) in addition to its token scope.
 *
 * Composed on top of HasRequiredScope; the consuming class must resolve the
 * shouldRegister() collision in its favour:
 *
 *     use HasRequiredScope, RequiresBackupFeature {
 *         RequiresBackupFeature::shouldRegister insteadof HasRequiredScope;
 *     }
 *
 * shouldRegister() is the enforcement boundary (see HasRequiredScope for why —
 * ServerContext::resolvePrimitives() filters on it for listing AND
 * invocation). assertBackupFeature() at the top of handle() is the backstop
 * for a caller that invokes handle() directly.
 */
trait RequiresBackupFeature
{
    public function shouldRegister(): bool
    {
        return static::backupFeatureEnabled() && $this->tokenHasRequiredScope();
    }

    protected static function backupFeatureEnabled(): bool
    {
        return (bool) config('backup.enabled', false);
    }

    /**
     * Returns null when the feature is on, or the error to return when it is
     * off. Callers use: if ($denied = $this->assertBackupFeature() ?? $this->assertScope()) return $denied;
     */
    protected function assertBackupFeature(): ?Response
    {
        if (static::backupFeatureEnabled()) {
            return null;
        }

        return Response::error(
            'Backups are currently disabled on this platform (BACKUP_ENABLED=false).'
        );
    }
}
