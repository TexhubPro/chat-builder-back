<?php

namespace TexHub\AlifBank\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use TexHub\AlifBank\Client;
use TexHub\AlifBank\Models\AlifBankLog;
use Throwable;

class AlifBankController extends Controller
{
    public function callback(Request $request, Client $client)
    {
        $payload = $request->all();
        $valid = $client->verifyCallback($payload);

        $this->storeCallbackLog($payload, $valid);

        return response()->json([
            'ok' => $valid,
        ]);
    }

    private function storeCallbackLog(array $payload, bool $valid): void
    {
        try {
            AlifBankLog::create([
                'title' => $valid ? 'AlifBank callback OK' : 'AlifBank callback invalid',
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $exception) {
            // ignore
        }
    }
}
