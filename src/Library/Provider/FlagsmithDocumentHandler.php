<?php

declare(strict_types=1);

namespace Kipchak\Driver\FeatureFlags\Library\Provider;

use Flagsmith\Engine\Environments\EnvironmentModel;
use Flagsmith\Engine\Features\FeatureStateModel;
use Flagsmith\Offline\IOfflineHandler;
use Flagsmith\Offline\LocalFileHandler;
use ReflectionProperty;

/**
 * Reads an environment document from a file, like the SDK's LocalFileHandler, and repairs what that leaves broken.
 *
 * The SDK's model builder skips null values, so a feature with no value (an on/off flag, the commonest kind) has
 * an uninitialised $feature_state_value, and the SDK fails with an Error as soon as it reads the document. This
 * sets those values to null.
 *
 * @internal
 */
final class FlagsmithDocumentHandler implements IOfflineHandler
{
    private readonly LocalFileHandler $file;

    public function __construct(string $filePath)
    {
        $this->file = new LocalFileHandler($filePath);
    }

    public function getEnvironment(): ?EnvironmentModel
    {
        $environment = $this->file->getEnvironment();
        if ($environment !== null) {
            $seen = [];
            self::repair($environment, $seen);
        }

        return $environment;
    }

    /**
     * @param array<int, true> $seen object ids already repaired
     */
    private static function repair(object $model, array &$seen): void
    {
        if (isset($seen[spl_object_id($model)])) {
            return;
        }
        $seen[spl_object_id($model)] = true;

        if ($model instanceof FeatureStateModel) {
            $value = new ReflectionProperty(FeatureStateModel::class, 'feature_state_value');
            if (!$value->isInitialized($model)) {
                $model->feature_state_value = null;
            }
        }

        foreach (is_iterable($model) ? $model : get_object_vars($model) as $child) {
            if (is_object($child)) {
                self::repair($child, $seen);
            }
        }
    }
}
