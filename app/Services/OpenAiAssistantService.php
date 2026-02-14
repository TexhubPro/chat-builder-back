<?php

namespace App\Services;

use App\Models\Assistant as AssistantModel;
use App\Models\AssistantInstructionFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use TexHub\OpenAi\Assistant as OpenAiAssistantClient;

class OpenAiAssistantService
{
    public function __construct(private OpenAiAssistantClient $client) {}

    public function isConfigured(): bool
    {
        $apiKey = config('openai.assistant.api_key');

        return is_string($apiKey) && trim($apiKey) !== '';
    }

    public function syncAssistant(AssistantModel $assistant): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        if ($assistant->enable_file_search && ! $assistant->openai_vector_store_id) {
            $vectorStoreId = $this->createVectorStore($assistant->name);

            if ($vectorStoreId) {
                $assistant->forceFill([
                    'openai_vector_store_id' => $vectorStoreId,
                ])->save();
            }
        }

        $assistant->refresh();

        if ($assistant->enable_file_search && $assistant->openai_vector_store_id) {
            $this->syncInstructionFilesToVectorStore($assistant, (string) $assistant->openai_vector_store_id);
            $assistant->refresh();
        }

        $payload = $this->buildAssistantPayload($assistant);

        if (! $assistant->openai_assistant_id) {
            $assistantId = $this->client->createAssistant($payload);

            if (! is_string($assistantId) || $assistantId === '') {
                throw new RuntimeException('OpenAI assistant sync failed during create.');
            }

            $assistant->forceFill([
                'openai_assistant_id' => $assistantId,
            ])->save();

            return;
        }

        $updated = $this->client->updateAssistant($assistant->openai_assistant_id, $payload);

        if ($updated) {
            return;
        }

        // Recover from stale/removed remote assistant id by creating a new assistant.
        $newAssistantId = $this->client->createAssistant($payload);

        if (! is_string($newAssistantId) || $newAssistantId === '') {
            throw new RuntimeException('OpenAI assistant sync failed during update.');
        }

