<?php

use Illuminate\Support\Facades\Validator;

it('explains validation problems in plain language, saying what to do', function (): void {
    $v = Validator::make(
        ['cell' => '', 'email' => 'not-an-email', 'popia_consent' => false, 'password' => 'abc', 'password_confirmation' => 'abd', 'guardian_cell' => ''],
        ['cell' => 'required', 'email' => 'email', 'popia_consent' => 'accepted', 'password' => 'confirmed', 'guardian_cell' => 'required'],
    );
    expect($v->errors()->all())->toBe([
        'Please fill in the mobile number.',
        'Please enter a valid email address, like name@example.com.',
        'Please tick the privacy consent box to continue.',
        'The password and the repeated entry do not match. Please type both again.',
        "Please fill in the guardian's mobile number.",
    ]);
});

it('keeps standard messages readable for rules without a plain-language version', function (): void {
    $message = Validator::make(['cell' => 'ab'], ['cell' => 'size:3'])->errors()->first('cell');
    expect($message)->toContain('mobile number')->not->toContain('the the');
});
