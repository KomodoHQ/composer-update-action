# composer update action

![update](https://github.com/kawax/composer-update-action/workflows/composer%20update/badge.svg)
![test](https://github.com/kawax/composer-update-action/workflows/test/badge.svg)
[![Maintainability](https://api.codeclimate.com/v1/badges/7a806f8e8f06017b9caf/maintainability)](https://codeclimate.com/github/kawax/composer-update-action/maintainability)
[![Test Coverage](https://api.codeclimate.com/v1/badges/7a806f8e8f06017b9caf/test_coverage)](https://codeclimate.com/github/kawax/composer-update-action/test_coverage)

`composer update` and create pull request.

## Reusable workflow version
https://github.com/kawax/composer-workflow

## Version
| Git branch | PHP    |
|------------|--------|
| 7.3        | 7.3    |
| 7.4        | 7.4    |
| 8.0        | 8.0    |
| 8.1        | 8.1    |
| 8.2        | 8.2    |
| 8.3        | 8.3    |
| master     | latest |

> **Note:** Currently only PHP 7.3, 7.4, 8.0, 8.1, 8.2, 8.3 are supported with Composer version 2.

## Usage

Create `.github/workflows/update.yml`

```yaml
name: composer update

on:
  schedule:
    - cron: '0 0 * * *' #UTC

jobs:
  composer_update_job:
    runs-on: ubuntu-latest
    name: composer update
    steps:
      - name: Checkout
        uses: actions/checkout@v3
      - name: composer update action
        uses: kawax/composer-update-action@master
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

## env
- COMPOSER_PATH : Specify if using subdirectory. Where composer.json is located.
- GIT_NAME : git user name
- GIT_EMAIL : git email
- APP_SINGLE_BRANCH : If set, the new functionality is enabled.
- APP_SINGLE_BRANCH_POSTFIX : A postfix for the branch used for updates. Default value is "-updated". If the branch doesn't exist, a new branch will be created with the parent branch name plus the postfix, e.g. "master-updated".
- APP_USE_MAINTENANCE_BRANCH_CONVENTION : Branches from your defined parent branch using a specific naming convention will be ignored. Naming convention is "maintenance/month-year", this overrides the single branch settings.
- GIT_COMMIT_PREFIX : Add a prefix to the commit message and pull request title. E.g. "[UPDATE] "
- COMPOSER_PACKAGES : Specify which packages should be updated. E.g. "typo3/cms-*". Setting this variable will also run Composer with the `--with-dependencies` argument.

Upgrade task
```yaml
      - name: composer update action
        uses: kawax/composer-update-action@master
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          COMPOSER_PATH: /subdir
          GIT_NAME: cu
          GIT_EMAIL: cu@composer-update
          APP_SINGLE_BRANCH: 1
          APP_SINGLE_BRANCH_POSTFIX: -updated
          APP_USE_MAINTENANCE_BRANCH_CONVENTION: 1
          GIT_COMMIT_PREFIX: '[UPDATE] '
          COMPOSER_PACKAGES: 'typo3/cms-*'
```

### Upgrade Action

This task requires a `composer_update_allowlist.txt` to exist in the composer path and a compatible set of packages
with the action's PHP version

The value of the file need to be new line separated as seen:

e.g.
```text
composer/installers
vlucas/phpdotenv
oscarotero/env
nesbot/carbon
```

The action will then update the packages in the `composer_update_allowlist.txt` file and create a PR with the changes.

```yaml
name: composer update

# Allows manual workflow dispatch and runs on the 24th of every month
on:
    workflow_dispatch:
    schedule:
        - cron: '0 0 24 * *'

jobs:
    composer_update_job:
        runs-on: ubuntu-latest
        name: Composer Update
        permissions:
            contents: write
            pull-requests: write
        steps:
            - name: Checkout
              uses: actions/checkout@v3
            - name: Composer Update Action
              uses: KomodoHQ/composer-update-action@master
              env:
                GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
                COMPOSER_PATH: '/site' // e.g. /site, /;
                GIT_NAME: cu
                GIT_EMAIL: cu@composer-update
                APP_USE_MAINTENANCE_BRANCH_CONVENTION: 1 // e.g. maintenance/may-2024
                GIT_COMMIT_PREFIX: '[UPDATE] '
```

To avoid any permissions issues, it is important to allow contents and pull-requests permissions for the GITHUB_TOKEN. This can be done by adding the following to your workflow file:
```yaml
        composer_update_job:
        runs-on: ubuntu-latest
        name: composer update
        permissions:
            contents: write
            pull-requests: write
```

## Troubleshooting

### Missing PHP extension

```
foo/bar 1.0.0 requires ext-XXX * -> the requested PHP extension XXX is missing from your system.
```

Configure `platform` in your composer.json.

```json
  "config": {
    "platform": {
      "php": "7.2.0", 
      "ext-XXX": "1.0.0"
     }
  },
```

## LICENCE
MIT
