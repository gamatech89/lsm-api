<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreIntegrationTokenRequest;
use App\Http\Resources\IntegrationTokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Long-lived, scoped bearer tokens for external integrations (MCP clients).
 *
 * Every query filters on type = 'integration' and the caller's own id, so a
 * user can neither reach another user's tokens nor revoke their own login.
 */
class IntegrationTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            IntegrationTokenResource::collection($request->user()->integrationTokens()->get())
        );
    }

    public function store(StoreIntegrationTokenRequest $request): JsonResponse
    {
        $user = $request->user();

        // createToken() inserts with the column default type = 'session', and
        // only the forceFill below flips it to 'integration'. Without a
        // transaction, a failure between the two writes would leave an orphan
        // session-typed row carrying MCP abilities: hidden from index() and
        // destroy() (both filter on type), yet still session-shaped enough for
        // refresh() to accept.
        $token = DB::transaction(function () use ($user, $request) {
            $token = $user->createToken(
                $request->input('name'),
                $request->scopes(),
                $request->expiresAt()
            );

            $token->accessToken->forceFill([
                'type' => 'integration',
                'created_from_ip' => $request->ip(),
            ])->save();

            return $token;
        });

        // The only place a plaintext token is ever returned.
        return $this->createdResponse([
            'token' => $token->plainTextToken,
            'integration_token' => new IntegrationTokenResource($token->accessToken->fresh()),
        ], 'Integration token created. Copy it now — it will not be shown again.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = $request->user()->integrationTokens()->find($id);

        // 404 rather than 403: a stranger learns nothing about whether the id exists.
        if (! $token) {
            return $this->notFoundResponse('Integration token not found.');
        }

        $token->delete();

        return $this->successResponse(null, 'Integration token revoked.');
    }
}
