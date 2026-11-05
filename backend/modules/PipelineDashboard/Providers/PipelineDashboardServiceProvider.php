<?php

namespace Modules\PipelineDashboard\Providers;

use App\Events\BatchStep\StepCompleted;
use App\Events\BatchStep\StepStarted;
use App\Events\WorkOrder\WorkOrderCompleted;
use App\Events\WorkOrder\WorkOrderCreated;
use App\Services\MenuRegistry;
use App\Services\WidgetRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\PipelineDashboard\Hooks;

class PipelineDashboardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../Views', 'pipeline-dashboard');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        $this->registerEventHooks();
        $this->registerMenuHooks();
        $this->registerWidgetHooks();
    }

    private function registerEventHooks(): void
    {
        Event::listen(WorkOrderCreated::class, [Hooks::class, 'onWorkOrderCreated']);
        Event::listen(WorkOrderCompleted::class, [Hooks::class, 'onWorkOrderCompleted']);
        Event::listen(StepStarted::class, [Hooks::class, 'onStepStarted']);
        Event::listen(StepCompleted::class, [Hooks::class, 'onStepCompleted']);
    }

    private function registerMenuHooks(): void
    {
        $menu = app(MenuRegistry::class);
        $menu->addItem('production', 'Pipeline', url('/modules/pipeline'), order: 95);
    }

    private function registerWidgetHooks(): void
    {
        $widgets = app(WidgetRegistry::class);
        $widgets->register('kpi', [
            'title' => 'Pipeline',
            'metric' => '-',
            'body' => 'open orders',
            'href' => url('/modules/pipeline'),
            'external' => true,
        ], order: 80);
    }
}
