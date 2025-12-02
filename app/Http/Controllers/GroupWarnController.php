<?php

namespace App\Http\Controllers;

use App\Services\TelegramServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GroupWarnController extends Controller
{
    protected $telegram;

    public function __construct(TelegramServiceInterface $telegramService)
    {
        $this->telegram = $telegramService;
    }

    /**
     * Warn a member in a group from an authenticated request.
     */
    public function warn(Request $request)
    {
        $validated = $request->validate([
            'telegram_group_id' => 'required|integer',
            'chat_id' => 'required',
            'message' => 'required|string|max:500',
        ]);

        $group = DB::table('telegram_groups')->where('id', $validated['telegram_group_id'])->first();
        if (empty($group)) {
            return response()->json(['error' => true, 'message' => __('Group not found')], 404);
        }

        $bot = DB::table('telegram_bots')
            ->where(['id' => $group->telegram_bot_id, 'user_id' => Auth::id()])
            ->first();

        if (empty($bot)) {
            return response()->json(['error' => true, 'message' => __('Bot not found for this group')], 404);
        }

        $response = $this->sendWarning($bot->bot_token, $validated['chat_id'], $validated['message']);
        $isOk = isset($response['ok']) && $response['ok'] === true;

        return response()->json([
            'error' => !$isOk,
            'message' => $isOk ? __('Warning sent') : ($response['description'] ?? __('Unable to send warning')),
            'data' => $response,
        ], $isOk ? 200 : 400);
    }

    /**
     * Send a warning message using a bot token. Reusable outside HTTP requests.
     */
    public function sendWarning(string $botToken, $chatId, string $message, $replyToMessageId = null): array
    {
        $this->telegram->bot_token = $botToken;

        $payload = [
            'chat_id' => $chatId,
            'text' => strip_tags($message),
        ];

        if (!empty($replyToMessageId)) {
            $payload['reply_to_message_id'] = $replyToMessageId;
        }

        $response = $this->telegram->send('sendMessage', json_encode($payload));

        return json_decode($response, true) ?? [];
    }

    /**
     * Increment warn counter for a group offender.
     */
    public function incrementWarningLog(int $ownerUserId, int $telegramGroupId, $offenderChatId, ?string $reason = null): int
    {
        $now = now();
        $existing = DB::table('telegram_group_warn_logs')
            ->where([
                'user_id' => $ownerUserId,
                'telegram_group_id' => $telegramGroupId,
                'offender_chat_id' => $offenderChatId,
            ])->first();

        if ($existing) {
            $newCount = ((int) $existing->warn_count) + 1;
            DB::table('telegram_group_warn_logs')
                ->where('id', $existing->id)
                ->update([
                    'warn_count' => $newCount,
                    'warn_reason' => $reason ?? $existing->warn_reason,
                    'last_warned_at' => $now,
                    'updated_at' => $now
                ]);
            return $newCount;
        }

        DB::table('telegram_group_warn_logs')->insert([
            'user_id' => $ownerUserId,
            'telegram_group_id' => $telegramGroupId,
            'offender_chat_id' => $offenderChatId,
            'warn_count' => 1,
            'warn_reason' => $reason,
            'last_warned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return 1;
    }
}
