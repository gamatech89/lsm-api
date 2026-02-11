<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->text('notes')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->boolean('is_quick_action')->default(false);
        });

        // Migrate existing project links to resources
        $projects = DB::table('projects')->get();

        foreach ($projects as $project) {
            $linksToMigrate = [
                [
                    'key' => 'drive_link',
                    'title' => 'Google Drive',
                    'type' => 'link',
                    'notes' => null,
                ],
                [
                    'key' => 'trello_link',
                    'title' => 'Trello',
                    'type' => 'link',
                    'notes' => null,
                ],
                [
                    'key' => 'hosting_url',
                    'title' => 'Hosting Panel',
                    'type' => 'link',
                    'notes' => $project->hosting_provider ?? null,
                ],
            ];

            foreach ($linksToMigrate as $item) {
                if (!empty($project->{$item['key']})) {
                    DB::table('resources')->insert([
                        'project_id' => $project->id,
                        'title' => $item['title'],
                        'type' => 'link',
                        'url' => $project->{$item['key']},
                        'is_quick_action' => true,
                        'notes' => $item['notes'],
                        'file_path' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn(['notes', 'file_name', 'file_size', 'is_quick_action']);
        });
    }
};
