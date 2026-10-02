<?php

use Tests\TestCase;

/*
 * All feature, unit and architecture tests run on the Laravel TestCase.
 */
pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Arch');
