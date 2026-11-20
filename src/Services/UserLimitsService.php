<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\ApiException;
use App\Repositories\CampaignRepository;
use App\Repositories\ShortcutRepository;
use App\Repositories\UserRepository;

final class UserLimitsService
{
    /** @var array<string, array{shortcuts: int, campaigns: int}> */
    public const PLAN_LIMITS = [
        'free' => [
            'shortcuts' => 50,
            'campaigns' => 5,
        ],
        'standard' => [
            'shortcuts' => 250,
            'campaigns' => 25,
        ],
        'pro' => [
            'shortcuts' => 1000,
            'campaigns' => 100,
        ],
        'enterprise' => [
            'shortcuts' => 10000,
            'campaigns' => 1000,
        ],
        'custom' => [
            'shortcuts' => 50,
            'campaigns' => 5,
        ],
    ];

    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly ShortcutRepository $shortcutRepo,
        private readonly CampaignRepository $campaignRepo,
    ) {
    }

    /**
     * @return array{
     *     plan: string,
     *     shortcuts: array{used: int, max: int, remaining: int, isReached: bool},
     *     campaigns: array{used: int, max: int, remaining: int, isReached: bool}
     * }
     */
    public function getUserLimits(string $userId): array
    {
        $user = $this->userRepo->findById($userId);
        $role = (string) ($user['role'] ?? 'user');
        $plan = (string) ($user['plan'] ?? 'free');
        if (!array_key_exists($plan, self::PLAN_LIMITS)) {
            $plan = 'free';
        }

        // Приоритет: индивидуальные лимиты пользователя -> тарифный план
        $planDefaults = self::PLAN_LIMITS[$plan];
        $maxShortcuts = isset($user['max_shortcuts']) && $user['max_shortcuts'] !== null
            ? (int) $user['max_shortcuts']
            : $planDefaults['shortcuts'];

        $maxCampaigns = isset($user['max_campaigns']) && $user['max_campaigns'] !== null
            ? (int) $user['max_campaigns']
            : $planDefaults['campaigns'];

        if ($role === 'admin') {
            $maxShortcuts = max($maxShortcuts, 100000);
            $maxCampaigns = max($maxCampaigns, 10000);
        }

        $shortcutsUsed = $this->shortcutRepo->countActiveForUser($userId);
        $campaignsUsed = $this->campaignRepo->countActiveForUser($userId);

        $shortcutsRemaining = max(0, $maxShortcuts - $shortcutsUsed);
        $campaignsRemaining = max(0, $maxCampaigns - $campaignsUsed);

        return [
            'plan' => $plan,
            'shortcuts' => [
                'used' => $shortcutsUsed,
                'max' => $maxShortcuts,
                'remaining' => $shortcutsRemaining,
                'isReached' => $shortcutsUsed >= $maxShortcuts,
            ],
            'campaigns' => [
                'used' => $campaignsUsed,
                'max' => $maxCampaigns,
                'remaining' => $campaignsRemaining,
                'isReached' => $campaignsUsed >= $maxCampaigns,
            ],
        ];
    }

    public function assertCanCreateShortcut(string $userId): void
    {
        $limits = $this->getUserLimits($userId);
        if ($limits['shortcuts']['isReached']) {
            throw ApiException::forbidden(
                'LIMIT_EXCEEDED_SHORTCUTS',
                "Вы достигли лимита ссылок ({$limits['shortcuts']['max']}). Для увеличения лимита обратитесь в службу поддержки.",
                [
                    'resource' => 'shortcuts',
                    'plan' => $limits['plan'],
                    'used' => $limits['shortcuts']['used'],
                    'max' => $limits['shortcuts']['max'],
                ]
            );
        }
    }

    public function assertCanCreateCampaign(string $userId): void
    {
        $limits = $this->getUserLimits($userId);
        if ($limits['campaigns']['isReached']) {
            throw ApiException::forbidden(
                'LIMIT_EXCEEDED_CAMPAIGNS',
                "Вы достигли лимита кампаний ({$limits['campaigns']['max']}). Для увеличения лимита обратитесь в службу поддержки.",
                [
                    'resource' => 'campaigns',
                    'plan' => $limits['plan'],
                    'used' => $limits['campaigns']['used'],
                    'max' => $limits['campaigns']['max'],
                ]
            );
        }
    }
}