        $assistant->forceFill([
            'openai_assistant_id' => $newAssistantId,
        ])->save();
    }

    public function uploadInstructionFile(
        AssistantModel $assistant,
        AssistantInstructionFile $instructionFile,
        string $absolutePath
    ): void {
        if (! $this->isConfigured()) {
            return;
        }

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Instruction file is missing on disk.');
        }

        $openAiFileId = $this->client->uploadFile($absolutePath, 'assistants');

        if (! is_string($openAiFileId) || $openAiFileId === '') {
            throw new RuntimeException('OpenAI instruction file upload failed.');
        }

        $instructionFile->forceFill([
            'openai_file_id' => $openAiFileId,
        ])->save();

        if ($assistant->enable_file_search) {
            $assistant->refresh();
            $vectorStoreId = $assistant->openai_vector_store_id;

            if (! $vectorStoreId) {
                $vectorStoreId = $this->createVectorStore($assistant->name);

                if ($vectorStoreId) {
                    $assistant->forceFill([
                        'openai_vector_store_id' => $vectorStoreId,
                    ])->save();
                }
            }

            if ($vectorStoreId) {
                $this->attachInstructionFileToVectorStore(
                    $assistant,
                    $instructionFile->fresh(),
                    (string) $vectorStoreId
                );
            }
        }

        $assistant->refresh();
        $this->syncAssistant($assistant);
    }

    public function deleteInstructionFile(AssistantInstructionFile $instructionFile): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        if (! $instructionFile->openai_file_id) {
            return;
        }

        $this->client->deleteFile((string) $instructionFile->openai_file_id);
    }

    public function cleanupAssistant(AssistantModel $assistant): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        if ($assistant->openai_vector_store_id) {
            $this->client->deleteVectorStore((string) $assistant->openai_vector_store_id);
        }
    }

    private function buildAssistantPayload(AssistantModel $assistant): array
    {
        $settings = is_array($assistant->settings) ? $assistant->settings : [];
        $tools = [];

        if ($assistant->enable_file_search) {
            $tools[] = ['type' => 'file_search'];
        }

        if ($assistant->enable_file_analysis) {
            $tools[] = ['type' => 'code_interpreter'];
        }

        $payload = [
            'name' => $assistant->name,
            'model' => (string) config('openai.assistant.defaults.model', 'gpt-4o'),
            'instructions' => $this->composeInstructions($assistant, $settings),
        ];

        $temperature = config('openai.assistant.defaults.temperature');
        if (is_numeric($temperature)) {
            $payload['temperature'] = (float) $temperature;
        }

        $topP = config('openai.assistant.defaults.top_p');
        if (is_numeric($topP)) {
            $payload['top_p'] = (float) $topP;
        }

        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        if ($assistant->enable_file_search && $assistant->openai_vector_store_id) {
            $payload['tool_resources'] = [
                'file_search' => [
                    'vector_store_ids' => [$assistant->openai_vector_store_id],
                ],
            ];
        }

        return $payload;
    }

    private function composeInstructions(AssistantModel $assistant, array $settings): string
    {
        $parts = [];

        $baseInstructions = trim((string) config('openai.assistant.base_instructions', ''));
        if ($baseInstructions !== '') {
            $parts[] = $baseInstructions;
        }

        $baseLimits = trim((string) config('openai.assistant.base_limits', ''));
        if ($baseLimits !== '') {
            $parts[] = $baseLimits;
        }

        $parts[] = implode("\n", [
            'Assistant context:',
            '- Assistant ID: '.(string) $assistant->id,
            '- Company ID: '.(string) $assistant->company_id,
            '- Assistant name: '.$assistant->name,
        ]);

        $parts[] = 'Conversation tone: '.$this->toneLabel((string) $assistant->conversation_tone).'.';

        $instructions = trim((string) $assistant->instructions);
        if ($instructions !== '') {
            $parts[] = "Main instructions:\n".$instructions;
        }

        $triggers = $this->normalizeTriggers($settings['triggers'] ?? []);
        if ($triggers !== []) {
            $lines = ['Trigger-response rules:'];

            foreach ($triggers as $pair) {
                $lines[] = '- Trigger: "'.$pair['trigger'].'" => Response: "'.$pair['response'].'"';
            }

            $parts[] = implode("\n", $lines);
        }

        $parts[] = $this->catalogSection($assistant);

        $restrictions = trim((string) $assistant->restrictions);
        if ($restrictions !== '') {
            $parts[] = "Restrictions:\n".$restrictions;
        }

        $parts[] = implode("\n", [
            'Tool settings:',
            '- File search: '.$this->boolLabel((bool) $assistant->enable_file_search),
            '- File analysis: '.$this->boolLabel((bool) $assistant->enable_file_analysis),
            '- Voice mode: '.$this->boolLabel((bool) $assistant->enable_voice),
            '- Web search: '.$this->boolLabel((bool) $assistant->enable_web_search),
        ]);

        return implode("\n\n", $parts);
    }

    private function normalizeTriggers(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $trigger = trim((string) ($row['trigger'] ?? ''));
            $response = trim((string) ($row['response'] ?? ''));

            if ($trigger === '' || $response === '') {
                continue;
            }

            $normalized[] = [
                'trigger' => mb_substr($trigger, 0, 300),
                'response' => mb_substr($response, 0, 2000),
            ];
        }

        return $normalized;
    }

    private function createVectorStore(string $assistantName): ?string
    {
        $vectorStore = $this->client->createVectorStore([
            'name' => trim($assistantName) !== '' ? trim($assistantName).' Knowledge Base' : 'Assistant Knowledge Base',
        ]);

        $id = $vectorStore['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function toneLabel(string $tone): string
    {
        return match ($tone) {
            AssistantModel::TONE_CONCISE => 'concise and to the point',
            AssistantModel::TONE_FRIENDLY => 'friendly and warm',
            AssistantModel::TONE_FORMAL => 'formal and professional',
            AssistantModel::TONE_CUSTOM => 'custom',
            default => 'polite and helpful',
        };
    }

    private function catalogSection(AssistantModel $assistant): string
    {
        $services = $assistant->services()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'description',
                'terms_conditions',
                'price',
                'currency',
                'metadata',
            ]);

        $products = $assistant->products()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'sku',
                'description',
                'terms_conditions',
                'price',
                'currency',
                'stock_quantity',
                'is_unlimited_stock',
                'metadata',
            ]);

        $lines = [
            'Company catalog:',
            '- Assistant ID: '.(string) $assistant->id,
            '- Company ID: '.(string) $assistant->company_id,
            'Services:',
        ];

        if ($services->isEmpty()) {
            $lines[] = '- none';
        } else {
            foreach ($services as $service) {
                $specialistsLine = $this->specialistsSummary($service->metadata, $service->price, $service->currency);
                $lines[] = sprintf(
                    '- [Service #%d] %s | price: %s %s | specialists: %s | description: %s | terms: %s',
                    (int) $service->id,
                    $this->cleanText((string) $service->name, 160),
                    $this->moneyValue($service->price),
                    (string) $service->currency,
                    $specialistsLine,
                    $this->cleanText((string) ($service->description ?? ''), 300),
                    $this->cleanText((string) ($service->terms_conditions ?? ''), 300),
                );
            }
        }

        $lines[] = 'Products:';

        if ($products->isEmpty()) {
            $lines[] = '- none';
        } else {
            foreach ($products as $product) {
                $stockLabel = (bool) $product->is_unlimited_stock
                    ? 'unlimited'
                    : (string) max((int) ($product->stock_quantity ?? 0), 0);
                $productLink = $this->productUrlFromMetadata($product->metadata);

                $lines[] = sprintf(
                    '- [Product #%d] %s | sku: %s | price: %s %s | stock: %s | link: %s | description: %s | terms: %s',
                    (int) $product->id,
                    $this->cleanText((string) $product->name, 160),
                    $this->cleanText((string) ($product->sku ?? 'n/a'), 120),
                    $this->moneyValue($product->price),
                    (string) $product->currency,
                    $stockLabel,
                    $productLink,
                    $this->cleanText((string) ($product->description ?? ''), 300),
                    $this->cleanText((string) ($product->terms_conditions ?? ''), 300),
                );
            }
        }

        return implode("\n", $lines);
    }

    private function syncInstructionFilesToVectorStore(AssistantModel $assistant, string $vectorStoreId): void
    {
        $assistant->loadMissing('instructionFiles');

        foreach ($assistant->instructionFiles as $instructionFile) {
            $openAiFileId = $this->ensureOpenAiFileIdForInstructionFile($instructionFile);

            if ($openAiFileId === null) {
                continue;
            }

            $this->attachInstructionFileToVectorStore($assistant, $instructionFile, $vectorStoreId);
        }
    }

    private function ensureOpenAiFileIdForInstructionFile(
        AssistantInstructionFile $instructionFile
    ): ?string {
        $existingOpenAiFileId = trim((string) ($instructionFile->openai_file_id ?? ''));
        if ($existingOpenAiFileId !== '') {
            return $existingOpenAiFileId;
        }

        $disk = trim((string) ($instructionFile->disk ?? ''));
        $relativePath = trim((string) ($instructionFile->path ?? ''));

        if ($disk === '' || $relativePath === '') {
            return null;
        }

        $storage = Storage::disk($disk);
        if (! $storage->exists($relativePath)) {
            return null;
        }

        $absolutePath = $storage->path($relativePath);
        if (! is_file($absolutePath)) {
            return null;
        }

        $openAiFileId = $this->client->uploadFile($absolutePath, 'assistants');
        if (! is_string($openAiFileId) || trim($openAiFileId) === '') {
            throw new RuntimeException(
                'OpenAI instruction file upload failed for existing file #'.$instructionFile->id
            );
        }

        $instructionFile->forceFill([
            'openai_file_id' => $openAiFileId,
        ])->save();

        return $openAiFileId;
    }

    private function attachInstructionFileToVectorStore(
        AssistantModel $assistant,
        AssistantInstructionFile $instructionFile,
        string $vectorStoreId
    ): void {
        $metadata = is_array($instructionFile->metadata) ? $instructionFile->metadata : [];
        $alreadySyncedVectorStoreId = trim((string) ($metadata['openai_vector_store_id'] ?? ''));

        if ($alreadySyncedVectorStoreId === $vectorStoreId) {
            return;
        }

        $vectorStoreFile = $this->client->createVectorStoreFile(
            $vectorStoreId,
            (string) $instructionFile->openai_file_id,
            [
                'assistant_id' => (string) $assistant->id,
                'company_id' => (string) $assistant->company_id,
                'instruction_file_id' => (string) $instructionFile->id,
            ]
        );

        if (! is_array($vectorStoreFile)) {
            throw new RuntimeException(
                'OpenAI vector store file attach failed for instruction file #'.$instructionFile->id
            );
        }

        $metadata['openai_vector_store_id'] = $vectorStoreId;
        $metadata['openai_vector_store_file_id'] = (string) ($vectorStoreFile['id'] ?? '');
        $metadata['openai_vector_store_synced_at'] = now()->toIso8601String();

        $instructionFile->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    private function boolLabel(bool $value): string
    {
        return $value ? 'enabled' : 'disabled';
    }

    private function specialistsSummary(mixed $metadata, mixed $fallbackPrice, mixed $currency): string
    {
        if (! is_array($metadata)) {
            return 'none';
        }

        $specialists = $metadata['specialists'] ?? null;
        if (! is_array($specialists) || $specialists === []) {
            return 'none';
        }

        $fallbackPriceValue = $this->moneyValue($fallbackPrice);
        $currencyValue = is_string($currency) && trim($currency) !== '' ? trim($currency) : 'TJS';
        $parts = [];

        foreach ($specialists as $specialist) {
            if (! is_array($specialist)) {
                continue;
            }

            $name = $this->cleanText((string) ($specialist['name'] ?? ''), 100);
            $price = array_key_exists('price', $specialist)
                ? $this->moneyValue($specialist['price'])
                : $fallbackPriceValue;

            $parts[] = "{$name} ({$price} {$currencyValue})";
        }

        if ($parts === []) {
            return 'none';
        }

        return implode(', ', $parts);
    }

    private function productUrlFromMetadata(mixed $metadata): string
    {
        if (! is_array($metadata)) {
            return 'n/a';
        }

        $url = trim((string) ($metadata['product_url'] ?? ''));

        if ($url === '') {
            return 'n/a';
        }

        return $this->cleanText($url, 300);
    }

    private function cleanText(string $value, int $limit): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($normalized === '') {
            return 'n/a';
        }

        return Str::limit($normalized, $limit, '...');
    }

    private function moneyValue(mixed $value): string
    {
        $numeric = is_numeric($value) ? (float) $value : 0.0;

        return number_format($numeric, 2, '.', '');
    }
}
