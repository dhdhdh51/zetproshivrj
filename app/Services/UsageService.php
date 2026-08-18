<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiUsage;
use App\Models\Plan;
use App\Models\Subscription;

/**
 * Plan resolution + monthly usage limits. Every limit is enforced server side
 * before a document or an AI generation is allowed.
 */
final class UsageService
{
    private Plan $plans;
    private Subscription $subscriptions;
    private AiUsage $usage;

    public function __construct()
    {
        $this->plans = new Plan();
        $this->subscriptions = new Subscription();
        $this->usage = new AiUsage();
    }

    /**
     * The plan a user is currently on (falls back to Free).
     */
    public function currentPlan(int $userId): array
    {
        $subscription = $this->subscriptions->activeForUser($userId);

        if ($subscription === null) {
            $plan = $this->plans->free();
            $plan['subscription'] = null;

            return $plan;
        }

        $plan = $this->plans->findPlan((int) $subscription['plan_id']) ?? $this->plans->free();
        $plan['subscription'] = $subscription;

        return $plan;
    }

    public function usage(int $userId, ?string $period = null): array
    {
        $row = $this->usage->forPeriod($userId, $period);

        return [
            'period' => (string) $row['period'],
            'documents' => (int) $row['documents_created'],
            'ai' => (int) $row['ai_generations'],
            'emails' => (int) $row['emails_sent'],
        ];
    }

    /**
     * Everything the dashboard / pricing page needs in one call.
     */
    public function summary(int $userId): array
    {
        $plan = $this->currentPlan($userId);
        $usage = $this->usage($userId);

        return [
            'plan' => $plan,
            'period' => $usage['period'],
            'documents_used' => $usage['documents'],
            'documents_limit' => (int) $plan['document_limit'],
            'documents_percent' => percent_of($usage['documents'], (int) $plan['document_limit']),
            'documents_left' => max(0, (int) $plan['document_limit'] - $usage['documents']),
            'ai_used' => $usage['ai'],
            'ai_limit' => (int) $plan['ai_limit'],
            'ai_percent' => percent_of($usage['ai'], (int) $plan['ai_limit']),
            'ai_left' => max(0, (int) $plan['ai_limit'] - $usage['ai']),
            'emails_used' => $usage['emails'],
            'renews_at' => $plan['subscription']['ends_at'] ?? null,
        ];
    }

    /**
     * @return array{allowed:bool, message:string}
     */
    public function canCreateDocument(int $userId): array
    {
        $plan = $this->currentPlan($userId);
        $usage = $this->usage($userId);
        $limit = (int) $plan['document_limit'];

        if ($limit > 0 && $usage['documents'] >= $limit) {
            return [
                'allowed' => false,
                'message' => sprintf(
                    'You have used all %d documents included in the %s plan this month. Upgrade to create more.',
                    $limit,
                    (string) $plan['name']
                ),
            ];
        }

        return ['allowed' => true, 'message' => ''];
    }

    /**
     * @return array{allowed:bool, message:string}
     */
    public function canUseAi(int $userId): array
    {
        $plan = $this->currentPlan($userId);
        $usage = $this->usage($userId);
        $limit = (int) $plan['ai_limit'];

        if ($limit > 0 && $usage['ai'] >= $limit) {
            return [
                'allowed' => false,
                'message' => sprintf(
                    'You have used all %d AI generations included in the %s plan this month. Upgrade for more.',
                    $limit,
                    (string) $plan['name']
                ),
            ];
        }

        return ['allowed' => true, 'message' => ''];
    }

    /**
     * @return array{allowed:bool, message:string}
     */
    public function canEmailDocuments(int $userId): array
    {
        $plan = $this->currentPlan($userId);

        if (!$plan['email_enabled']) {
            return [
                'allowed' => false,
                'message' => 'Email delivery is available on the Pro and Business plans. Upgrade to send documents to clients by email.',
            ];
        }

        return ['allowed' => true, 'message' => ''];
    }

    public function canUseAllTemplates(int $userId): bool
    {
        return (bool) $this->currentPlan($userId)['all_templates'];
    }

    /* ------------------------------------------------------------------ */
    /* Counters                                                            */
    /* ------------------------------------------------------------------ */

    public function recordDocument(int $userId): void
    {
        $this->usage->increment($userId, 'documents_created');
    }

    public function recordAi(int $userId): void
    {
        $this->usage->increment($userId, 'ai_generations');
    }

    public function recordEmail(int $userId): void
    {
        $this->usage->increment($userId, 'emails_sent');
    }
}
