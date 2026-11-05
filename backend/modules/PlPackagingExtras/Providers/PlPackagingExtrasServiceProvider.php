<?php

namespace Modules\PlPackagingExtras\Providers;

use Illuminate\Support\ServiceProvider;

class PlPackagingExtrasServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'pl-packaging-extras');
    }
}
