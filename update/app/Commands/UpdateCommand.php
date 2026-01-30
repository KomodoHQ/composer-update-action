<?php

namespace App\Commands;

use App\Actions\PackagesUpdate;
use App\Actions\PackagesRequire;
use App\Actions\Token;
use App\Actions\Update;
use App\Facades\Git;
use App\Facades\GitHub;
use CzProject\GitPhp\GitException;
use Github\AuthMethod;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

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
    protected string $repo;

    /**
     * @var string
     */
    protected string $base_path;

    /**
     * @var string
     */
    protected string $parent_branch;

    /**
     * @var string
     */
    protected string $new_branch;

    /**
     * @var string
     */
    protected string $out;

    protected array $upgradedPackages = [];
    protected array $composerJsonRequireBefore = [];
    protected array $composerJsonRequireAfter = [];
    protected array $requireConstraintChanges = [];

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

        // Read composer.json require section before update
        $this->composerJsonRequireBefore = $this->readComposerJsonRequire();

        if ($this->composerUpdateAllowExists()) {
            $output = app()->call(PackagesRequire::class, ['path' => $this->base_path]);
        } elseif (filled(env('COMPOSER_PACKAGES'))) {
            $output = app()->call(PackagesUpdate::class, ['path' => $this->base_path]);
        } else {
            $output = app()->call(Update::class, ['path' => $this->base_path]);
        }

        // Read composer.json require section after update
        $this->composerJsonRequireAfter = $this->readComposerJsonRequire();
        $this->requireConstraintChanges = $this->detectRequireConstraintChanges();

        echo $output;

        $this->output($output);

        if (! Git::hasChanges()) {
            $this->info('No changes after update.'); // @codeCoverageIgnore

            return; // @codeCoverageIgnore
        }

        $this->commitPush();

        $this->createPullRequest();
    }

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
     * Set GitHub token for composer.
     */
    protected function token(): void
    {
        app()->call(Token::class, ['path' => $this->base_path]);
    }

    protected function output(string $output): void
    {
        $lines = Str::of($output)
            ->explode(PHP_EOL)
            ->filter(fn ($item) => Str::contains($item, 'Upgrading'))
            ->reject(fn ($item) => Str::contains($item, 'Downloading '));

        $this->upgradedPackages = [];
        foreach ($lines as $line) {
            // Match: Upgrading vendor/package (old => new)
            if (preg_match('/Upgrading ([^ ]+) \(([^ ]+) => ([^\)]+)\)/', $line, $matches)) {
                $this->upgradedPackages[] = [
                    'name' => $matches[1],
                    'from' => $matches[2],
                    'to' => $matches[3],
                ];
            }
        }

        $this->out = $lines->isNotEmpty()
            ? $lines->implode(PHP_EOL).PHP_EOL
            : 'No package updates detected.'.PHP_EOL;

        $this->line($this->out);
    }

    protected function readComposerJsonRequire(): array
    {
        $composerJsonPath = $this->base_path.'/composer.json';
        if (!file_exists($composerJsonPath)) {
            return [];
        }
        $json = json_decode(file_get_contents($composerJsonPath), true);
        return $json['require'] ?? [];
    }

    protected function detectRequireConstraintChanges(): array
    {
        $changes = [];
        foreach ($this->composerJsonRequireBefore as $package => $oldConstraint) {
            if (isset($this->composerJsonRequireAfter[$package])) {
                $newConstraint = $this->composerJsonRequireAfter[$package];
                if ($oldConstraint !== $newConstraint) {
                    $changes[] = [
                        'name' => $package,
                        'from' => $oldConstraint,
                        'to' => $newConstraint,
                    ];
                }
            }
        }
        // Also check for new packages added
        foreach ($this->composerJsonRequireAfter as $package => $newConstraint) {
            if (!isset($this->composerJsonRequireBefore[$package])) {
                $changes[] = [
                    'name' => $package,
                    'from' => '(not required before)',
                    'to' => $newConstraint,
                ];
            }
        }
        return $changes;
    }

    protected function formatPullRequestBody(): string
    {
        $amount = count($this->upgradedPackages);
        $list = '';
        foreach ($this->upgradedPackages as $pkg) {
            $list .= "* {$pkg['name']} from {$pkg['from']} to {$pkg['to']}\n";
        }
        if ($amount === 0) {
            $list = 'No packages were upgraded.';
        }

        // Add composer.json constraint changes
        if (count($this->requireConstraintChanges) > 0) {
            $list .= "\nThe following version constraints in composer.json were changed:\n";
            foreach ($this->requireConstraintChanges as $change) {
                $list .= "* {$change['name']} constraint changed from {$change['from']} to {$change['to']}\n";
            }
        }

        return <<<EOT
# [GitHub Bot]Composer maintenance auto updater

**Ticket:** N/A

## Description
The following automated pull request updates {$amount} package(s).

The following being upgraded:

{$list}

<!-- Add your description here. -->

## Notes
Although automated this branch requires manual testing as it is a feature update.
EOT;
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

    protected function createPullRequest(): void
    {
        $this->info('Pull Request');

        $date = env('APP_SINGLE_BRANCH') ? '' : ' '.today()->toDateString();

        $pullData = [
            'base' => Str::afterLast(env('GITHUB_REF'), '/'),
            'head' => $this->new_branch,
            'title' => env('GIT_COMMIT_PREFIX', '').'Composer update with '
                .(count($this->upgradedPackages)).' changes'
                .$date,
            'body' => $this->formatPullRequestBody(),
        ];

        $createPullRequest = true;

        if (env('APP_SINGLE_BRANCH')) {
            $pullRequests = GitHub::api('pullRequest')->all(
                Str::before($this->repo, '/'),
                Str::afterLast($this->repo, '/'),
                [
                    'head' => Str::before($this->repo, '/').':'.$this->new_branch,
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
