<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyClientQuestion;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CompanyClientQuestionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);

        $questions = $company->clientQuestions()
            ->with(['client:id,name,phone,email', 'assistant:id,name'])
            ->orderBy('position')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(400)
            ->get()
            ->reject(fn (CompanyClientQuestion $question): bool => $this->isArchived($question->metadata))
            ->values();

        return response()->json([
            'questions' => $questions
                ->map(fn (CompanyClientQuestion $question): array => $this->questionPayload($question))
                ->values(),
        ]);
    }

    public function update(Request $request, int $questionId): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'min:2', 'max:2000'],
            'status' => [
                'nullable',
                'string',
                Rule::in([
                    CompanyClientQuestion::STATUS_OPEN,
                    CompanyClientQuestion::STATUS_IN_PROGRESS,
                    CompanyClientQuestion::STATUS_ANSWERED,
                    CompanyClientQuestion::STATUS_CLOSED,
                ]),
            ],
            'board' => ['nullable', 'string', Rule::in(['new', 'in_progress', 'completed'])],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $question = $this->resolveQuestion($company, $questionId);

        if ($this->isArchived($question->metadata)) {
            return response()->json([
                'message' => 'Question is archived and cannot be updated.',
            ], 422);
        }

        if (array_key_exists('description', $validated)) {
            $description = trim((string) ($validated['description'] ?? ''));

            if ($description === '') {
                return response()->json([
                    'message' => 'Description cannot be empty.',
                ], 422);
            }

            $question->description = Str::limit($description, 2000, '');
        }

        [$nextStatus, $nextBoard, $consistencyError] = $this->resolveNextStatusAndBoard(
            array_key_exists('status', $validated) ? (string) $validated['status'] : null,
            array_key_exists('board', $validated) ? (string) $validated['board'] : null,
        );

        if ($consistencyError !== null) {
            return response()->json([
                'message' => $consistencyError,
            ], 422);
        }

        if ($nextStatus !== null) {
            $question->status = $nextStatus;
            $question->board_column = $nextBoard;
            $question->resolved_at = $nextBoard === 'completed'
                ? ($question->resolved_at ?? now())
                : null;
        }

        if (
            in_array((string) $question->board_column, ['new', 'in_progress'], true)
            && ! $this->canKeepQuestionActiveForChat($company, $question)
        ) {
            return response()->json([
                'message' => 'Only one active question is allowed per chat.',
            ], 422);
        }

        $question->save();

        return response()->json([
            'message' => 'Question updated successfully.',
            'question' => $this->questionPayload($question->fresh(['client:id,name,phone,email', 'assistant:id,name'])),
        ]);
    }

    public function destroy(Request $request, int $questionId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $question = $this->resolveQuestion($company, $questionId);

        $metadata = is_array($question->metadata) ? $question->metadata : [];
        $metadata['archived'] = true;
        $metadata['archived_at'] = now()->toIso8601String();

        $question->forceFill([
            'metadata' => $metadata,
        ])->save();

        return response()->json([
            'message' => 'Question archived successfully.',
        ]);
    }

    private function resolveUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function resolveCompany(User $user): Company
    {
        return $this->subscriptionService()->provisionDefaultWorkspaceForUser(
            $user->id,
            $user->name
        );
    }

    private function resolveQuestion(Company $company, int $questionId): CompanyClientQuestion
    {
        /** @var CompanyClientQuestion|null $question */
        $question = $company->clientQuestions()
            ->with(['client:id,name,phone,email', 'assistant:id,name'])
            ->whereKey($questionId)
            ->first();

        if (! $question) {
            abort(404, 'Question not found.');
        }

        return $question;
    }

    private function questionPayload(CompanyClientQuestion $question): array
    {
        $metadata = is_array($question->metadata) ? $question->metadata : [];
        $board = $this->normalizeBoard((string) $question->board_column, (string) $question->status);

        return [
            'id' => $question->id,
            'description' => $question->description,
            'status' => $this->normalizeStatus((string) $question->status),
            'board' => $board,
            'position' => (int) ($question->position ?? 0),
            'resolved_at' => $question->resolved_at?->toIso8601String(),
            'source_chat_id' => $this->extractSourceChatId($metadata),
            'source_channel' => $this->extractSourceChannel($metadata),
            'client' => $question->client
                ? [
                    'id' => $question->client->id,
                    'name' => $question->client->name,
                    'phone' => $question->client->phone,
                    'email' => $question->client->email,
                ]
                : null,
            'assistant' => $question->assistant
                ? [
                    'id' => $question->assistant->id,
                    'name' => $question->assistant->name,
                ]
                : null,
            'created_at' => $question->created_at?->toIso8601String(),
            'updated_at' => $question->updated_at?->toIso8601String(),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = trim($status);

        if (in_array($normalized, [
            CompanyClientQuestion::STATUS_OPEN,
            CompanyClientQuestion::STATUS_IN_PROGRESS,
            CompanyClientQuestion::STATUS_ANSWERED,
            CompanyClientQuestion::STATUS_CLOSED,
        ], true)) {
            return $normalized;
        }

        return CompanyClientQuestion::STATUS_OPEN;
    }

    private function normalizeBoard(string $board, string $status): string
    {
        $normalizedBoard = trim($board);
        if (in_array($normalizedBoard, ['new', 'in_progress', 'completed'], true)) {
            return $normalizedBoard;
        }

        return $this->boardFromStatus($this->normalizeStatus($status));
    }

    private function boardFromStatus(string $status): string
    {
        return match ($status) {
            CompanyClientQuestion::STATUS_IN_PROGRESS => 'in_progress',
            CompanyClientQuestion::STATUS_ANSWERED,
            CompanyClientQuestion::STATUS_CLOSED => 'completed',
            default => 'new',
        };
    }

    private function statusFromBoard(string $board): string
    {
        return match ($board) {
            'in_progress' => CompanyClientQuestion::STATUS_IN_PROGRESS,
            'completed' => CompanyClientQuestion::STATUS_ANSWERED,
            default => CompanyClientQuestion::STATUS_OPEN,
        };
    }

    /**
     * @return array{0:?string,1:?string,2:?string}
     */
    private function resolveNextStatusAndBoard(?string $status, ?string $board): array
    {
        if ($status === null && $board === null) {
            return [null, null, null];
        }

        if ($status !== null && $board !== null) {
            $normalizedStatus = $this->normalizeStatus($status);
            $normalizedBoard = $this->normalizeBoard($board, $normalizedStatus);

            if ($this->boardFromStatus($normalizedStatus) !== $normalizedBoard) {
                return [null, null, 'status and board values are inconsistent.'];
            }

            return [$normalizedStatus, $normalizedBoard, null];
        }

        if ($board !== null) {
            $normalizedBoard = $this->normalizeBoard($board, CompanyClientQuestion::STATUS_OPEN);

            return [$this->statusFromBoard($normalizedBoard), $normalizedBoard, null];
        }

        $normalizedStatus = $this->normalizeStatus((string) $status);

        return [$normalizedStatus, $this->boardFromStatus($normalizedStatus), null];
    }

    private function canKeepQuestionActiveForChat(Company $company, CompanyClientQuestion $question): bool
    {
        $metadata = is_array($question->metadata) ? $question->metadata : [];
        $chatId = $this->extractSourceChatId($metadata);

        if ($chatId === null) {
            return true;
        }

        return ! $company->clientQuestions()
            ->whereKeyNot($question->id)
            ->whereIn('board_column', ['new', 'in_progress'])
            ->where(function ($builder) use ($chatId): void {
                $builder
                    ->where('metadata->chat_id', $chatId)
                    ->orWhere('metadata->source_chat_id', $chatId)
                    ->orWhere('metadata->chat->id', $chatId)
                    ->orWhere('metadata->source->chat_id', $chatId);
            })
            ->exists();
    }

    private function extractSourceChatId(array $metadata): ?int
    {
        $candidates = [
            data_get($metadata, 'chat_id'),
            data_get($metadata, 'source_chat_id'),
            data_get($metadata, 'chat.id'),
            data_get($metadata, 'source.chat_id'),
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private function extractSourceChannel(array $metadata): ?string
    {
        $candidates = [
            data_get($metadata, 'source_channel'),
            data_get($metadata, 'source.channel'),
            data_get($metadata, 'channel'),
        ];

        foreach ($candidates as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized = trim($value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function isArchived(mixed $metadata): bool
    {
        if (! is_array($metadata)) {
            return false;
        }

        $archived = data_get($metadata, 'archived');

        return $archived === true
            || $archived === 1
            || $archived === '1';
    }

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }
}
