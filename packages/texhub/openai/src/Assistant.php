<?php

namespace TexHub\OpenAi;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use TexHub\OpenAi\Models\OpenAiLog;
use Throwable;

class Assistant
{
    public function __construct(private array $config = [])
    {
    }

    public function config(): array
    {
        return $this->config;
    }

    /**
     * Create an assistant and return only its id on success.
     */
    public function createAssistant(array $payload): ?string
    {
        $defaults = $this->config['defaults'] ?? [];
        $body = $this->normalizeAssistantPayload(array_merge($defaults, $payload));

        if (empty($body['model'])) {
            $this->logError('OpenAI assistant create failed', [
                'error' => 'Missing required model.',
                'payload' => $body,
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->post($this->endpoint('/assistants'), $body);

            if (! $response->successful()) {
                $this->logError('OpenAI assistant create failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'payload' => $body,
                ]);

                return null;
            }

            $id = $response->json('id');

            if (! is_string($id) || $id === '') {
                $this->logError('OpenAI assistant create failed', [
                    'error' => 'Missing assistant id in response.',
                    'body' => $response->json(),
                ]);

                return null;
            }

            return $id;
        } catch (Throwable $exception) {
            $this->logError('OpenAI assistant create failed', [
                'error' => $exception->getMessage(),
                'payload' => $body,
            ]);

            return null;
        }
    }

    /**
     * Update an assistant. Returns true on success.
     */
    public function updateAssistant(string $assistantId, array $payload): bool
    {
        if ($assistantId === '') {
            $this->logError('OpenAI assistant update failed', [
                'error' => 'Missing assistant id.',
                'payload' => $payload,
            ]);

            return false;
        }

        $payload = $this->normalizeAssistantPayload($payload);

        try {
            $response = $this->http()
                ->post($this->endpoint('/assistants/' . $assistantId), $payload);

            if (! $response->successful()) {
                $this->logError('OpenAI assistant update failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'payload' => $payload,
                ]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            $this->logError('OpenAI assistant update failed', [
                'error' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return false;
        }
    }

    /**
     * Create a thread and return only its id on success.
     *
     * @param array $messages Optional initial messages for the thread.
     * @param array $metadata Optional metadata for the thread.
     */
    public function createThread(array $messages = [], array $metadata = []): ?string
    {
        $body = [];

        if (! empty($messages)) {
            $body['messages'] = $messages;
        }

        if (! empty($metadata)) {
            $body['metadata'] = $metadata;
        }

        try {
            $response = $this->http()
                ->post($this->endpoint('/threads'), $body);

            if (! $response->successful()) {
                $this->logError('OpenAI thread create failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'payload' => $body,
                ]);

                return null;
            }

            $id = $response->json('id');

            if (! is_string($id) || $id === '') {
                $this->logError('OpenAI thread create failed', [
                    'error' => 'Missing thread id in response.',
                    'body' => $response->json(),
                ]);

                return null;
            }

            return $id;
        } catch (Throwable $exception) {
            $this->logError('OpenAI thread create failed', [
                'error' => $exception->getMessage(),
                'payload' => $body,
            ]);

            return null;
        }
    }

    /**
     * Delete a thread.
     */
    public function deleteThread(string $threadId): bool
    {
        if ($threadId === '') {
            $this->logError('OpenAI thread delete failed', [
                'error' => 'Missing thread id.',
            ]);

            return false;
        }

        try {
            $response = $this->http()
                ->delete($this->endpoint('/threads/' . $threadId));

            $data = $this->parseJsonResponse($response, 'OpenAI thread delete failed', [
                'thread_id' => $threadId,
            ]);

            return (bool) ($data['deleted'] ?? false);
        } catch (Throwable $exception) {
            $this->logError('OpenAI thread delete failed', [
                'error' => $exception->getMessage(),
                'thread_id' => $threadId,
            ]);

            return false;
        }
    }

    /**
     * Create a message in a thread and return only its id on success.
     *
     * @param string $threadId
     * @param string $role
     * @param string|array $content
     * @param array $attachments
     * @param array $metadata
     */
    public function createMessage(
        string $threadId,
        string $role,
        string|array $content,
        array $attachments = [],
        array $metadata = []
    ): ?string {
        if ($threadId === '') {
            $this->logError('OpenAI message create failed', [
                'error' => 'Missing thread id.',
            ]);

            return null;
        }

        $body = [
            'role' => $role,
            'content' => $content,
        ];

        if (! empty($attachments)) {
            $body['attachments'] = $attachments;
        }

        if (! empty($metadata)) {
            $body['metadata'] = $metadata;
        }

        try {
            $response = $this->http()
                ->post($this->endpoint('/threads/' . $threadId . '/messages'), $body);

            if (! $response->successful()) {
                $this->logError('OpenAI message create failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'payload' => $body,
                ]);

                return null;
            }

            $id = $response->json('id');

            if (! is_string($id) || $id === '') {
                $this->logError('OpenAI message create failed', [
                    'error' => 'Missing message id in response.',
                    'body' => $response->json(),
                ]);

                return null;
            }

            return $id;
        } catch (Throwable $exception) {
            $this->logError('OpenAI message create failed', [
                'error' => $exception->getMessage(),
                'payload' => $body,
            ]);

            return null;
        }
    }

    /**
     * Send a plain text message to a thread.
     */
    public function sendTextMessage(
        string $threadId,
        string $text,
        array $metadata = [],
        string $role = 'user'
    ): ?string {
        return $this->createMessage($threadId, $role, $text, [], $metadata);
    }

    /**
     * Send image URLs (optionally with text) to a thread.
     *
     * @param string $threadId
     * @param string|array $imageUrls
     * @param string|null $text
     * @param string $detail
     * @param array $metadata
     * @param string $role
     */
    public function sendImageUrlMessage(
        string $threadId,
        string|array $imageUrls,
        ?string $text = null,
        string $detail = 'auto',
        array $metadata = [],
        string $role = 'user'
    ): ?string {
        $urls = is_array($imageUrls) ? $imageUrls : [$imageUrls];

        $content = [];

        if ($text !== null && $text !== '') {
            $content[] = [
                'type' => 'text',
                'text' => $text,
            ];
        }

        foreach ($urls as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $url,
                    'detail' => $detail,
                ],
            ];
        }

        return $this->createMessage($threadId, $role, $content, [], $metadata);
    }

