<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider (Penyelidik Insiden)
    |--------------------------------------------------------------------------
    |
    | Provider, base URL, model, dan API key diatur dari menu Pengaturan dan
    | disimpan di database (App\Services\Monitoring\AiSettings). Nilai di sini
    | hanya batas waktu dan default bila belum pernah disimpan.
    |
    | Dua jenis provider didukung:
    |  - anthropic : Claude API (atau proxy yang kompatibel).
    |  - openai    : endpoint kompatibel OpenAI /chat/completions (OpenAI,
    |                OpenRouter, LiteLLM, vLLM, Ollama, dsb).
    |
    */
    'default_model' => 'claude-haiku-4-5',
    'anthropic_base_url' => 'https://api.anthropic.com',

    // Batas waktu per request (detik) dan jumlah retry. Analisis insiden berjalan
    // di dalam request web, jadi total terburuk ~ timeout x (1 + max_retries)
    // harus tetap di bawah max_execution_time PHP.
    'timeout' => (float) env('AI_TIMEOUT', 60),
    'max_retries' => (int) env('AI_MAX_RETRIES', 1),

    // Batas atas jeda retry (detik): header retry-after dari API dipotong ke nilai ini.
    'max_retry_delay' => (float) env('AI_MAX_RETRY_DELAY', 10),

];
