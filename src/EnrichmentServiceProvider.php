<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel;

use Elvesora\Enrichment\Laravel\Contracts\EnrichmentClientInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class EnrichmentServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->mergeConfigFrom($this->configPath(), 'enrichment');

        $this->app->singleton(
            EnrichmentClientInterface::class,
            function (Container $container): EnrichmentClientInterface {
                $config = $container->make(ConfigRepository::class);

                return new Client(
                    apiKey: $this->requiredString($config, 'enrichment.api_key'),
                    baseUrl: $this->requiredString($config, 'enrichment.base_url'),
                    timeout: $this->requiredNumber($config, 'enrichment.timeout'),
                    connectTimeout: $this->requiredNumber($config, 'enrichment.connect_timeout'),
                    userAgent: $this->requiredString($config, 'enrichment.user_agent'),
                );
            },
        );

        $this->app->alias(EnrichmentClientInterface::class, Client::class);
    }

    public function boot(): void {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->configPath() => config_path('enrichment.php'),
        ], 'enrichment-config');
    }

    private function configPath(): string {
        return dirname(__DIR__).'/config/enrichment.php';
    }

    private function requiredString(ConfigRepository $config, string $key): string {
        $value = $config->get($key);
        if (!is_string($value) || trim($value) === '') {
            if ($key === 'enrichment.api_key') {
                throw new LogicException('Enrichment is not configured. Set ELVESORA_ENRICHMENT_API_KEY.');
            }

            throw new LogicException(sprintf('Enrichment configuration value "%s" must be a non-empty string.', $key));
        }

        return $value;
    }

    private function requiredNumber(ConfigRepository $config, string $key): float {
        $value = $config->get($key);
        if (!is_int($value) && !is_float($value) && !is_numeric($value)) {
            throw new LogicException(sprintf('Enrichment configuration value "%s" must be numeric.', $key));
        }

        $number = (float) $value;
        if (!is_finite($number)) {
            throw new LogicException(sprintf('Enrichment configuration value "%s" must be finite.', $key));
        }

        return $number;
    }
}
