<?php

namespace Makermind\Erd4dev\Commands;

use Illuminate\Console\Command;
use Makermind\Erd4dev\ErdServiceProvider;

class InstallCommand extends Command
{
    protected $signature = 'erd:install {--migrate : Run pending application migrations}';

    protected $description = 'Publish ERD assets and migration';

    public function handle(): int
    {
        $status = $this->call('vendor:publish', [
            '--provider' => ErdServiceProvider::class,
            '--tag' => ['erd-assets'],
            '--force' => true,
        ]);
        if ($status !== self::SUCCESS) {
            return $status;
        }

        $status = $this->call('vendor:publish', [
            '--provider' => ErdServiceProvider::class,
            '--tag' => ['erd-migrations'],
        ]);

        if ($status !== self::SUCCESS) {
            return $status;
        }

        if ($this->option('migrate')) {
            return $this->call('migrate');
        }

        $this->info('Open /erd on your existing application server. Outside the local environment, run php artisan migrate and configure the erd.access Gate.');

        return self::SUCCESS;
    }
}
