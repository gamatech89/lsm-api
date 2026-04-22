<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SiteReviewAnnotationResource;
use App\Http\Resources\SiteReviewResource;
use App\Models\Project;
use App\Models\SiteReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SiteReviewController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $reviews = $project->siteReviews()
            ->with('creator:id,name')
            ->withCount(['annotations as pin_count' => fn($q) => $q->whereNull('parent_id')])
            ->latest()
            ->get();

        return $this->successResponse(SiteReviewResource::collection($reviews));
    }

    public function show(SiteReview $siteReview): JsonResponse
    {
        Gate::authorize('view', $siteReview->project);

        $siteReview->load([
            'creator:id,name',
            'pins.replies.creator:id,name',
            'pins.creator:id,name',
            'pins.resolver:id,name',
        ]);

        return $this->successResponse(new SiteReviewResource($siteReview));
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'url'   => 'required|url|max:2048',
        ]);

        $review = $project->siteReviews()->create([
            ...$validated,
            'created_by' => $request->user()->id,
            'status'     => 'active',
        ]);

        return $this->created(new SiteReviewResource($review), 'Review session created.');
    }

    public function update(Request $request, SiteReview $siteReview): JsonResponse
    {
        Gate::authorize('view', $siteReview->project);

        $validated = $request->validate([
            'title'  => 'sometimes|string|max:255',
            'url'    => 'sometimes|url|max:2048',
            'status' => 'sometimes|in:draft,active,archived',
        ]);

        $siteReview->update($validated);

        return $this->successResponse(new SiteReviewResource($siteReview), 'Review updated.');
    }

    public function destroy(SiteReview $siteReview): JsonResponse
    {
        Gate::authorize('view', $siteReview->project);

        $siteReview->delete();

        return $this->noContentResponse();
    }

    // -------------------------------------------------------------------------
    // Share management
    // -------------------------------------------------------------------------

    public function generateShare(Request $request, SiteReview $siteReview): JsonResponse
    {
        Gate::authorize('view', $siteReview->project);

        $validated = $request->validate([
            'password'   => 'nullable|string|min:4|max:100',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $siteReview->update([
            'share_token'      => Str::random(40),
            'share_password'   => $validated['password'] ?? null,
            'share_expires_at' => $validated['expires_at'] ?? null,
        ]);

        return $this->successResponse([
            'share_url'      => config('app.frontend_url') . '/review/share/' . $siteReview->share_token,
            'has_password'   => ! empty($siteReview->share_password),
            'expires_at'     => $siteReview->share_expires_at,
        ], 'Share link generated.');
    }

    public function revokeShare(SiteReview $siteReview): JsonResponse
    {
        Gate::authorize('view', $siteReview->project);

        $siteReview->update([
            'share_token'      => null,
            'share_password'   => null,
            'share_expires_at' => null,
        ]);

        return $this->successResponse(null, 'Share link revoked.');
    }

    // -------------------------------------------------------------------------
    // Public share endpoints (no auth)
    // -------------------------------------------------------------------------

    public function showShare(string $token): JsonResponse
    {
        $review = SiteReview::where('share_token', $token)->first();

        if (! $review || ! $review->isShareValid()) {
            return $this->notFound('This review link is invalid or has expired.');
        }

        return $this->successResponse([
            'title'        => $review->title,
            'url'          => $review->url,
            'has_password' => ! empty($review->share_password),
            'expires_at'   => $review->share_expires_at,
        ]);
    }

    public function accessShare(Request $request, string $token): JsonResponse
    {
        $review = SiteReview::where('share_token', $token)->first();

        if (! $review || ! $review->isShareValid()) {
            return $this->notFound('This review link is invalid or has expired.');
        }

        if (! $review->isSharePasswordCorrect($request->input('password'))) {
            return $this->forbiddenResponse('Incorrect password.');
        }

        $review->load([
            'pins.replies.creator:id,name',
            'pins.creator:id,name',
            'pins.resolver:id,name',
        ]);

        return $this->successResponse([
            'id'    => $review->id,
            'title' => $review->title,
            'url'   => $review->url,
            'pins'  => SiteReviewAnnotationResource::collection($review->pins),
        ]);
    }
}
