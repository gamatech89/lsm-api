<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Tag Controller
 * 
 * Manages project tags for organization and filtering.
 */
class TagController extends Controller
{
    /**
     * Display a listing of tags.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index()
    {
        $tags = Tag::withCount('projects')->orderBy('name')->get();

        return TagResource::collection($tags);
    }

    /**
     * Display the specified tag.
     *
     * @param Tag $tag
     * @return TagResource
     */
    public function show(Tag $tag): TagResource
    {
        $tag->loadCount('projects');

        return new TagResource($tag);
    }

    /**
     * Store a newly created tag.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50|unique:tags,name',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $tag = Tag::create($validated);

        return $this->createdResponse(
            new TagResource($tag),
            'Tag created successfully'
        );
    }

    /**
     * Update the specified tag.
     *
     * @param Request $request
     * @param Tag $tag
     * @return TagResource
     */
    public function update(Request $request, Tag $tag): TagResource
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:50|unique:tags,name,' . $tag->id,
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $tag->update($validated);

        return new TagResource($tag);
    }

    /**
     * Remove the specified tag.
     *
     * @param Tag $tag
     * @return JsonResponse
     */
    public function destroy(Tag $tag): JsonResponse
    {
        // Detach from all projects first
        $tag->projects()->detach();
        $tag->delete();

        return $this->successResponse(null, 'Tag deleted successfully');
    }
}
