<?php

namespace App\Actions;

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\File;

class PackagesRequire
{
    public function __invoke(string $path): string
    {
        $filePath = $path.'/composer_update_allowlist.txt';

        if (!File::exists($filePath)) {
            return 'The composer_update_allowlist file missing.';
        }

        /**
         * Get contents of composer_update_allowlist.txt.
         */
        $packages = File::get($filePath);

        if (empty($packages)) {
            return 'No packages are allowed to be updated.';
        }

        $packagesToArray =  explode("\n", $packages);

        $cmd = [
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

        $cmd = $this->getUpdatedCommandArguments($cmd, $packagesToArray);

        /** @var ProcessResult $result */
        $result = Process::composer($path)->run($cmd);

        if (filled($result->output()) && $result->successful()) {
            return trim($result->output()); // @codeCoverageIgnore
        }

        return trim($result->errorOutput());
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
