<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel\Tests;

use Elvesora\Enrichment\Laravel\EnrichmentServiceProvider;
use Illuminate\Foundation\Application;
use LogicException;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra {
    /**
     * @param \Illuminate\Foundation\Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array {
        return [EnrichmentServiceProvider::class];
    }

    protected function appInstance(): Application {
        if (!$this->app instanceof Application) {
            throw new LogicException('The Testbench application has not been initialized.');
        }

        return $this->app;
    }
}
