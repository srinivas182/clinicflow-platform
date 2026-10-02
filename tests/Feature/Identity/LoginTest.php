<?php

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Models\LoginChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['email' => 'nomvula@sunrise.test', 'phone' => '0821234567']);
});

function sentCode(User $user): string
{
    return app(OtpSender::class)->sent[$user->id];
}

it('signs in with password and a one-time code', function (): void {
    $this->post('http://localhost/login', ['login' => 'nomvula@sunrise.test', 'password' => 'password'])
        ->assertRedirect('/login/verify');

    $this->assertGuest();

    $this->post('http://localhost/login/verify', ['code' => sentCode($this->user)])
        ->assertRedirect('/workspaces');

    $this->assertAuthenticatedAs($this->user);
    expect($this->user->fresh()->last_login_at)->not->toBeNull();
});

it('accepts the cell number as the login', function (): void {
    $this->post('http://localhost/login', ['login' => '0821234567', 'password' => 'password'])
        ->assertRedirect('/login/verify');
});

it('rejects a wrong password without revealing which field was wrong', function (): void {
    $this->post('http://localhost/login', ['login' => 'nomvula@sunrise.test', 'password' => 'wrong'])
        ->assertSessionHasErrors(['login' => 'These details do not match our records.']);

    expect(LoginChallenge::count())->toBe(0);
});

it('closes the code after five wrong attempts', function (): void {
    $this->post('http://localhost/login', ['login' => 'nomvula@sunrise.test', 'password' => 'password']);
    $right = sentCode($this->user);

    foreach (range(1, 5) as $attempt) {
        $this->post('http://localhost/login/verify', ['code' => '000000'])->assertSessionHasErrors('code');
    }

    $this->post('http://localhost/login/verify', ['code' => $right])
        ->assertSessionHasErrors(['code' => 'This code has expired. Please sign in again.']);
    $this->assertGuest();
});

it('rejects an expired code', function (): void {
    $this->post('http://localhost/login', ['login' => 'nomvula@sunrise.test', 'password' => 'password']);
    $code = sentCode($this->user);

    $this->travel(6)->minutes();

    $this->post('http://localhost/login/verify', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('rate limits password attempts', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->post('http://localhost/login', ['login' => 'nomvula@sunrise.test', 'password' => 'wrong']);
    }

    $this->post('http://localhost/login', ['login' => 'nomvula@sunrise.test', 'password' => 'wrong'])->assertTooManyRequests();
});

it('sends guests to the sign-in page', function (): void {
    $this->get('http://localhost/workspaces')->assertRedirect('http://localhost/login');
});
