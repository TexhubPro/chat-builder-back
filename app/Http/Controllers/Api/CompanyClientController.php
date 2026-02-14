<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyCalendarEvent;
use App\Models\CompanyClient;
use App\Models\CompanyClientOrder;
use App\Models\CompanyClientQuestion;
use App\Models\CompanyClientTask;
use App\Models\User;
use App\Services\CompanySubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CompanyClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', 'in:active,archived,blocked'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);

        $search = trim((string) ($validated['search'] ?? ''));
        $status = trim((string) ($validated['status'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 80);

        $query = $company->clients()
            ->withCount([
                'orders',
                'tasks',
                'questions',
                'calendarEvents as appointments_count',
            ])
            ->withSum('orders as total_spent', 'total_price')
            ->withMax('orders as latest_ordered_at', 'ordered_at')
            ->withMax('calendarEvents as latest_appointment_at', 'starts_at')
            ->withMax('tasks as latest_task_at', 'created_at')
            ->withMax('questions as latest_question_at', 'created_at')
            ->selectSub(
                CompanyClientOrder::query()
                    ->select('currency')
                    ->whereColumn('company_client_id', 'company_clients.id')
                    ->orderByDesc('ordered_at')
                    ->orderByDesc('id')
                    ->limit(1),
                'last_order_currency'
            )
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        $clients = $query
            ->limit($limit)
            ->get();

        return response()->json([
            'clients' => $clients
                ->map(fn (CompanyClient $client): array => $this->clientCardPayload($client))
                ->values(),
            'counts' => [
                'all' => $company->clients()->count(),
                'active' => $company->clients()->where('status', CompanyClient::STATUS_ACTIVE)->count(),
                'archived' => $company->clients()->where('status', CompanyClient::STATUS_ARCHIVED)->count(),
                'blocked' => $company->clients()->where('status', CompanyClient::STATUS_BLOCKED)->count(),
            ],
        ]);
    }

    public function show(Request $request, int $clientId): JsonResponse
    {
        $user = $this->resolveUser($request);
        $company = $this->resolveCompany($user);
        $client = $this->resolveClient($company, $clientId);

        $orders = $client->orders()
            ->with('assistant:id,name')
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        $appointments = $client->calendarEvents()
            ->with('assistant:id,name')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        $tasks = $client->tasks()
            ->with([
                'assistant:id,name',
                'calendarEvent:id,starts_at,ends_at,timezone,status',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        $questions = $client->questions()
            ->with('assistant:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        return response()->json([
            'client' => $this->clientDetailsPayload($client),
            'history' => [
                'orders' => $orders
                    ->map(fn (CompanyClientOrder $order): array => $this->orderHistoryPayload($order))
                    ->values(),
                'appointments' => $appointments
                    ->map(fn (CompanyCalendarEvent $event): array => $this->appointmentHistoryPayload($event))
                    ->values(),
                'tasks' => $tasks
                    ->map(fn (CompanyClientTask $task): array => $this->taskHistoryPayload($task))
                    ->values(),
                'questions' => $questions
                    ->map(fn (CompanyClientQuestion $question): array => $this->questionHistoryPayload($question))
                    ->values(),
                'timeline' => $this->timelinePayload($orders, $appointments, $tasks, $questions),
            ],
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

    private function resolveClient(Company $company, int $clientId): CompanyClient
    {
        /** @var CompanyClient|null $client */
        $client = $company->clients()
            ->whereKey($clientId)
            ->first();

        if (! $client) {
            abort(404, 'Client not found.');
        }

        return $client;
    }

    private function clientCardPayload(CompanyClient $client): array
    {
        $metadata = is_array($client->metadata) ? $client->metadata : [];

        $latestOrder = $this->toIso8601($client->latest_ordered_at ?? null);
        $latestAppointment = $this->toIso8601($client->latest_appointment_at ?? null);
        $latestTask = $this->toIso8601($client->latest_task_at ?? null);
        $latestQuestion = $this->toIso8601($client->latest_question_at ?? null);

        $lastContact = $this->latestIsoTimestamp([
            $latestOrder,
            $latestAppointment,
            $latestTask,
            $latestQuestion,
            $this->toIso8601($client->updated_at),
        ]);

        $currency = trim((string) ($client->last_order_currency ?? 'TJS'));
        if ($currency === '') {
            $currency = 'TJS';
        }

        return [
            'id' => $client->id,
            'name' => $client->name,
            'phone' => $client->phone,
            'email' => $client->email,
            'notes' => $client->notes,
            'status' => $client->status,
            'avatar' => $this->clientAvatar($metadata),
            'stats' => [
                'orders_count' => (int) ($client->orders_count ?? 0),
                'appointments_count' => (int) ($client->appointments_count ?? 0),
                'tasks_count' => (int) ($client->tasks_count ?? 0),
                'questions_count' => (int) ($client->questions_count ?? 0),
                'total_spent' => (float) ($client->total_spent ?? 0),
                'currency' => $currency,
            ],
            'activity' => [
                'last_ordered_at' => $latestOrder,
                'last_appointment_at' => $latestAppointment,
                'last_task_at' => $latestTask,
                'last_question_at' => $latestQuestion,
                'last_contact_at' => $lastContact,
            ],
            'created_at' => $this->toIso8601($client->created_at),
            'updated_at' => $this->toIso8601($client->updated_at),
        ];
    }

    private function clientDetailsPayload(CompanyClient $client): array
    {
        $metadata = is_array($client->metadata) ? $client->metadata : [];

        return [
            'id' => $client->id,
            'name' => $client->name,
            'phone' => $client->phone,
            'email' => $client->email,
            'notes' => $client->notes,
            'status' => $client->status,
            'avatar' => $this->clientAvatar($metadata),
            'metadata' => $metadata !== [] ? $metadata : null,
            'created_at' => $this->toIso8601($client->created_at),
            'updated_at' => $this->toIso8601($client->updated_at),
        ];
    }

    private function orderHistoryPayload(CompanyClientOrder $order): array
    {
        $metadata = is_array($order->metadata) ? $order->metadata : [];

        return [
            'id' => $order->id,
            'service_name' => $order->service_name,
            'status' => $order->status,
            'quantity' => (int) $order->quantity,
            'unit_price' => (float) $order->unit_price,
            'total_price' => (float) $order->total_price,
            'currency' => $order->currency,
            'notes' => $order->notes,
            'address' => $this->nullableTrimmedString($metadata['address'] ?? null),
            'phone' => $this->nullableTrimmedString($metadata['phone'] ?? null),
            'assistant' => $order->assistant
                ? [
                    'id' => $order->assistant->id,
                    'name' => $order->assistant->name,
                ]
                : null,
            'ordered_at' => $this->toIso8601($order->ordered_at),
            'completed_at' => $this->toIso8601($order->completed_at),
            'created_at' => $this->toIso8601($order->created_at),
            'metadata' => $metadata !== [] ? $metadata : null,
        ];
    }

    private function appointmentHistoryPayload(CompanyCalendarEvent $event): array
    {
        $metadata = is_array($event->metadata) ? $event->metadata : [];

        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'status' => $event->status,
            'starts_at' => $this->toIso8601($event->starts_at),
            'ends_at' => $this->toIso8601($event->ends_at),
            'timezone' => $event->timezone,
            'location' => $event->location,
            'meeting_link' => $event->meeting_link,
            'assistant' => $event->assistant
                ? [
                    'id' => $event->assistant->id,
                    'name' => $event->assistant->name,
                ]
                : null,
            'created_at' => $this->toIso8601($event->created_at),
            'metadata' => $metadata !== [] ? $metadata : null,
        ];
    }

    private function taskHistoryPayload(CompanyClientTask $task): array
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];

        return [
            'id' => $task->id,
            'description' => $task->description,
            'status' => $task->status,
            'board_column' => $task->board_column,
            'priority' => $task->priority,
            'sync_with_calendar' => (bool) $task->sync_with_calendar,
            'scheduled_at' => $this->toIso8601($task->scheduled_at),
            'due_at' => $this->toIso8601($task->due_at),
            'completed_at' => $this->toIso8601($task->completed_at),
            'assistant' => $task->assistant
                ? [
                    'id' => $task->assistant->id,
                    'name' => $task->assistant->name,
                ]
                : null,
            'calendar_event' => $task->calendarEvent
                ? [
                    'id' => $task->calendarEvent->id,
                    'starts_at' => $this->toIso8601($task->calendarEvent->starts_at),
                    'ends_at' => $this->toIso8601($task->calendarEvent->ends_at),
                    'timezone' => $task->calendarEvent->timezone,
                    'status' => $task->calendarEvent->status,
                ]
                : null,
            'created_at' => $this->toIso8601($task->created_at),
            'metadata' => $metadata !== [] ? $metadata : null,
        ];
    }

    private function questionHistoryPayload(CompanyClientQuestion $question): array
    {
        $metadata = is_array($question->metadata) ? $question->metadata : [];

        return [
            'id' => $question->id,
            'description' => $question->description,
            'status' => $question->status,
            'board_column' => $question->board_column,
            'resolved_at' => $this->toIso8601($question->resolved_at),
            'assistant' => $question->assistant
                ? [
                    'id' => $question->assistant->id,
                    'name' => $question->assistant->name,
                ]
                : null,
            'created_at' => $this->toIso8601($question->created_at),
            'metadata' => $metadata !== [] ? $metadata : null,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, CompanyClientOrder> $orders
     * @param \Illuminate\Support\Collection<int, CompanyCalendarEvent> $appointments
     * @param \Illuminate\Support\Collection<int, CompanyClientTask> $tasks
     * @param \Illuminate\Support\Collection<int, CompanyClientQuestion> $questions
     */
    private function timelinePayload(
        $orders,
        $appointments,
        $tasks,
        $questions
    ): array {
        $rows = [];

        foreach ($orders as $order) {
            $rows[] = [
                'type' => 'order',
                'id' => (int) $order->id,
                'title' => 'Order: '.Str::limit((string) $order->service_name, 140, ''),
                'description' => $order->notes,
                'status' => $order->status,
                'amount' => (float) $order->total_price,
                'currency' => (string) $order->currency,
                'happened_at' => $this->toIso8601($order->ordered_at ?? $order->created_at),
                'created_at' => $this->toIso8601($order->created_at),
            ];
        }

        foreach ($appointments as $event) {
            $rows[] = [
                'type' => 'appointment',
                'id' => (int) $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'status' => $event->status,
                'happened_at' => $this->toIso8601($event->starts_at),
                'created_at' => $this->toIso8601($event->created_at),
            ];
        }

        foreach ($tasks as $task) {
            $rows[] = [
                'type' => 'task',
                'id' => (int) $task->id,
                'title' => Str::limit((string) $task->description, 140, ''),
                'description' => $task->description,
                'status' => $task->status,
                'happened_at' => $this->toIso8601($task->scheduled_at ?? $task->created_at),
                'created_at' => $this->toIso8601($task->created_at),
            ];
        }

        foreach ($questions as $question) {
            $rows[] = [
                'type' => 'question',
                'id' => (int) $question->id,
                'title' => Str::limit((string) $question->description, 140, ''),
                'description' => $question->description,
                'status' => $question->status,
                'happened_at' => $this->toIso8601($question->created_at),
                'created_at' => $this->toIso8601($question->created_at),
            ];
        }

        usort($rows, function (array $left, array $right): int {
            $leftTs = strtotime((string) ($left['happened_at'] ?? '')) ?: 0;
            $rightTs = strtotime((string) ($right['happened_at'] ?? '')) ?: 0;

            if ($leftTs === $rightTs) {
                $leftCreated = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
                $rightCreated = strtotime((string) ($right['created_at'] ?? '')) ?: 0;

                return $rightCreated <=> $leftCreated;
            }

            return $rightTs <=> $leftTs;
        });

        return $rows;
    }

    private function clientAvatar(array $metadata): ?string
    {
        foreach (['avatar', 'avatar_url', 'photo', 'photo_url'] as $key) {
            $value = $this->nullableTrimmedString($metadata[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function latestIsoTimestamp(array $values): ?string
    {
        $latest = null;

        foreach ($values as $value) {
            $normalized = $this->toIso8601($value);
            if ($normalized === null) {
                continue;
            }

            $ts = strtotime($normalized);
            if ($ts === false) {
                continue;
            }

            if ($latest === null || $ts > $latest) {
                $latest = $ts;
            }
        }

        return $latest !== null
            ? Carbon::createFromTimestampUTC($latest)->toIso8601String()
            : null;
    }

    private function toIso8601(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function subscriptionService(): CompanySubscriptionService
    {
        return app(CompanySubscriptionService::class);
    }
}
