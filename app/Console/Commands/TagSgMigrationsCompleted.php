<?php

namespace App\Console\Commands;

use App\Models\Tag;
use Illuminate\Console\Command;

class TagSgMigrationsCompleted extends Command
{
    protected $signature = 'migrations:tag-completed {--dry-run : Preview without saving}';

    protected $description = 'Add "Completed" tag to all projects tagged with sg-migrate';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $sgMigrateTag = Tag::where('slug', 'sg-migrate')->first();
        if (!$sgMigrateTag) {
            $this->error('Tag "sg-migrate" not found.');
            return 1;
        }

        $projects = $sgMigrateTag->projects()->with('tags')->get();
        $this->info("Found {$projects->count()} projects with sg-migrate tag.");

        $completedTag = Tag::where('slug', 'completed')->first();

        if (!$completedTag) {
            $this->line('  "completed" tag does not exist — will create it (color: #22c55e)');
            if (!$dryRun) {
                $completedTag = Tag::create([
                    'name'  => 'completed',
                    'slug'  => 'completed',
                    'color' => '#22c55e',
                ]);
            }
        } else {
            $this->line("  Found existing tag: \"{$completedTag->name}\" (#{$completedTag->id}, {$completedTag->color})");
        }

        $attached = 0;
        $skipped  = 0;

        foreach ($projects as $project) {
            $alreadyTagged = $project->tags->contains('slug', 'completed');

            if ($alreadyTagged) {
                $this->line("  SKIP (already tagged): {$project->name}");
                $skipped++;
                continue;
            }

            $this->line("  Tag: {$project->name}");
            if (!$dryRun) {
                $project->tags()->syncWithoutDetaching([$completedTag->id]);
            }
            $attached++;
        }

        if ($dryRun) {
            $this->warn('Dry run — no changes saved.');
        } else {
            $this->info('Done.');
        }

        $this->newLine();
        $this->table(['Action', 'Count'], [
            ['Tagged',  $attached],
            ['Skipped (already had tag)', $skipped],
        ]);

        return 0;
    }
}
