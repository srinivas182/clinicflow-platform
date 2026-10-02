<?php

/*
 * Architecture rules enforced on every pull request.
 */

arch('no debugging statements are committed')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('domain code declares strict types')
    ->expect('App\Domains')
    ->toUseStrictTypes();

arch('enums live in an Enums namespace')
    ->expect('App\Domains\Platform\Enums')
    ->toBeEnums();

arch('controllers do not reach into the database facade directly')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');
