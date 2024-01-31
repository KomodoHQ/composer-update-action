<?php

namespace App\Commands;

use App\Facades\Git;
use App\Facades\GitHub;
use CzProject\GitPhp\GitException;
use Github\AuthMethod;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class UpdateCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'update';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'composer update';

    /**
     * @var string
     */
    protected $repo;

    /**
     * @var string
     */
    protected $base_path;

    /**
     * @var string
     */
    protected $parent_branch;

    /**
     * @var string
     */
    protected $new_branch;

    /**
     * @var string
     */
    protected $out;

    /**
     * Execute the console command.
     * @throws GitException
     */
    public function handle()
    {
        $this->init();

        if (! $this->exists()) {
            return; // @codeCoverageIgnore
        }

        if ($this->composerUpdateAllowExists()) {
            $output = $this->process('upgrade-packages');
        } else {
            $output = $this->process(env('COMPOSER_PACKAGES') ? 'update-packages' : 'update');
        }

        echo $output;

        $this->output($output);

        if (! Git::hasChanges()) {
            $this->info('No changes after update.'); // @codeCoverageIgnore

            return; // @codeCoverageIgnore
        }

        $this->commitPush();

        $this->createPullRequest();
    }

    /**
     * @return void
     */
    protected function init(): void
    {
        $this->info('Initializing ...');

        $this->repo = env('GITHUB_REPOSITORY', '');

        $this->base_path = env('GITHUB_WORKSPACE', '').env('COMPOSER_PATH', '');

        Git::execute('config', '--global', '--add', 'safe.directory', env('GITHUB_WORKSPACE', ''));
        Git::execute('config', '--global', '--add', 'safe.directory', $this->base_path);

        $this->parent_branch = Git::getCurrentBranchName();

        $this->info('Repository checked out on branch "'.$this->parent_branch.'"');

        $this->info('Creating new branch ...');

        $useMaintenanceBranchNameConvention = env('APP_USE_MAINTENANCE_BRANCH_CONVENTION');

        if ($useMaintenanceBranchNameConvention) {
            $this->new_branch = 'maintenance/'. strtolower(date('F-Y'));
        } else {
            $this->new_branch = 'cu/'.Str::random(8);
        }

        $appSingleBranch = env('APP_SINGLE_BRANCH');

        if ($appSingleBranch && !$useMaintenanceBranchNameConvention) {
            $this->new_branch = $this->parent_branch.env('APP_SINGLE_BRANCH_POSTFIX', '-updated');

            $this->info('Using single-branch approach. Branch name: "'.$this->new_branch.'"');
        }

        $token = env('GITHUB_TOKEN');

        GitHub::authenticate($token, AuthMethod::ACCESS_TOKEN);

        Git::setRemoteUrl(
            'origin',
            "https://{$token}@github.com/{$this->repo}.git"
        );

        Git::execute('config', '--local', 'user.name', env('GIT_NAME', 'cu'));
        Git::execute('config', '--local', 'user.email', env('GIT_EMAIL', 'cu@composer-update'));

        $this->info('Fetching from remote.');

        /**
         * In the event of an error, we want to catch it and exit.
         */
        try {
            Git::fetch('origin');
        } catch (GitException $e) {
            $this->info($e->getRunnerResult()->toText()); // @codeCoverageIgnore

            exit(1);
        }

        if (
            !$appSingleBranch
            || ! in_array('remotes/origin/'.$this->new_branch, Git::getBranches() ?? [])
        ) {
            $this->info('Creating branch "'.$this->new_branch.'".');

            Git::createBranch($this->new_branch, true);
        }

        if ($appSingleBranch && !$useMaintenanceBranchNameConvention) {
            $this->info('Checking out branch "'.$this->new_branch.'".');

            Git::checkout($this->new_branch);

            $this->info('Pulling from origin.');

            Git::pull('origin');

            $this->info('Merging from "'.$this->parent_branch.'".');

            Git::merge($this->parent_branch, [
                '--strategy-option=theirs',
                '--quiet',
            ]);
        }

        $this->token();
    }

    /**
     * @return bool
     */
    protected function exists(): bool
    {
        return File::exists($this->base_path.'/composer.json')
            && File::exists($this->base_path.'/composer.lock');
    }

    protected function composerUpdateAllowExists(): bool
    {
        return File::exists($this->base_path.'/composer_update_allowlist.txt');
    }

    /**
     * @param  string  $command
     *
     * @return string
     */
    protected function process(string $command): string
    {
        $this->info($command);

        /**
         * @var Process $process
         */
        $process = app('process.'.$command)
            ->setWorkingDirectory($this->base_path)
            ->setTimeout(600)
            ->setEnv(
                [
                    'COMPOSER_MEMORY_LIMIT' => '-1',
                ]
            )
            ->mustRun();

        $output = $process->getOutput();
        if (blank($output)) {
            $output = $process->getErrorOutput(); // @codeCoverageIgnore
        }

        return $output ?? '';
    }

    /**
     * Set GitHub token for composer.
     *
     * @return void
     */
    protected function token(): void
    {
        /**
         * @var Process $process
         */
        $process = app('process.token')
            ->setWorkingDirectory($this->base_path)
            ->setTimeout(60)
            ->mustRun();
    }

    /**
     * @param  string  $output
     *
     * @return void
     */
    protected function output(string $output): void
    {
        $this->out = Str::of($output)
                        ->explode(PHP_EOL)
                        ->filter(fn ($item) => Str::contains($item, ' - '))
                        ->reject(fn ($item) => Str::contains($item, 'Downloading '))
                        ->takeUntil(fn ($item) => Str::contains($item, ':'))
                        ->implode(PHP_EOL).PHP_EOL;

        $this->line($this->out);
    }

    /**
     * @throws GitException
     */
    protected function commitPush(): void
    {
        $this->info('Committing changes ...');

        /**
         * Ensure we catch any errors from the push event.
         */
        try {
        Git::addAllChanges()
                ->commit(
                    env('GIT_COMMIT_PREFIX', '')
                    . 'Composer Automated Update '
                    . today()->toDateString()
                    . PHP_EOL
                    . PHP_EOL
                    . $this->out
                )
                ->push(['origin', $this->new_branch]);
        } catch (GitException $e) {
            $this->info($e->getRunnerResult()->toText()); // @codeCoverageIgnore

            exit(1);
        }
    }

    /**
     * @return void
     */
    protected function createPullRequest(): void
    {
        $this->info('Pull Request');

        $date = env('APP_SINGLE_BRANCH') ? '' : ' '.today()->toDateString();

        $pullData = [
            'base'  => Str::afterLast(env('GITHUB_REF'), '/'),
            'head'  => $this->new_branch,
            'title' => env('GIT_COMMIT_PREFIX', '').'Composer update with '
                .(count(explode(PHP_EOL, $this->out)) - 1).' changes'
                .$date,
            'body'  => $this->out,
        ];

        $createPullRequest = true;

        if (env('APP_SINGLE_BRANCH')) {
            $pullRequests = GitHub::api('pullRequest')->all(
                Str::before($this->repo, '/'),
                Str::afterLast($this->repo, '/'),
                [
                    'head'  => Str::before($this->repo, '/').':'.$this->new_branch,
                    'state' => 'open',
                ]
            );

            if (count($pullRequests) > 0) {
                $createPullRequest = false;
            }
        }

        if ($createPullRequest || ! isset($pullRequests)) {
            $result = GitHub::api('pullRequest')->create(
                Str::before($this->repo, '/'),
                Str::afterLast($this->repo, '/'),
                $pullData
            );

            $this->info('Pull request created for branch "'.$this->new_branch.'": '.$result['html_url']);
        } else {
            $this->info('Pull request already exists for branch "'.$this->new_branch.'": '.$pullRequests[0]['html_url']);
        }
    }
}
