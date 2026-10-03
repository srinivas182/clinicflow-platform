<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the platform home on a central domain', function (): void {
    $this->withoutVite()
        ->get('http://localhost/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Welcome')
            ->where('region', 'af-south-1')
            ->where('app.name', config('app.name'))
            ->where('provider', null));
});
