<?php

namespace App\Mcp\Tools;

use App\Models\Tag;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListTagsTool extends Tool
{
    protected string $name = 'list-tags';

    protected string $description = <<<'MARKDOWN'
        List all available tags. Tags can be used to categorize projects.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $tags = Tag::withCount('projects')
            ->orderBy('name')
            ->get();

        if ($tags->isEmpty()) {
            return Response::text("No tags found in the system.");
        }

        $lines = ["**🏷️ Tags ({$tags->count()})**\n"];

        foreach ($tags as $tag) {
            $color = $tag->color ? " ({$tag->color})" : '';
            $lines[] = "• **{$tag->name}**{$color} — {$tag->projects_count} projects";
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
