<?php

declare(strict_types=1);

use App\Domains\Platform\Support\Website\RolePages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings the four Solutions pages (clinics, individual doctors, pharmacies & labs, patients) up to the
 * new, detailed versions — but only pages nobody has edited. A page changed in the CMS is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (RolePages::all() as $page) {
            $row = DB::table('cms_pages')->where('slug', $page['slug'])->first();
            if ($row === null) {
                continue; // the website seeder creates missing pages
            }
            $created = strtotime((string) $row->created_at);
            $updated = $row->updated_at === null ? $created : strtotime((string) $row->updated_at);
            if ($created !== false && $updated !== false && $updated - $created > 60) {
                continue; // edited in the CMS: never overwritten
            }
            DB::table('cms_pages')->where('id', $row->id)->update([
                'title' => $page['title'], 'meta_description' => $page['meta_description'],
                'menu_label' => $page['menu_label'], 'menu_order' => $page['menu_order'],
                'sections' => json_encode($page['sections']),
            ]);
        }
    }

    public function down(): void {}
};
