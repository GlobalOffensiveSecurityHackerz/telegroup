<?php

namespace App\Http\Controllers;

use App\Services\TelegramServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GroupBanController extends Controller
{
    protected $telegram;

    public function __construct(TelegramServiceInterface $telegramService)
    {
        $this->telegram = $telegramService;
    }

    /**
     * Ban or kick a member in a Telegram group and log it.
     *
     * @param string $action ban|kick
     */
    public function banOrKick(string $action, string $botToken, string $groupId, string $offenderChatId, ?string $reason = null, ?int $ownerUserId = null): array
    {
        $this->telegram->bot_token = $botToken;

        $method = $action === 'kick' ? 'banChatMember' : 'banChatMember';
        $responseRaw = $this->telegram->Ban_UnbanChatMember($method, $groupId, $offenderChatId);
        $response = json_decode($responseRaw, true) ?? [];

        // For "kick", unban immediately so the user can rejoin later.
        if ($action === 'kick') {
            $this->telegram->Ban_UnbanChatMember('unbanChatMember', $groupId, $offenderChatId);
        }

        $ownerId = $ownerUserId ?? Auth::id();
        if (!empty($ownerId) && !empty($groupId) && !empty($offenderChatId)) {
            $this->logBan((int) $ownerId, (int) $groupId, $offenderChatId, $action, $reason);
        }

        return $response;
    }

    public function logBan(int $ownerUserId, int $telegramGroupId, string $offenderChatId, string $action, ?string $reason = null): void
    {
        $now = now();
        DB::table('telegram_group_ban_logs')->insert([
            'user_id' => $ownerUserId,
            'telegram_group_id' => $telegramGroupId,
            'offender_chat_id' => $offenderChatId,
            'ban_action' => $action,
            'ban_reason' => $reason,
            'banned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
