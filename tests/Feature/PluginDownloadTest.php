<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * "Download Plugin" on the Connect WordPress card serves the zip attached to
 * the latest lsm-wp GitHub release: the same artifact the fleet auto-updates
 * from, so the button can't drift from what is actually released. When GitHub
 * can't provide it the endpoint answers 502 with a message the SPA can show.
 */
const PLUGIN_DOWNLOAD_RELEASE_API = 'https://api.github.com/repos/gamatech89/lsm-wp/releases/latest';
const PLUGIN_DOWNLOAD_ZIP_URL = 'https://github.com/gamatech89/lsm-wp/releases/download/v9.9.9/landeseiten-maintenance.zip';
const PLUGIN_DOWNLOAD_ZIP_BYTES = "PK\x03\x04fake-zip-contents";

function pluginDownloadRelease(?array $assets = null): array
{
    return [
        'tag_name' => 'v9.9.9',
        'name' => 'Version 9.9.9',
        'body' => 'Release notes',
        'published_at' => '2026-11-01T10:00:00Z',
        'zipball_url' => 'https://api.github.com/repos/gamatech89/lsm-wp/zipball/v9.9.9',
        'author' => ['login' => 'not-for-the-updater'],
        // The plugin zip is deliberately not first: it must be picked by name.
        'assets' => $assets ?? [
            ['name' => 'checksums.txt', 'browser_download_url' => 'https://github.com/gamatech89/lsm-wp/releases/download/v9.9.9/checksums.txt', 'size' => 10],
            ['name' => 'landeseiten-maintenance.zip', 'browser_download_url' => PLUGIN_DOWNLOAD_ZIP_URL, 'size' => 20],
        ],
    ];
}

function pluginDownloadUrl(Project $project): string
{
    return "/api/v1/projects/{$project->id}/lsm/download-plugin";
}

/**
 * @return array{0: User, 1: Project}
 */
function pluginDownloadAssignedDeveloper(): array
{
    $developer = User::factory()->create(['role' => 'developer']);

    return [$developer, Project::factory()->create(['developer_id' => $developer->id])];
}

beforeEach(fn () => Http::preventStrayRequests());

test('an assigned developer downloads the zip of the latest GitHub release', function () {
    Http::fake([
        PLUGIN_DOWNLOAD_RELEASE_API => Http::response(pluginDownloadRelease()),
        PLUGIN_DOWNLOAD_ZIP_URL => Http::response(PLUGIN_DOWNLOAD_ZIP_BYTES),
    ]);
    [$developer, $project] = pluginDownloadAssignedDeveloper();

    $response = $this->actingAs($developer)->get(pluginDownloadUrl($project));

    $response->assertOk();
    expect($response->getContent())->toBe(PLUGIN_DOWNLOAD_ZIP_BYTES);
    expect($response->headers->get('Content-Type'))->toBe('application/zip');
    expect($response->headers->get('Content-Disposition'))
        ->toBe('attachment; filename="landeseiten-maintenance.zip"');
});

test('a developer not assigned to the project gets 403 and GitHub is not contacted', function () {
    Http::fake();
    $outsider = User::factory()->create(['role' => 'developer']);
    $project = Project::factory()->create();

    $this->actingAs($outsider)->get(pluginDownloadUrl($project))->assertForbidden();

    Http::assertNothingSent();
});

test('it answers 502 with a readable message when GitHub cannot provide the zip', function (array $fakes) {
    Http::fake($fakes);
    [$developer, $project] = pluginDownloadAssignedDeveloper();

    $this->actingAs($developer)->getJson(pluginDownloadUrl($project))
        ->assertStatus(502)
        ->assertExactJson(['message' => 'Could not fetch the plugin from GitHub. Please try again in a moment.']);
})->with([
    'the release lookup fails' => [fn () => [
        PLUGIN_DOWNLOAD_RELEASE_API => Http::response(['message' => 'Not Found'], 404),
    ]],
    'the release has no plugin zip attached' => [fn () => [
        PLUGIN_DOWNLOAD_RELEASE_API => Http::response(pluginDownloadRelease([
            ['name' => 'checksums.txt', 'browser_download_url' => 'https://github.com/gamatech89/lsm-wp/releases/download/v9.9.9/checksums.txt'],
        ])),
    ]],
    'the zip download fails' => [fn () => [
        PLUGIN_DOWNLOAD_RELEASE_API => Http::response(pluginDownloadRelease()),
        PLUGIN_DOWNLOAD_ZIP_URL => Http::response('Bad Gateway', 502),
    ]],
    'the download is not a zip' => [fn () => [
        PLUGIN_DOWNLOAD_RELEASE_API => Http::response(pluginDownloadRelease()),
        PLUGIN_DOWNLOAD_ZIP_URL => Http::response('<html>Rate limited</html>'),
    ]],
    'GitHub is unreachable' => [fn () => [
        PLUGIN_DOWNLOAD_RELEASE_API => fn () => throw new ConnectionException('cURL error 28: timed out'),
    ]],
]);

test('a failed release lookup is not cached, so the next click retries', function () {
    Http::fake([
        PLUGIN_DOWNLOAD_RELEASE_API => Http::sequence()
            ->push(['message' => 'Server Error'], 500)
            ->push(pluginDownloadRelease()),
        PLUGIN_DOWNLOAD_ZIP_URL => Http::response(PLUGIN_DOWNLOAD_ZIP_BYTES),
    ]);
    [$developer, $project] = pluginDownloadAssignedDeveloper();

    $this->actingAs($developer)->getJson(pluginDownloadUrl($project))->assertStatus(502);
    $this->actingAs($developer)->get(pluginDownloadUrl($project))->assertOk();
});

test('the public latest-release endpoint keeps its response shape for the plugin updater', function () {
    Http::fake([PLUGIN_DOWNLOAD_RELEASE_API => Http::response(pluginDownloadRelease())]);

    $this->getJson('/api/v1/plugin/latest-release')
        ->assertOk()
        ->assertExactJson([
            'tag_name' => 'v9.9.9',
            'name' => 'Version 9.9.9',
            'body' => 'Release notes',
            'published_at' => '2026-11-01T10:00:00Z',
            'zipball_url' => 'https://api.github.com/repos/gamatech89/lsm-wp/zipball/v9.9.9',
            'assets' => [
                ['name' => 'checksums.txt', 'browser_download_url' => 'https://github.com/gamatech89/lsm-wp/releases/download/v9.9.9/checksums.txt'],
                ['name' => 'landeseiten-maintenance.zip', 'browser_download_url' => PLUGIN_DOWNLOAD_ZIP_URL],
            ],
        ]);
});

test('the public latest-release endpoint answers 404 when GitHub has no release', function () {
    Http::fake([PLUGIN_DOWNLOAD_RELEASE_API => Http::response(['message' => 'Not Found'], 404)]);

    $this->getJson('/api/v1/plugin/latest-release')
        ->assertNotFound()
        ->assertExactJson(['error' => 'No release found']);
});
