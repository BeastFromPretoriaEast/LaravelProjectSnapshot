<?php
namespace InfoPixel\ProjectSnapshot;

use Illuminate\Support\ServiceProvider;
use InfoPixel\ProjectSnapshot\Console\SnapshotCommand;

class ProjectSnapshotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/project-snapshot.php',
            'project-snapshot'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SnapshotCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/project-snapshot.php' =>
                    config_path('project-snapshot.php'),
            ], 'project-snapshot-config');
        }
    }
}
