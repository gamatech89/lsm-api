<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SiteReviewAnnotationResource;
use App\Models\SiteReview;
use App\Models\SiteReviewAnnotation;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class SiteReviewAnnotationController extends Controller
{
    // -------------------------------------------------------------------------
    // Protected (authenticated) endpoints
    // -------------------------------------------------------------------------

    public function store(Request $request, SiteReview $siteReview): JsonResponse
    {
        Gate::authorize('view', $siteReview->project);

        $validated = $request->validate([
            'parent_id'   => 'nullable|exists:site_review_annotations,id',
            'x_percent'   => 'required_without:parent_id|nullable|numeric|between:0,100',
            'y_percent'   => 'required_without:parent_id|nullable|numeric|between:0,100',
            'scroll_y'    => 'nullable|numeric|min:0',
            'comment'     => 'required|string|max:5000',
            'create_todo' => 'boolean',
        ]);

        $annotation = $siteReview->annotations()->create([
            'parent_id'   => $validated['parent_id'] ?? null,
            'created_by'  => $request->user()->id,
            'author_type' => 'internal',
            'x_percent'   => $validated['x_percent'] ?? null,
            'y_percent'   => $validated['y_percent'] ?? null,
            'scroll_y'    => $validated['scroll_y'] ?? null,
            'comment'     => $validated['comment'],
        ]);

        if (($validated['create_todo'] ?? false) && $annotation->isPin()) {
            $todo = $this->createTodoFromAnnotation($annotation, $siteReview);
            $annotation->update(['todo_id' => $todo->id]);
        }

        $annotation->load(['creator:id,name', 'replies']);

        return $this->created(new SiteReviewAnnotationResource($annotation), 'Annotation added.');
    }

    public function update(Request $request, SiteReviewAnnotation $siteReviewAnnotation): JsonResponse
    {
        Gate::authorize('view', $siteReviewAnnotation->review->project);

        $validated = $request->validate([
            'comment' => 'required|string|max:5000',
        ]);

        // Only the author can edit their own comment
        if ($siteReviewAnnotation->created_by !== $request->user()->id) {
            return $this->forbiddenResponse('You can only edit your own comments.');
        }

        $siteReviewAnnotation->update($validated);

        return $this->successResponse(new SiteReviewAnnotationResource($siteReviewAnnotation));
    }

    public function destroy(Request $request, SiteReviewAnnotation $siteReviewAnnotation): JsonResponse
    {
        Gate::authorize('view', $siteReviewAnnotation->review->project);

        $siteReviewAnnotation->delete();

        return $this->noContentResponse();
    }

    public function resolve(Request $request, SiteReviewAnnotation $siteReviewAnnotation): JsonResponse
    {
        Gate::authorize('view', $siteReviewAnnotation->review->project);

        if ($siteReviewAnnotation->isResolved()) {
            $siteReviewAnnotation->update(['resolved_at' => null, 'resolved_by' => null]);
        } else {
            $siteReviewAnnotation->update([
                'resolved_at' => now(),
                'resolved_by' => $request->user()->id,
            ]);
        }

        return $this->successResponse(new SiteReviewAnnotationResource($siteReviewAnnotation));
    }

    public function createTodo(Request $request, SiteReviewAnnotation $siteReviewAnnotation): JsonResponse
    {
        Gate::authorize('view', $siteReviewAnnotation->review->project);

        if ($siteReviewAnnotation->todo_id) {
            return $this->errorResponse('A Todo already exists for this annotation.');
        }

        $validated = $request->validate([
            'title'    => 'nullable|string|max:255',
            'priority' => 'nullable|in:low,medium,high,critical',
        ]);

        $review = $siteReviewAnnotation->review;
        $title  = $validated['title'] ?? mb_substr($siteReviewAnnotation->comment, 0, 80) . (mb_strlen($siteReviewAnnotation->comment) > 80 ? '...' : '');

        $frontendUrl = env('FRONTEND_URL', config('app.url'));
        $projectUrl = rtrim($frontendUrl, '/') . '/projects/' . $review->project_id;
        
        $todo = Todo::create([
            'project_id'  => $review->project_id,
            'title'       => $title,
            'description' => $siteReviewAnnotation->comment . "\n\n--- Resource Links ---\n" . $projectUrl,
            'status'      => 'pending',
            'priority'    => $validated['priority'] ?? 'medium',
        ]);

        // Attach screenshot if provided
        if ($request->hasFile('screenshot')) {
            $path = $request->file('screenshot')->store(
                'site-reviews/' . $review->id . '/todos',
                'public'
            );
            \App\Models\TodoAttachment::create([
                'todo_id'       => $todo->id,
                'file_path'     => $path,
                'file_name'     => 'review-screenshot.png',
                'file_size'     => $request->file('screenshot')->getSize(),
                'mime_type'     => 'image/png',
                'uploaded_by'   => $request->user()->id,
            ]);
            $siteReviewAnnotation->update(['screenshot_path' => $path]);
        }

        $siteReviewAnnotation->update(['todo_id' => $todo->id]);

        return $this->createdResponse([
            'todo_id' => $todo->id,
            'todo_title' => $todo->title,
        ], 'Todo created.');
    }

    public function screenshot(Request $request, SiteReviewAnnotation $siteReviewAnnotation): JsonResponse
    {
        Gate::authorize('view', $siteReviewAnnotation->review->project);

        $request->validate([
            'screenshot' => 'required|image|max:20480',
        ]);

        if ($siteReviewAnnotation->screenshot_path) {
            Storage::disk('public')->delete($siteReviewAnnotation->screenshot_path);
        }

        $path = $request->file('screenshot')->store(
            'site-reviews/' . $siteReviewAnnotation->review_id . '/annotations',
            'public'
        );

        $siteReviewAnnotation->update(['screenshot_path' => $path]);

        // If a Todo is associated with this annotation, attach the screenshot to the Todo as well
        if ($siteReviewAnnotation->todo_id) {
            \App\Models\TodoAttachment::create([
                'todo_id'       => $siteReviewAnnotation->todo_id,
                'file_path'     => $path,
                'file_name'     => 'review-screenshot.png',
                'file_size'     => $request->file('screenshot')->getSize(),
                'mime_type'     => 'image/png',
                'uploaded_by'   => $request->user() ? $request->user()->id : null,
            ]);
        }

        return $this->successResponse([
            'screenshot_url' => asset('storage/' . $path),
        ]);
    }

    // -------------------------------------------------------------------------
    // Public share endpoints (no auth)
    // -------------------------------------------------------------------------

    public function storeShare(Request $request, string $token): JsonResponse
    {
        $review = SiteReview::where('share_token', $token)->first();

        if (! $review || ! $review->isShareValid()) {
            return $this->notFound('Review link is invalid or has expired.');
        }

        if (! $review->isSharePasswordCorrect($request->input('password'))) {
            return $this->forbiddenResponse('Incorrect password.');
        }

        $validated = $request->validate([
            'parent_id'    => 'nullable|exists:site_review_annotations,id',
            'x_percent'    => 'required_without:parent_id|nullable|numeric|between:0,100',
            'y_percent'    => 'required_without:parent_id|nullable|numeric|between:0,100',
            'scroll_y'     => 'nullable|numeric|min:0',
            'comment'      => 'required|string|max:5000',
            'author_name'  => 'required|string|max:100',
            'author_email' => 'nullable|email|max:255',
        ]);

        $annotation = $review->annotations()->create([
            'parent_id'    => $validated['parent_id'] ?? null,
            'author_type'  => 'client',
            'author_name'  => $validated['author_name'],
            'author_email' => $validated['author_email'] ?? null,
            'x_percent'    => $validated['x_percent'] ?? null,
            'y_percent'    => $validated['y_percent'] ?? null,
            'scroll_y'     => $validated['scroll_y'] ?? null,
            'comment'      => $validated['comment'],
        ]);

        $annotation->load('replies');

        return $this->created(new SiteReviewAnnotationResource($annotation), 'Comment added.');
    }

    public function screenshotShare(Request $request, string $token, SiteReviewAnnotation $siteReviewAnnotation): JsonResponse
    {
        $review = SiteReview::where('share_token', $token)->first();

        if (! $review || ! $review->isShareValid()) {
            return $this->notFound('Review link is invalid or has expired.');
        }

        if (! $review->isSharePasswordCorrect($request->input('password'))) {
            return $this->forbiddenResponse('Incorrect password.');
        }

        $request->validate([
            'screenshot' => 'required|image|max:20480',
        ]);

        if ($siteReviewAnnotation->screenshot_path) {
            Storage::disk('public')->delete($siteReviewAnnotation->screenshot_path);
        }

        $path = $request->file('screenshot')->store(
            'site-reviews/' . $siteReviewAnnotation->review_id . '/annotations',
            'public'
        );

        $siteReviewAnnotation->update(['screenshot_path' => $path]);

        // If a Todo is associated with this annotation, attach the screenshot to the Todo as well
        if ($siteReviewAnnotation->todo_id) {
            \App\Models\TodoAttachment::create([
                'todo_id'       => $siteReviewAnnotation->todo_id,
                'file_path'     => $path,
                'file_name'     => 'review-screenshot.png',
                'file_size'     => $request->file('screenshot')->getSize(),
                'mime_type'     => 'image/png',
                'uploaded_by'   => null, // public shares have no logged-in user
            ]);
        }

        return $this->successResponse([
            'screenshot_url' => asset('storage/' . $path),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createTodoFromAnnotation(SiteReviewAnnotation $annotation, SiteReview $review): Todo
    {
        $title = mb_substr($annotation->comment, 0, 80);
        if (mb_strlen($annotation->comment) > 80) {
            $title .= '...';
        }

        $frontendUrl = env('FRONTEND_URL', config('app.url'));
        $projectUrl = rtrim($frontendUrl, '/') . '/projects/' . $review->project_id;
        
        return Todo::create([
            'project_id'  => $review->project_id,
            'title'       => $title,
            'description' => $annotation->comment . "\n\n--- Resource Links ---\n" . $projectUrl,
            'status'      => 'pending',
            'priority'    => 'medium',
        ]);
    }
}
