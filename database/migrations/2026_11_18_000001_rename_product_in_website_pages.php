<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The product is now called Dr Business Flow: rename it in the platform website's pages. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('cms_pages')->get(['id', 'title', 'meta_description', 'body', 'sections']) as $page) {
            $changes = [];
            foreach (['title', 'meta_description', 'body', 'sections'] as $field) {
                $value = $page->{$field};
                if (is_string($value) && str_contains($value, 'Clinic Flow')) {
                    $changes[$field] = str_replace('Clinic Flow', 'Dr Business Flow', $value);
                }
            }
            if ($changes !== []) {
                DB::table('cms_pages')->where('id', $page->id)->update($changes);
            }
        }
    }

    public function down(): void {}
};
