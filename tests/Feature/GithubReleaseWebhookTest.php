<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

const RELEASE_REPO_ID = 987654321;
const RELEASE_REPO_FULL_NAME = 'test-org/test-repo';
const RELEASE_TAG = 'v1.2.3';

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();

    $team = Team::factory()->create();

    openssl_pkey_export(openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]), $pemKey);

    $this->githubApp = GithubApp::create([
        'name' => 'github-app-release',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 1234567890,
        'installation_id' => 67890,
        'webhook_secret' => 'test-secret',
        'private_key_id' => PrivateKey::create([
            'name' => 'GitHub App Key',
            'private_key' => $pemKey,
            'team_id' => $team->id,
        ])->id,
        'is_public' => false,
        'team_id' => $team->id,
    ]);

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $this->createApplication = function (string $name, bool $releaseDeploy, ?string $gitRepository = null) use ($environment, $destination) {
        $application = Application::create([
            'name' => $name,
            'git_repository' => $gitRepository ?? RELEASE_REPO_FULL_NAME,
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'repository_project_id' => RELEASE_REPO_ID,
            'source_id' => $this->githubApp->id,
            'source_type' => GithubApp::class,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
        $application->settings->update(['is_release_deploy_enabled' => $releaseDeploy]);

        return $application->refresh();
    };
});

function sendGithubAppEvent(TestCase $test, string $event, array $payload, ?string $secret = 'test-secret'): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => $event,
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => '1234567890',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? ''),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

function githubReleasePayload(string $action = 'published', array $releaseOverrides = []): array
{
    return [
        'action' => $action,
        'release' => array_merge([
            'id' => 555,
            'tag_name' => RELEASE_TAG,
            'target_commitish' => 'main',
            'draft' => false,
            'prerelease' => false,
            'html_url' => 'https://github.com/'.RELEASE_REPO_FULL_NAME.'/releases/tag/'.RELEASE_TAG,
        ], $releaseOverrides),
        'repository' => ['id' => RELEASE_REPO_ID, 'full_name' => RELEASE_REPO_FULL_NAME],
        'installation' => ['id' => 67890],
    ];
}

function githubPushPayload(): array
{
    return [
        'ref' => 'refs/heads/main',
        'before' => '1111111111111111111111111111111111111111',
        'after' => '2222222222222222222222222222222222222222',
        'repository' => ['id' => RELEASE_REPO_ID, 'full_name' => RELEASE_REPO_FULL_NAME],
        'installation' => ['id' => 67890],
        'commits' => [
            ['message' => 'A change', 'added' => ['app.js'], 'removed' => [], 'modified' => []],
        ],
    ];
}

function sendGithubManualEvent(TestCase $test, string $event, array $payload, string $secret): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
        'HTTP_X-GitHub-Event' => $event,
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '203.0.113.20',
    ], $body);
}

function releaseDeploymentCommits(int $applicationId): array
{
    return ApplicationDeploymentQueue::query()
        ->where('application_id', $applicationId)
        ->pluck('commit')
        ->all();
}

describe('GitHub App release webhook', function () {
    it('queues a deployment of the release tag when a release is published', function () {
        $app = ($this->createApplication)('release-app', true);

        $response = sendGithubAppEvent($this, 'release', githubReleasePayload());

        $response->assertOk();
        expect(releaseDeploymentCommits($app->id))->toBe([RELEASE_TAG]);
        expect($response->json('0.status'))->toBe('queued');
    });

    it('ignores releases for applications without release deploys enabled', function () {
        $app = ($this->createApplication)('push-app', false);

        $response = sendGithubAppEvent($this, 'release', githubReleasePayload());

        $response->assertOk();
        $response->assertSee('No applications found with release deployments enabled');
        expect(releaseDeploymentCommits($app->id))->toBe([]);
    });

    it('ignores pushes for applications with release deploys enabled', function () {
        $releaseApp = ($this->createApplication)('release-app', true);
        $pushApp = ($this->createApplication)('push-app', false);

        $response = sendGithubAppEvent($this, 'push', githubPushPayload());

        $response->assertOk();
        expect(releaseDeploymentCommits($releaseApp->id))->toBe([]);
        expect(releaseDeploymentCommits($pushApp->id))->toBe(['2222222222222222222222222222222222222222']);
        $payloads = collect($response->json());
        expect($payloads->firstWhere('application_uuid', $releaseApp->uuid)['status'])->toBe('skipped');
    });

    it('does not deploy prereleases, drafts or deleted releases', function () {
        $app = ($this->createApplication)('release-app', true);

        sendGithubAppEvent($this, 'release', githubReleasePayload('prereleased', ['prerelease' => true]))->assertOk();
        sendGithubAppEvent($this, 'release', githubReleasePayload('created', ['draft' => true]))->assertOk();
        sendGithubAppEvent($this, 'release', githubReleasePayload('deleted'))->assertOk();

        expect(releaseDeploymentCommits($app->id))->toBe([]);
    });

    it('deploys on the released action as well', function () {
        $app = ($this->createApplication)('release-app', true);

        sendGithubAppEvent($this, 'release', githubReleasePayload('released'))->assertOk();

        expect(releaseDeploymentCommits($app->id))->toBe([RELEASE_TAG]);
    });

    it('rejects a release event with an invalid signature', function () {
        $app = ($this->createApplication)('release-app', true);

        $response = sendGithubAppEvent($this, 'release', githubReleasePayload(), 'wrong-secret');

        $response->assertOk();
        $response->assertSee('Invalid signature');
        expect(releaseDeploymentCommits($app->id))->toBe([]);
    });

    it('does not deploy when the release tag is not a valid git ref', function () {
        $app = ($this->createApplication)('release-app', true);

        $response = sendGithubAppEvent($this, 'release', githubReleasePayload('published', ['tag_name' => '-rf /']));

        $response->assertOk();
        expect(releaseDeploymentCommits($app->id))->toBe([]);
        expect(collect($response->json())->firstWhere('application_uuid', $app->uuid)['status'])->toBe('skipped');
    });

    it('does not deploy when auto deploy is disabled', function () {
        $app = ($this->createApplication)('release-app', true);
        $app->settings->update(['is_auto_deploy_enabled' => false]);

        $response = sendGithubAppEvent($this, 'release', githubReleasePayload());

        $response->assertOk();
        expect(releaseDeploymentCommits($app->id))->toBe([]);
        expect(collect($response->json())->firstWhere('application_uuid', $app->uuid)['status'])->toBe('failed');
    });
});

