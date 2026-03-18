<?php

use App\Http\Requests\Api\StoreProjectRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time migration to normalize all existing project URLs in the DB
 * so they are consistent with the normalizeUrl logic used on form submissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $projects = DB::table('projects')->whereNotNull('url')->where('url', '!=', '')->get(['id', 'url']);

        foreach ($projects as $project) {
            $normalized = StoreProjectRequest::normalizeUrl($project->url);
            if ($normalized !== $project->url) {
                DB::table('projects')->where('id', $project->id)->update(['url' => $normalized]);
            }
        }
    }

    public function down(): void
    {
        // Cannot reverse URL normalization
    }
};
