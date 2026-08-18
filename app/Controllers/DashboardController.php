<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\AiGeneration;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Document;
use App\Services\OpenRouterService;
use App\Services\UsageService;

final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $userId = (int) Auth::id();

        $documents = new Document();
        $profiles = new BusinessProfile();
        $clients = new Client();
        $usage = new UsageService();

        $profile = $profiles->forUser($userId);
        $stats = $documents->statsForUser($userId);
        $summary = $usage->summary($userId);

        $this->view('dashboard.index', [
            'title' => 'Dashboard',
            'stats' => $stats,
            'summary' => $summary,
            'recent' => $documents->recentForUser($userId, 6),
            'clients_count' => $clients->count(['user_id' => $userId]),
            'profile' => $profile,
            'profile_complete' => $profiles->isComplete($profile),
            'ai_recent' => (new AiGeneration())->recentForUser($userId, 5),
            'ai_ready' => (new OpenRouterService())->isEnabled(),
            'user' => Auth::user(),
        ]);
    }
}