    /**
     * Send image file IDs (optionally with text) to a thread.
     *
     * @param string $threadId
     * @param string|array $fileIds
     * @param string|null $text
     * @param string $detail
     * @param array $metadata
     * @param string $role
     */
    public function sendImageFileMessage(
        string $threadId,
        string|array $fileIds,
        ?string $text = null,
        string $detail = 'auto',
        array $metadata = [],
        string $role = 'user'
    ): ?string {
        $ids = is_array($fileIds) ? $fileIds : [$fileIds];

        $content = [];

        if ($text !== null && $text !== '') {
            $content[] = [
                'type' => 'text',
                'text' => $text,
            ];
        }

        foreach ($ids as $fileId) {
            if (! is_string($fileId) || $fileId === '') {
                continue;
            }

            $content[] = [
                'type' => 'image_file',
                'image_file' => [
                    'file_id' => $fileId,
                    'detail' => $detail,
                ],
            ];
        }

        return $this->createMessage($threadId, $role, $content, [], $metadata);
    }

    /**
     * Send documents/videos (file attachments) to a thread.
     *
     * @param string $threadId
     * @param array $fileIds
     * @param string|null $text
     * @param array $toolTypes
     * @param array $metadata
     * @param string $role
     */
    public function sendFileMessage(
        string $threadId,
        array $fileIds,
        ?string $text = null,
        array $toolTypes = ['file_search'],
        array $metadata = [],
        string $role = 'user'
    ): ?string {
        $attachments = $this->buildAttachments($fileIds, $toolTypes);
        $content = $text ?? '';

        return $this->createMessage($threadId, $role, $content, $attachments, $metadata);
    }

