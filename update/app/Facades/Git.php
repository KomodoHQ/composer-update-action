<?php

namespace App\Facades;

use CzProject\GitPhp\GitRepository;
use Illuminate\Support\Facades\Facade;

class Git extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'git';
    }
}