describe('GitHub manual release webhook', function () {
    it('queues a deployment of the release tag when a release is published', function () {
        $app = ($this->createApplication)('manual-release-app', true, 'https://github.com/'.RELEASE_REPO_FULL_NAME.'.git');

        $payload = githubReleasePayload();
        unset($payload['installation']);
        $response = sendGithubManualEvent($this, 'release', $payload, $app->manual_webhook_secret_github);

        $response->assertOk();
        expect(releaseDeploymentCommits($app->id))->toBe([RELEASE_TAG]);
    });

    it('ignores pushes for applications with release deploys enabled', function () {
        $app = ($this->createApplication)('manual-release-app', true, 'https://github.com/'.RELEASE_REPO_FULL_NAME.'.git');

        $payload = githubPushPayload();
        unset($payload['installation'], $payload['repository']['id']);
        $response = sendGithubManualEvent($this, 'push', $payload, $app->manual_webhook_secret_github);

        $response->assertOk();
        expect(releaseDeploymentCommits($app->id))->toBe([]);
        expect(collect($response->json())->firstWhere('application_uuid', $app->uuid)['status'])->toBe('skipped');
    });

    it('still deploys pushes for applications without release deploys enabled', function () {
        $app = ($this->createApplication)('manual-push-app', false, 'https://github.com/'.RELEASE_REPO_FULL_NAME.'.git');

        $payload = githubPushPayload();
        unset($payload['installation'], $payload['repository']['id']);
        $response = sendGithubManualEvent($this, 'push', $payload, $app->manual_webhook_secret_github);

        $response->assertOk();
        expect(releaseDeploymentCommits($app->id))->toBe(['2222222222222222222222222222222222222222']);
    });

    it('rejects a release event with an invalid signature', function () {
        $app = ($this->createApplication)('manual-release-app', true, 'https://github.com/'.RELEASE_REPO_FULL_NAME.'.git');

        $payload = githubReleasePayload();
        unset($payload['installation']);
        $response = sendGithubManualEvent($this, 'release', $payload, 'wrong-secret');

        $response->assertOk();
        expect(releaseDeploymentCommits($app->id))->toBe([]);
        expect($response->json('0.status'))->toBe('failed');
    });

    it('ignores releases for applications without release deploys enabled', function () {
        $app = ($this->createApplication)('manual-push-app', false, 'https://github.com/'.RELEASE_REPO_FULL_NAME.'.git');

        $payload = githubReleasePayload();
        unset($payload['installation']);
        $response = sendGithubManualEvent($this, 'release', $payload, $app->manual_webhook_secret_github);

        $response->assertOk();
        expect(releaseDeploymentCommits($app->id))->toBe([]);
    });
});

describe('release deploy helpers', function () {
    it('detects GitHub webhook usage from the source or repository host', function () {
        $appSource = ($this->createApplication)('app-source', false);
        expect($appSource->usesGithubWebhooks())->toBeTrue();

        $githubCom = ($this->createApplication)('gh-com', false, 'https://github.com/test-org/test-repo.git');
        $githubCom->source_id = null;
        $githubCom->source_type = null;
        expect($githubCom->usesGithubWebhooks())->toBeTrue();

        $shorthand = ($this->createApplication)('shorthand', false, 'test-org/test-repo');
        $shorthand->source_id = null;
        $shorthand->source_type = null;
        expect($shorthand->usesGithubWebhooks())->toBeTrue();

        $gitlab = ($this->createApplication)('gitlab', false, 'https://gitlab.com/test-org/test-repo.git');
        $gitlab->source_id = null;
        $gitlab->source_type = null;
        expect($gitlab->usesGithubWebhooks())->toBeFalse();
    });

    it('reads the release deploy flag from the settings', function () {
        $app = ($this->createApplication)('flag-app', true);
        expect($app->isReleaseDeployable())->toBeTrue();

        $app->settings->update(['is_release_deploy_enabled' => false]);
        expect($app->refresh()->isReleaseDeployable())->toBeFalse();
    });
});
