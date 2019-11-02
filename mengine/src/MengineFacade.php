<?php

namespace StingBo\Mengine;

use Illuminate\Support\Facades\Facade;

class Mengine extends Facade
{
    protected static function getFacadeAccessor()
    {
        return Mengine::class;
    }
}
