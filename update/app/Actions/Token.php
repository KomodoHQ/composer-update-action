<?php

namespace App\Actions;

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class Token
{
    public function __invoke(string $path): string
    {
        $cmd = [
            'composer',
            'config',
            '-g',
            'github-oauth.github.com',
            env('GITHUB_TOKEN'),
        ];

        /** @var ProcessResult $result */
        $result = Process::composer($path)->run($cmd);

        if ($result->successful()) {
            return trim($result->output()); // @codeCoverageIgnore
        }

        return trim($result->errorOutput());
    }
}
