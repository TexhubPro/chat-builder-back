<?php

namespace TexHub\Meta\Console;

use Illuminate\Console\Command;

class InstallMetaCommand extends Command
{
    protected $signature = 'texhub:meta-install';
    protected $description = 'Install TexHub Meta package (publish config, run migrations, and update .env).';

    public function handle(): int
    {
        $this->info('Installing TexHub Meta...');

        $this->call('vendor:publish', [
            '--tag' => 'texhub-meta-config',
            '--force' => true,
        ]);

        $this->call('migrate', [
            '--force' => true,
        ]);

        $this->updateEnv();

        $this->info('TexHub Meta installed.');

        return self::SUCCESS;
    }

    private function updateEnv(): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->warn('.env not found, skipping env update.');
            return;
        }

        $content = file_get_contents($envPath);
        if ($content === false) {
            $this->warn('Unable to read .env, skipping env update.');
            return;
        }

        $entries = [
            'META_INSTAGRAM_APP_ID' => '',
            'META_INSTAGRAM_APP_SECRET' => '',
            'META_INSTAGRAM_VERIFY_TOKEN' => '',
            'META_INSTAGRAM_REDIRECT_PATH' => '/callback',
            'META_INSTAGRAM_REDIRECT_URI' => '',
            'META_INSTAGRAM_WEBHOOK_PATH' => '/instagram-main-webhook',
            'META_INSTAGRAM_SCOPES' => 'instagram_basic,instagram_manage_messages,pages_show_list,pages_messaging',
            'META_INSTAGRAM_GRAPH_BASE' => 'https://graph.instagram.com',
            'META_INSTAGRAM_API_VERSION' => 'v20.0',
            'META_INSTAGRAM_AUTH_URL' => '',
            'META_INSTAGRAM_AUTO_REPLY_ENABLED' => 'true',
            'META_INSTAGRAM_VOICE_REPLY_FOR_AUDIO' => 'true',
        ];

        $lines = [];
        foreach ($entries as $key => $default) {
            if (preg_match('/^' . preg_quote($key, '/') . '=/m', $content)) {
                continue;
            }

            $value = $default;
            $lines[] = $key . '=' . $value;
        }

        if (empty($lines)) {
            return;
        }

        $content = rtrim($content) . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL;
        file_put_contents($envPath, $content);
    }
}
