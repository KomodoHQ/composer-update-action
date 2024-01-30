<?php

namespace App\Providers;

use CzProject\GitPhp\Git;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\File;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(
            'git',
            fn ($app) => (new Git())->open(env('GITHUB_WORKSPACE'))
        );

        $this->app->bind(
            'process.install',
            fn ($app) => new Process($this->command('install'))
        );

        $this->app->bind(
            'process.update',
            fn ($app) => new Process($this->command('update'))
        );

        $this->app->bind(
            'process.update-packages',
            fn ($app) => new Process($this->packages())
        );

        $this->app->bind(
            'process.upgrade-packages',
            fn ($app) => new Process($this->requirePackages())
        );

        $this->app->bind(
            'process.token',
            fn ($app) => new Process($this->token())
        );
    }

    /**
     * @param  string  $cmd
     *
     * @return array
     */
    private function command(string $cmd): array
    {
        return [
            'composer',
            $cmd,
            '--no-interaction',
            '--no-progress',
            '--no-autoloader',
            '--no-scripts',
        ];
    }

    /**
     * @return array
     */
    private function packages(): array
    {
        return [
            'composer',
            'update',
            env('COMPOSER_PACKAGES'),
            '--with-dependencies',
            '--no-interaction',
            '--no-progress',
            '--no-autoloader',
            '--no-scripts',
        ];
    }

    /**
     * @return array
     */
    private function requirePackages(): array
    {
        $cmd =  [
            'composer',
            'require',
            '__COMPOSER_PACKAGES_ARRAY__', // @codeCoverageIgnore
            '--prefer-dist',
            '--with-dependencies',
            '--no-interaction',
            '--no-progress',
            '--no-scripts',
            '--no-cache',
        ];

        return $this->getUpdatedCommandArguments(
            $cmd,
            $this->getAllowedPackageArrayList()
        );
    }

    private function getAllowedPackageArrayList(): array
    {
        $path = env('COMPOSER_PATH', '');
        $filePath = $path.'/composer_update_allowlist.txt';

        try {
            if (!File::exists($filePath)) {
                throw new Exception('The composer_update_allowlist file missing.');
            }

            /**
             * Get contents of composer_update_allowlist.txt.
             */
            $packages = File::get($filePath);

            if (empty($packages)) {
                throw new Exception('No packages are allowed to be updated.');
            }

            return explode("\n", $packages);

        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * @return array
     */
    private function token(): array
    {
        return [
            'composer',
            'config',
            '-g',
            'github-oauth.github.com',
            env('GITHUB_TOKEN'),
        ];
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    private function getUpdatedCommandArguments(array $cmd, array $packagesToArray): array
    {
        // Find the position of '__COMPOSER_PACKAGES_ARRAY__' in $cmd
        $keyPosition = array_search('__COMPOSER_PACKAGES_ARRAY__', $cmd);

        // Remove '__COMPOSER_PACKAGES_ARRAY__' from $cmd
        array_splice($cmd, $keyPosition, 1);

        // Filter out empty values from $packagesToArray
        $packagesToArray = array_filter($packagesToArray);

        // Insert elements from $arr into $cmd at the found position
        array_splice($cmd, $keyPosition, 0, $packagesToArray);

        return $cmd;
    }
}
