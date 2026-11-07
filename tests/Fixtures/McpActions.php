<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Tests\Fixtures;

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionHandler;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionPolicy;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Actions\ScopeRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\ServiceProvider;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

final class McpFixtureProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(ScopeRegistry::class)->register('fixture/telemetry', 'fixture:edit', 'Edit a draft');
        $this->app->make(CapabilityRegistry::class)->register(new ActionDefinition(
            'fixture/telemetry', 'edit', 1, 'Edit a draft title.', ActionEffect::Edit, ['fixture:edit'],
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'title' => ['type' => 'string']],
                'required' => ['id', 'title'], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id'], 'additionalProperties' => false],
            McpFixtureHandler::class, McpFixturePolicy::class,
        ));
    }
}

final class McpFixtureHandler implements ActionHandler
{
    public function execute(array $input, ExecutionContext $context): array
    {
        $entry = Entry::find($input['id']);
        if ($entry === null || $entry->set('title', $input['title'])->save() === false) {
            throw new \RuntimeException('Draft save refused.');
        }

        return ['id' => $entry->id()];
    }
}

final class McpFixturePolicy implements ActionPolicy
{
    public function authorize(array $input, ExecutionContext $context): void
    {
        $entry = Entry::find($input['id']);
        $actor = User::find($context->actorId);
        if ($entry === null || $entry->published() || $actor === null || ! $actor->can('edit', $entry)) {
            throw new AuthorizationException;
        }
    }

    public function filterResult(array $input, array $result, ExecutionContext $context): array
    {
        return $result;
    }
}
