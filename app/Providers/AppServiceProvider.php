<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Swap\SlippageCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Built from config once per request rather than resolved ad hoc, so
        // the fee schedule is a single configured object instead of constants
        // scattered through the swap path.
        $this->app->singleton(SlippageCalculator::class, fn (): SlippageCalculator => SlippageCalculator::fromConfig());
    }

    public function boot(): void
    {
        // Fail loudly on a typo'd attribute rather than silently writing
        // nothing — in a ledger, a silently dropped field is a lost movement.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Accessing an unloaded relation inside a loop is an N+1 waiting to
        // happen; surface it in development instead of in production latency.
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
