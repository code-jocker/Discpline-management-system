<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SubscriptionRepository;
use App\Repositories\TenantRepository;

final class TenantService
{
    private TenantRepository $tenants;
    private SubscriptionRepository $subscriptions;

    public function __construct(TenantRepository $tenants, SubscriptionRepository $subscriptions)
    {
        $this->tenants = $tenants;
        $this->subscriptions = $subscriptions;
    }

    public function resolveContext(array $context): ?array
    {
        $tenant = $this->tenants->resolveByContext(
            isset($context['tenant_id']) ? (int) $context['tenant_id'] : null,
            isset($context['tenant_slug']) ? (string) $context['tenant_slug'] : null,
            isset($context['domain']) ? (string) $context['domain'] : null
        );

        if (!$tenant) {
            return null;
        }

        $subscription = $this->subscriptions->activeForTenant((int) $tenant['id']);

        return [
            'tenant' => $tenant,
            'subscription' => $subscription,
        ];
    }
}
