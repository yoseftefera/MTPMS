<?php

namespace App\Providers;

use App\Repositories\BudgetRepository;
use App\Repositories\Contracts\BudgetRepositoryInterface;
use App\Repositories\Contracts\PurchaseRequestRepositoryInterface;
use App\Repositories\PurchaseRequestRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register repository interface bindings.
     *
     * Each interface is bound to its concrete implementation so that
     * controllers and services depend on abstractions, not concretions.
     */
    public function register(): void
    {
        $this->app->bind(
            BudgetRepositoryInterface::class,
            BudgetRepository::class,
        );

        $this->app->bind(
            PurchaseRequestRepositoryInterface::class,
            PurchaseRequestRepository::class,
        );
    }
}