    /**
     * Synthesize speech from text. Returns audio bytes or saved path on success.
     *
     * @param string $text
     * @param array $options model, voice, response_format, speed, instructions
     * @param string|null $outputPath
     */
    public function createSpeech(string $text, array $options = [], ?string $outputPath = null): ?string
    {
        $input = trim($text);

        if ($input === '') {
            $this->logError('OpenAI speech create failed', [
                'error' => 'Missing input text.',
            ]);

            return null;
        }

        $ttsConfig = $this->config['tts'] ?? [];
        $model = $options['model'] ?? $ttsConfig['model'] ?? null;
        $voice = $options['voice'] ?? $ttsConfig['voice'] ?? null;

        if (! is_string($model) || $model === '') {
            $this->logError('OpenAI speech create failed', [
                'error' => 'Missing model.',
            ]);

            return null;
        }

        if (! is_string($voice) || $voice === '') {
            $this->logError('OpenAI speech create failed', [
                'error' => 'Missing voice.',
            ]);

            return null;
        }

        $body = [
            'model' => $model,
            'voice' => $voice,
            'input' => $input,
        ];

        $responseFormat = $options['response_format'] ?? $ttsConfig['response_format'] ?? null;
        if (is_string($responseFormat) && $responseFormat !== '') {
            $body['response_format'] = $responseFormat;
        }

        if (array_key_exists('speed', $options) || array_key_exists('speed', $ttsConfig)) {
            $speed = $options['speed'] ?? $ttsConfig['speed'];
            if (is_numeric($speed)) {
                $body['speed'] = (float) $speed;
            }
        }

        if (! empty($options['instructions']) && is_string($options['instructions'])) {
            $body['instructions'] = $options['instructions'];
        }

        try {
            $response = $this->http()
                ->post($this->endpoint('/audio/speech'), $body);

            if (! $response->successful()) {
                $this->logError('OpenAI speech create failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'payload' => $body,
                ]);

                return null;
            }

            $audio = $response->body();

            if (! is_string($audio) || $audio === '') {
                $this->logError('OpenAI speech create failed', [
                    'error' => 'Empty audio response.',
                ]);

                return null;
            }

            if ($outputPath !== null) {
                $written = @file_put_contents($outputPath, $audio);

                if ($written === false) {
                    $this->logError('OpenAI speech create failed', [
                        'error' => 'Failed to write audio file.',
                        'output_path' => $outputPath,
                    ]);

                    return null;
                }

                return $outputPath;
            }

            return $audio;
        } catch (Throwable $exception) {
            $this->logError('OpenAI speech create failed', [
                'error' => $exception->getMessage(),
                'payload' => $body,
            ]);

            return null;
        }
    }

    /**
     * Start a run for a thread with an assistant and return assistant response text.
     *
     * @param string $threadId
     * @param string $assistantId
     * @param array $runPayload Optional run overrides (model, instructions, tools, metadata, etc).
     * @param int $maxAttempts
     * @param int $sleepMs
     */
    public function runThreadAndGetResponse(
        string $threadId,
        string $assistantId,
        array $runPayload = [],
        int $maxAttempts = 30,
        int $sleepMs = 1000
    ): ?string {
        $maxServerErrorRetries = 2;

        for ($attempt = 0; $attempt <= $maxServerErrorRetries; $attempt++) {
            $runId = $this->createRun($threadId, $assistantId, $runPayload);

            if ($runId === null) {
                return null;
            }

            $run = $this->waitForRun($threadId, $runId, $maxAttempts, $sleepMs);

            if ($run === null) {
                return null;
            }

            if (($run['status'] ?? null) === 'completed') {
                return $this->getLatestAssistantMessageText($threadId);
            }

            $status = (string) ($run['status'] ?? '');
            $lastError = $run['last_error'] ?? null;
            $lastErrorCode = is_array($lastError) ? (string) ($lastError['code'] ?? '') : '';

            $this->logError('OpenAI run did not complete', [
                'thread_id' => $threadId,
                'run_id' => $runId,
                'status' => $run['status'] ?? null,
                'last_error' => $run['last_error'] ?? null,
                'required_action' => $run['required_action'] ?? null,
                'attempt' => $attempt + 1,
                'max_attempts_on_server_error' => $maxServerErrorRetries + 1,
            ]);

            if ($status !== 'failed' || $lastErrorCode !== 'server_error' || $attempt >= $maxServerErrorRetries) {
                return null;
            }

            usleep(($attempt + 1) * 400000);
        }

        return null;
    }

    /**
     * Create a run and return its id on success.
     */
    public function createRun(string $threadId, string $assistantId, array $payload = []): ?string
    {
        if ($threadId === '' || $assistantId === '') {
            $this->logError('OpenAI run create failed', [
                'error' => 'Missing thread id or assistant id.',
                'thread_id' => $threadId,
                'assistant_id' => $assistantId,
            ]);

            return null;
        }

        $body = array_merge($payload, [
            'assistant_id' => $assistantId,
        ]);

        try {
            $response = $this->http()
                ->post($this->endpoint('/threads/' . $threadId . '/runs'), $body);

            if (! $response->successful()) {
                $this->logError('OpenAI run create failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'payload' => $body,
                ]);

                return null;
            }

            $id = $response->json('id');

            if (! is_string($id) || $id === '') {
                $this->logError('OpenAI run create failed', [
                    'error' => 'Missing run id in response.',
                    'body' => $response->json(),
                ]);

                return null;
            }

            return $id;
        } catch (Throwable $exception) {
            $this->logError('OpenAI run create failed', [
                'error' => $exception->getMessage(),
                'payload' => $body,
            ]);

            return null;
        }
    }

    /**
     * Wait for a run to reach a terminal state and return the run data.
     */
    public function waitForRun(string $threadId, string $runId, int $maxAttempts = 30, int $sleepMs = 1000): ?array
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $run = $this->retrieveRun($threadId, $runId);

            if ($run === null) {
                return null;
            }

            $status = $run['status'] ?? null;

            if (in_array($status, ['queued', 'in_progress'], true)) {
                usleep(max($sleepMs, 1) * 1000);
                continue;
            }

            return $run;
        }

        $this->logError('OpenAI run wait timeout', [
            'thread_id' => $threadId,
            'run_id' => $runId,
            'max_attempts' => $maxAttempts,
        ]);

        return null;
    }

    /**
     * Retrieve a run.
     */
    public function retrieveRun(string $threadId, string $runId): ?array
    {
        if ($threadId === '' || $runId === '') {
            $this->logError('OpenAI run retrieve failed', [
                'error' => 'Missing thread id or run id.',
                'thread_id' => $threadId,
                'run_id' => $runId,
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/threads/' . $threadId . '/runs/' . $runId));

            if (! $response->successful()) {
                $this->logError('OpenAI run retrieve failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            }

            $run = $response->json();

            if (! is_array($run)) {
                $this->logError('OpenAI run retrieve failed', [
                    'error' => 'Invalid run response.',
                    'body' => $response->json(),
                ]);

                return null;
            }

            return $run;
        } catch (Throwable $exception) {
            $this->logError('OpenAI run retrieve failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get latest assistant text message from a thread.
     */
    public function getLatestAssistantMessageText(string $threadId, int $limit = 20): ?string
    {
        if ($threadId === '') {
            $this->logError('OpenAI messages list failed', [
                'error' => 'Missing thread id.',
            ]);

            return null;
        }

        try {
            $response = $this->http()->get(
                $this->endpoint('/threads/' . $threadId . '/messages'),
                [
                    'order' => 'desc',
                    'limit' => $limit,
                ]
            );

            if (! $response->successful()) {
                $this->logError('OpenAI messages list failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            }

            $messages = $response->json('data', []);

            if (! is_array($messages)) {
                $this->logError('OpenAI messages list failed', [
                    'error' => 'Invalid messages response.',
                    'body' => $response->json(),
                ]);

                return null;
            }

            foreach ($messages as $message) {
                if (($message['role'] ?? null) !== 'assistant') {
                    continue;
                }

                $content = $message['content'] ?? [];
                $text = $this->extractTextFromContent($content);

                if ($text !== '') {
                    return $text;
                }
            }

            $this->logError('OpenAI messages list failed', [
                'error' => 'No assistant text message found.',
                'thread_id' => $threadId,
            ]);

            return null;
        } catch (Throwable $exception) {
            $this->logError('OpenAI messages list failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * List messages in a thread.
     */
    public function listMessages(string $threadId, array $query = []): ?array
    {
        if ($threadId === '') {
            $this->logError('OpenAI messages list failed', [
                'error' => 'Missing thread id.',
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/threads/' . $threadId . '/messages'), $query);

            return $this->parseJsonResponse($response, 'OpenAI messages list failed', [
                'thread_id' => $threadId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI messages list failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Retrieve a single message.
     */
    public function retrieveMessage(string $threadId, string $messageId): ?array
    {
        if ($threadId === '' || $messageId === '') {
            $this->logError('OpenAI message retrieve failed', [
                'error' => 'Missing thread id or message id.',
                'thread_id' => $threadId,
                'message_id' => $messageId,
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/threads/' . $threadId . '/messages/' . $messageId));

            return $this->parseJsonResponse($response, 'OpenAI message retrieve failed', [
                'thread_id' => $threadId,
                'message_id' => $messageId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI message retrieve failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create a thread and run in one request.
     */
    public function createThreadAndRun(string $assistantId, array $threadPayload = [], array $runPayload = []): ?array
    {
        if ($assistantId === '') {
            $this->logError('OpenAI thread run create failed', [
                'error' => 'Missing assistant id.',
            ]);

            return null;
        }

        $body = array_merge($runPayload, [
            'assistant_id' => $assistantId,
        ]);

        if (! empty($threadPayload)) {
            $body['thread'] = $threadPayload;
        }

        try {
            $response = $this->http()
                ->post($this->endpoint('/threads/runs'), $body);

            $data = $this->parseJsonResponse($response, 'OpenAI thread run create failed', [
                'payload' => $body,
            ]);

            if ($data === null) {
                return null;
            }

            $threadId = $data['thread_id'] ?? null;
            $runId = $data['id'] ?? null;

            if (! is_string($threadId) || $threadId === '' || ! is_string($runId) || $runId === '') {
                $this->logError('OpenAI thread run create failed', [
                    'error' => 'Missing run id or thread id in response.',
                    'body' => $data,
                ]);

                return null;
            }

            return [
                'thread_id' => $threadId,
                'run_id' => $runId,
                'run' => $data,
            ];
        } catch (Throwable $exception) {
            $this->logError('OpenAI thread run create failed', [
                'error' => $exception->getMessage(),
                'payload' => $body,
            ]);

            return null;
        }
    }

    /**
     * List runs for a thread.
     */
    public function listRuns(string $threadId, array $query = []): ?array
    {
        if ($threadId === '') {
            $this->logError('OpenAI runs list failed', [
                'error' => 'Missing thread id.',
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/threads/' . $threadId . '/runs'), $query);

            return $this->parseJsonResponse($response, 'OpenAI runs list failed', [
                'thread_id' => $threadId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI runs list failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cancel a run.
     */
    public function cancelRun(string $threadId, string $runId): ?array
    {
        if ($threadId === '' || $runId === '') {
            $this->logError('OpenAI run cancel failed', [
                'error' => 'Missing thread id or run id.',
                'thread_id' => $threadId,
                'run_id' => $runId,
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->post($this->endpoint('/threads/' . $threadId . '/runs/' . $runId . '/cancel'));

            return $this->parseJsonResponse($response, 'OpenAI run cancel failed', [
                'thread_id' => $threadId,
                'run_id' => $runId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI run cancel failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * List run steps for a run.
     */
    public function listRunSteps(string $threadId, string $runId, array $query = []): ?array
    {
        if ($threadId === '' || $runId === '') {
            $this->logError('OpenAI run steps list failed', [
                'error' => 'Missing thread id or run id.',
                'thread_id' => $threadId,
                'run_id' => $runId,
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/threads/' . $threadId . '/runs/' . $runId . '/steps'), $query);

            return $this->parseJsonResponse($response, 'OpenAI run steps list failed', [
                'thread_id' => $threadId,
                'run_id' => $runId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI run steps list failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Retrieve a run step.
     */
    public function retrieveRunStep(string $threadId, string $runId, string $stepId): ?array
    {
        if ($threadId === '' || $runId === '' || $stepId === '') {
            $this->logError('OpenAI run step retrieve failed', [
                'error' => 'Missing thread id, run id, or step id.',
                'thread_id' => $threadId,
                'run_id' => $runId,
                'step_id' => $stepId,
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/threads/' . $threadId . '/runs/' . $runId . '/steps/' . $stepId));

            return $this->parseJsonResponse($response, 'OpenAI run step retrieve failed', [
                'thread_id' => $threadId,
                'run_id' => $runId,
                'step_id' => $stepId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI run step retrieve failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Submit tool outputs to a run.
     */
    public function submitToolOutputs(string $threadId, string $runId, array $toolOutputs): ?array
    {
        if ($threadId === '' || $runId === '') {
            $this->logError('OpenAI submit tool outputs failed', [
                'error' => 'Missing thread id or run id.',
                'thread_id' => $threadId,
                'run_id' => $runId,
            ]);

            return null;
        }

        if (empty($toolOutputs)) {
            $this->logError('OpenAI submit tool outputs failed', [
                'error' => 'Missing tool outputs.',
                'thread_id' => $threadId,
                'run_id' => $runId,
            ]);

            return null;
        }

        $body = ['tool_outputs' => $toolOutputs];

        try {
            $response = $this->http()
                ->post($this->endpoint('/threads/' . $threadId . '/runs/' . $runId . '/submit_tool_outputs'), $body);

            return $this->parseJsonResponse($response, 'OpenAI submit tool outputs failed', [
                'thread_id' => $threadId,
                'run_id' => $runId,
                'payload' => $body,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI submit tool outputs failed', [
                'error' => $exception->getMessage(),
                'payload' => $body,
            ]);

            return null;
        }
    }

    /**
     * Submit tool outputs with streaming enabled.
     *
     * Returns raw stream content (SSE).
     */
    public function submitToolOutputsStream(string $threadId, string $runId, array $toolOutputs): ?string
    {
        if ($threadId === '' || $runId === '') {
            $this->logError('OpenAI submit tool outputs stream failed', [
                'error' => 'Missing thread id or run id.',
                'thread_id' => $threadId,
                'run_id' => $runId,
            ]);

            return null;
        }

        if (empty($toolOutputs)) {
            $this->logError('OpenAI submit tool outputs stream failed', [
                'error' => 'Missing tool outputs.',
                'thread_id' => $threadId,
                'run_id' => $runId,
            ]);

            return null;
        }

        $body = [
            'tool_outputs' => $toolOutputs,
            'stream' => true,
        ];

        try {
            $response = $this->httpStream()
                ->post($this->endpoint('/threads/' . $threadId . '/runs/' . $runId . '/submit_tool_outputs'), $body);

            if (! $response->successful()) {
                $this->logError('OpenAI submit tool outputs stream failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'payload' => $body,
                ]);

                return null;
            }

            return $response->body();
        } catch (Throwable $exception) {
            $this->logError('OpenAI submit tool outputs stream failed', [
                'error' => $exception->getMessage(),
                'payload' => $body,
            ]);

            return null;
        }
    }

    /**
     * Upload a file by path for use with assistants.
     */
    public function uploadFile(string $filePath, string $purpose = 'assistants', array $fields = []): ?string
    {
        if ($filePath === '' || ! is_file($filePath) || ! is_readable($filePath)) {
            $this->logError('OpenAI file upload failed', [
                'error' => 'File not found or not readable.',
                'file_path' => $filePath,
            ]);

            return null;
        }

        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            $this->logError('OpenAI file upload failed', [
                'error' => 'Unable to read file contents.',
                'file_path' => $filePath,
            ]);

            return null;
        }

        return $this->uploadFileContents($contents, basename($filePath), $purpose, $fields);
    }

    /**
     * Upload file contents for use with assistants.
     */
    public function uploadFileContents(
        string $contents,
        string $filename,
        string $purpose = 'assistants',
        array $fields = []
    ): ?string {
        if ($contents === '') {
            $this->logError('OpenAI file upload failed', [
                'error' => 'Empty file contents.',
            ]);

            return null;
        }

        if ($filename === '') {
            $this->logError('OpenAI file upload failed', [
                'error' => 'Missing filename.',
            ]);

            return null;
        }

        $payload = array_merge($fields, [
            'purpose' => $purpose,
        ]);

        try {
            $response = $this->httpForFiles()
                ->attach('file', $contents, $filename)
                ->post($this->endpoint('/files'), $payload);

            $data = $this->parseJsonResponse($response, 'OpenAI file upload failed', [
                'payload' => $payload,
                'filename' => $filename,
            ]);

            if ($data === null) {
                return null;
            }

            $id = $data['id'] ?? null;

            if (! is_string($id) || $id === '') {
                $this->logError('OpenAI file upload failed', [
                    'error' => 'Missing file id in response.',
                    'body' => $data,
                ]);

                return null;
            }

            return $id;
        } catch (Throwable $exception) {
            $this->logError('OpenAI file upload failed', [
                'error' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return null;
        }
    }

    /**
     * List uploaded files.
     */
    public function listFiles(array $query = []): ?array
    {
        try {
            $response = $this->http()
                ->get($this->endpoint('/files'), $query);

            return $this->parseJsonResponse($response, 'OpenAI files list failed');
        } catch (Throwable $exception) {
            $this->logError('OpenAI files list failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Retrieve file metadata.
     */
    public function retrieveFile(string $fileId): ?array
    {
        if ($fileId === '') {
            $this->logError('OpenAI file retrieve failed', [
                'error' => 'Missing file id.',
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/files/' . $fileId));

            return $this->parseJsonResponse($response, 'OpenAI file retrieve failed', [
                'file_id' => $fileId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI file retrieve failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Delete a file.
     */
    public function deleteFile(string $fileId): bool
    {
        if ($fileId === '') {
            $this->logError('OpenAI file delete failed', [
                'error' => 'Missing file id.',
            ]);

            return false;
        }

        try {
            $response = $this->http()
                ->delete($this->endpoint('/files/' . $fileId));

            $data = $this->parseJsonResponse($response, 'OpenAI file delete failed', [
                'file_id' => $fileId,
            ]);

            return (bool) ($data['deleted'] ?? false);
        } catch (Throwable $exception) {
            $this->logError('OpenAI file delete failed', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Download raw file content.
     */
    public function downloadFileContent(string $fileId): ?string
    {
        if ($fileId === '') {
            $this->logError('OpenAI file content download failed', [
                'error' => 'Missing file id.',
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/files/' . $fileId . '/content'));

            if (! $response->successful()) {
                $this->logError('OpenAI file content download failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->body();
        } catch (Throwable $exception) {
            $this->logError('OpenAI file content download failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create a vector store.
     */
    public function createVectorStore(array $payload = []): ?array
    {
        try {
            $response = $this->http()
                ->post($this->endpoint('/vector_stores'), $payload);

            return $this->parseJsonResponse($response, 'OpenAI vector store create failed', [
                'payload' => $payload,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store create failed', [
                'error' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return null;
        }
    }

    /**
     * List vector stores.
     */
    public function listVectorStores(array $query = []): ?array
    {
        try {
            $response = $this->http()
                ->get($this->endpoint('/vector_stores'), $query);

            return $this->parseJsonResponse($response, 'OpenAI vector stores list failed');
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector stores list failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Retrieve a vector store.
     */
    public function retrieveVectorStore(string $vectorStoreId): ?array
    {
        if ($vectorStoreId === '') {
            $this->logError('OpenAI vector store retrieve failed', [
                'error' => 'Missing vector store id.',
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/vector_stores/' . $vectorStoreId));

            return $this->parseJsonResponse($response, 'OpenAI vector store retrieve failed', [
                'vector_store_id' => $vectorStoreId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store retrieve failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Update a vector store.
     */
    public function updateVectorStore(string $vectorStoreId, array $payload): ?array
    {
        if ($vectorStoreId === '') {
            $this->logError('OpenAI vector store update failed', [
                'error' => 'Missing vector store id.',
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->post($this->endpoint('/vector_stores/' . $vectorStoreId), $payload);

            return $this->parseJsonResponse($response, 'OpenAI vector store update failed', [
                'vector_store_id' => $vectorStoreId,
                'payload' => $payload,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store update failed', [
                'error' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return null;
        }
    }

    /**
     * Delete a vector store.
     */
    public function deleteVectorStore(string $vectorStoreId): bool
    {
        if ($vectorStoreId === '') {
            $this->logError('OpenAI vector store delete failed', [
                'error' => 'Missing vector store id.',
            ]);

            return false;
        }

        try {
            $response = $this->http()
                ->delete($this->endpoint('/vector_stores/' . $vectorStoreId));

            $data = $this->parseJsonResponse($response, 'OpenAI vector store delete failed', [
                'vector_store_id' => $vectorStoreId,
            ]);

            return (bool) ($data['deleted'] ?? false);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store delete failed', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Search a vector store.
     */
    public function searchVectorStore(string $vectorStoreId, string|array $query, array $options = []): ?array
    {
        if ($vectorStoreId === '') {
            $this->logError('OpenAI vector store search failed', [
                'error' => 'Missing vector store id.',
            ]);

            return null;
        }

        $payload = array_merge($options, [
            'query' => $query,
        ]);

        try {
            $response = $this->http()
                ->post($this->endpoint('/vector_stores/' . $vectorStoreId . '/search'), $payload);

            return $this->parseJsonResponse($response, 'OpenAI vector store search failed', [
                'vector_store_id' => $vectorStoreId,
                'payload' => $payload,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store search failed', [
                'error' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return null;
        }
    }

    /**
     * Add a file to a vector store.
     */
    public function createVectorStoreFile(string $vectorStoreId, string $fileId, array $attributes = []): ?array
    {
        if ($vectorStoreId === '' || $fileId === '') {
            $this->logError('OpenAI vector store file create failed', [
                'error' => 'Missing vector store id or file id.',
                'vector_store_id' => $vectorStoreId,
                'file_id' => $fileId,
            ]);

            return null;
        }

        $payload = [
            'file_id' => $fileId,
        ];

        if (! empty($attributes)) {
            $payload['attributes'] = $attributes;
        }

        try {
            $response = $this->http()
                ->post($this->endpoint('/vector_stores/' . $vectorStoreId . '/files'), $payload);

            return $this->parseJsonResponse($response, 'OpenAI vector store file create failed', [
                'vector_store_id' => $vectorStoreId,
                'payload' => $payload,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store file create failed', [
                'error' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return null;
        }
    }

    /**
     * List vector store files.
     */
    public function listVectorStoreFiles(string $vectorStoreId, array $query = []): ?array
    {
        if ($vectorStoreId === '') {
            $this->logError('OpenAI vector store files list failed', [
                'error' => 'Missing vector store id.',
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/vector_stores/' . $vectorStoreId . '/files'), $query);

            return $this->parseJsonResponse($response, 'OpenAI vector store files list failed', [
                'vector_store_id' => $vectorStoreId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store files list failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Retrieve a vector store file.
     */
    public function retrieveVectorStoreFile(string $vectorStoreId, string $fileId): ?array
    {
        if ($vectorStoreId === '' || $fileId === '') {
            $this->logError('OpenAI vector store file retrieve failed', [
                'error' => 'Missing vector store id or file id.',
                'vector_store_id' => $vectorStoreId,
                'file_id' => $fileId,
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/vector_stores/' . $vectorStoreId . '/files/' . $fileId));

            return $this->parseJsonResponse($response, 'OpenAI vector store file retrieve failed', [
                'vector_store_id' => $vectorStoreId,
                'file_id' => $fileId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store file retrieve failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Delete a vector store file.
     */
    public function deleteVectorStoreFile(string $vectorStoreId, string $fileId): bool
    {
        if ($vectorStoreId === '' || $fileId === '') {
            $this->logError('OpenAI vector store file delete failed', [
                'error' => 'Missing vector store id or file id.',
                'vector_store_id' => $vectorStoreId,
                'file_id' => $fileId,
            ]);

            return false;
        }

        try {
            $response = $this->http()
                ->delete($this->endpoint('/vector_stores/' . $vectorStoreId . '/files/' . $fileId));

            $data = $this->parseJsonResponse($response, 'OpenAI vector store file delete failed', [
                'vector_store_id' => $vectorStoreId,
                'file_id' => $fileId,
            ]);

            return (bool) ($data['deleted'] ?? false);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store file delete failed', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Retrieve parsed vector store file content.
     */
    public function retrieveVectorStoreFileContent(string $vectorStoreId, string $fileId): ?array
    {
        if ($vectorStoreId === '' || $fileId === '') {
            $this->logError('OpenAI vector store file content retrieve failed', [
                'error' => 'Missing vector store id or file id.',
                'vector_store_id' => $vectorStoreId,
                'file_id' => $fileId,
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/vector_stores/' . $vectorStoreId . '/files/' . $fileId . '/content'));

            return $this->parseJsonResponse($response, 'OpenAI vector store file content retrieve failed', [
                'vector_store_id' => $vectorStoreId,
                'file_id' => $fileId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store file content retrieve failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create a vector store file batch.
     */
    public function createVectorStoreFileBatch(string $vectorStoreId, array $payload): ?array
    {
        if ($vectorStoreId === '') {
            $this->logError('OpenAI vector store file batch create failed', [
                'error' => 'Missing vector store id.',
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->post($this->endpoint('/vector_stores/' . $vectorStoreId . '/file_batches'), $payload);

            return $this->parseJsonResponse($response, 'OpenAI vector store file batch create failed', [
                'vector_store_id' => $vectorStoreId,
                'payload' => $payload,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store file batch create failed', [
                'error' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return null;
        }
    }

    /**
     * Retrieve a vector store file batch.
     */
    public function retrieveVectorStoreFileBatch(string $vectorStoreId, string $batchId): ?array
    {
        if ($vectorStoreId === '' || $batchId === '') {
            $this->logError('OpenAI vector store file batch retrieve failed', [
                'error' => 'Missing vector store id or batch id.',
                'vector_store_id' => $vectorStoreId,
                'batch_id' => $batchId,
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/vector_stores/' . $vectorStoreId . '/file_batches/' . $batchId));

            return $this->parseJsonResponse($response, 'OpenAI vector store file batch retrieve failed', [
                'vector_store_id' => $vectorStoreId,
                'batch_id' => $batchId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store file batch retrieve failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cancel a vector store file batch.
     */
    public function cancelVectorStoreFileBatch(string $vectorStoreId, string $batchId): ?array
    {
        if ($vectorStoreId === '' || $batchId === '') {
            $this->logError('OpenAI vector store file batch cancel failed', [
                'error' => 'Missing vector store id or batch id.',
                'vector_store_id' => $vectorStoreId,
                'batch_id' => $batchId,
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->post($this->endpoint('/vector_stores/' . $vectorStoreId . '/file_batches/' . $batchId . '/cancel'));

            return $this->parseJsonResponse($response, 'OpenAI vector store file batch cancel failed', [
                'vector_store_id' => $vectorStoreId,
                'batch_id' => $batchId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store file batch cancel failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * List vector store files in a batch.
     */
    public function listVectorStoreFileBatchFiles(string $vectorStoreId, string $batchId, array $query = []): ?array
    {
        if ($vectorStoreId === '' || $batchId === '') {
            $this->logError('OpenAI vector store file batch files list failed', [
                'error' => 'Missing vector store id or batch id.',
                'vector_store_id' => $vectorStoreId,
                'batch_id' => $batchId,
            ]);

            return null;
        }

        try {
            $response = $this->http()
                ->get($this->endpoint('/vector_stores/' . $vectorStoreId . '/file_batches/' . $batchId . '/files'), $query);

            return $this->parseJsonResponse($response, 'OpenAI vector store file batch files list failed', [
                'vector_store_id' => $vectorStoreId,
                'batch_id' => $batchId,
            ]);
        } catch (Throwable $exception) {
            $this->logError('OpenAI vector store file batch files list failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Attach vector store(s) to an assistant for file_search.
     */
    public function attachVectorStoreToAssistant(string $assistantId, string|array $vectorStoreIds): bool
    {
        if ($assistantId === '') {
            $this->logError('OpenAI assistant attach vector store failed', [
                'error' => 'Missing assistant id.',
            ]);

            return false;
        }

        $ids = is_array($vectorStoreIds) ? $vectorStoreIds : [$vectorStoreIds];
        $ids = array_values(array_filter($ids, fn ($id) => is_string($id) && $id !== ''));

        if (empty($ids)) {
            $this->logError('OpenAI assistant attach vector store failed', [
                'error' => 'Missing vector store ids.',
            ]);

            return false;
        }

        return $this->updateAssistant($assistantId, [
            'tool_resources' => [
                'file_search' => [
                    'vector_store_ids' => $ids,
                ],
            ],
        ]);
    }

    private function http()
    {
        $headers = $this->baseHeaders();
        $headers['Content-Type'] = 'application/json';

        return Http::withHeaders($headers)->timeout(30);
    }

    private function httpForFiles()
    {
        return Http::withHeaders($this->baseHeaders())->timeout(60);
    }

    private function httpStream()
    {
        $headers = $this->baseHeaders();
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'text/event-stream';

        return Http::withHeaders($headers)->withOptions(['stream' => true])->timeout(0);
    }

    private function baseHeaders(): array
    {
        $betaHeader = $this->config['beta'] ?? 'assistants=v2';
        if (! is_string($betaHeader) || trim($betaHeader) === '') {
            $betaHeader = 'assistants=v2';
        }

        $headers = [
            'Authorization' => 'Bearer ' . ($this->config['api_key'] ?? ''),
            'OpenAI-Beta' => $betaHeader,
        ];

        if (! empty($this->config['organization'])) {
            $headers['OpenAI-Organization'] = $this->config['organization'];
        }

        if (! empty($this->config['project'])) {
            $headers['OpenAI-Project'] = $this->config['project'];
        }

        return $headers;
    }

    private function endpoint(string $path): string
    {
        $base = rtrim($this->config['base_url'] ?? 'https://api.openai.com/v1', '/');
        $path = '/' . ltrim($path, '/');

        return $base . $path;
    }

    /**
     * Build attachments payload for file-based tools.
     */
    private function buildAttachments(array $fileIds, array $toolTypes): array
    {
        $tools = [];

        foreach ($toolTypes as $toolType) {
            if (! is_string($toolType) || $toolType === '') {
                continue;
            }

            $tools[] = ['type' => $toolType];
        }

        $attachments = [];

        foreach ($fileIds as $fileId) {
            if (! is_string($fileId) || $fileId === '') {
                continue;
            }

            $attachments[] = [
                'file_id' => $fileId,
                'tools' => $tools,
            ];
        }

        return $attachments;
    }

    /**
     * Extract plain text from message content parts.
     */
    private function extractTextFromContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        $chunks = [];

        foreach ($content as $part) {
            if (! is_array($part)) {
                continue;
            }

            if (($part['type'] ?? null) !== 'text') {
                continue;
            }

            $value = $part['text']['value'] ?? null;

            if (is_string($value) && $value !== '') {
                $chunks[] = $value;
            }
        }

        return trim(implode("\n", $chunks));
    }

    private function parseJsonResponse(Response $response, string $title, array $context = []): ?array
    {
        if (! $response->successful()) {
            $this->logError($title, array_merge($context, [
                'status' => $response->status(),
                'body' => $response->json(),
            ]));

            return null;
        }

        $data = $response->json();

        if (! is_array($data)) {
            $this->logError($title, array_merge($context, [
                'error' => 'Invalid JSON response.',
                'body' => $response->body(),
            ]));

            return null;
        }

        return $data;
    }

    private function logError(string $title, array $data): void
    {
        try {
            OpenAiLog::create([
                'title' => $title,
                'content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $exception) {
            // Logging should not break main flow.
        }
    }

    /**
     * Normalize assistant payload fields that are strict-typed by OpenAI API.
     */
    private function normalizeAssistantPayload(array $payload): array
    {
        if (array_key_exists('temperature', $payload)) {
            if (is_numeric($payload['temperature'])) {
                $payload['temperature'] = (float) $payload['temperature'];
            } else {
                unset($payload['temperature']);
            }
        }

        if (array_key_exists('top_p', $payload)) {
            if (is_numeric($payload['top_p'])) {
                $payload['top_p'] = (float) $payload['top_p'];
            } else {
                unset($payload['top_p']);
            }
        }

        if (array_key_exists('response_format', $payload)) {
            $responseFormat = $payload['response_format'];

            if (is_string($responseFormat)) {
                $responseFormat = trim($responseFormat);
                if ($responseFormat === '') {
                    unset($payload['response_format']);
                } else {
                    $payload['response_format'] = $responseFormat;
                }
            } elseif (! is_array($responseFormat)) {
                unset($payload['response_format']);
            }
        }

        return $payload;
    }
}
