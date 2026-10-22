<?php

declare(strict_types=1);

namespace PactivaSign\Laravel;

use Illuminate\Contracts\Container\Container;
use PactivaSign\Exception\ConfigurationException;
use PactivaSign\Key\KeyProvider;
use PactivaSign\Key\LocalKey;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
final class KeyResolver
{
    /**
     * @param array{provider?: class-string<KeyProvider>|null, jwk?: string|null, path?: string|null} $config
     */
    public static function resolve(Container $container, array $config, string $name, LoggerInterface $logger): KeyProvider
    {
        if (!empty($config['provider'])) {
            $provider = $container->make($config['provider']);
            if (!$provider instanceof KeyProvider) {
                throw new ConfigurationException(sprintf('pactivasign.%s.provider must implement %s.', $name, KeyProvider::class));
            }

            return $provider;
        }
        if (!empty($config['jwk'])) {
            return LocalKey::fromJwk($config['jwk']);
        }
        if (!empty($config['path'])) {
            if (!is_file($config['path'])) {
                throw new ConfigurationException(sprintf(
                    'The PactivaSign %s file %s does not exist. Run `php artisan pactivasign:keys` or set pactivasign.%s.',
                    str_replace('_', ' ', $name),
                    $config['path'],
                    $name,
                ));
            }

            return LocalKey::fromFile($config['path'], logger: $logger);
        }

        throw new ConfigurationException(sprintf('No PactivaSign %s is configured (pactivasign.%s).', str_replace('_', ' ', $name), $name));
    }
}
