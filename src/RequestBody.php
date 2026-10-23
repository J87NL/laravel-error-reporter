<?php

declare(strict_types=1);

namespace J87NL\ErrorReporter;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class RequestBody
{
    private const int MAX_DEPTH = 8;

    /**
     * @var list<string>
     */
    public const array DEFAULT_EXCLUDE = [
        'login',
        '*/login',
        'logout',
        'register',
        '*/register',
        'forgot-password',
        '*/forgot-password',
        'reset-password*',
        '*/reset-password*',
        'confirm-password',
        'password/*',
        'password.*',
        'user/password',
        'user-password.*',
        'user/confirm-password',
        'user/confirmed-password-status',
        'two-factor*',
        '*/two-factor*',
        'two-factor.*',
        'user/two-factor*',
        'settings/password',
        'settings/two-factor*',
    ];

    public function __construct(
        private readonly Scrubber $scrubber,
        private readonly ValueRedactor $redactor,
    ) {}

    /**
     * @return array<array-key, mixed>|null
     */
    public function fromRequest(Request $request): ?array
    {
        if (! config('error-reporter.include_request_body', true) || $this->isExcluded($request)) {
            return null;
        }

        $body = array_replace_recursive($this->plain($this->input($request)), $this->files($request->allFiles()));

        if ($body === []) {
            return null;
        }

        $redacted = $this->redactor->redact($this->scrubber->scrub($body));
        $redacted = is_array($redacted) ? $redacted : [];

        if (strlen((string) json_encode($redacted, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)) > (int) config('error-reporter.max_request_body_bytes', 16384)) {
            return [
                'truncated' => true,
            ];
        }

        return $redacted;
    }

    private function isExcluded(Request $request): bool
    {
        $patterns = config('error-reporter.request_body_exclude', self::DEFAULT_EXCLUDE);

        foreach (is_array($patterns) ? $patterns : [] as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            if ($request->is(ltrim($pattern, '/')) || $request->routeIs($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function input(Request $request): array
    {
        if ($request->isJson()) {
            return $request->json()->all();
        }

        return $request->request->all();
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function plain(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $encoded = json_encode($values, JSON_INVALID_UTF8_SUBSTITUTE, self::MAX_DEPTH);
        $plain = is_string($encoded) ? json_decode($encoded, true) : null;

        return is_array($plain) ? $plain : [
            'truncated' => true,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array<array-key, mixed>
     */
    private function files(array $files): array
    {
        $described = [];

        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                $size = $file->getSize();

                $described[$key] = [
                    'name' => $file->getClientOriginalName(),
                    'size' => is_int($size) ? $size : null,
                    'mime' => $file->getClientMimeType(),
                ];

                continue;
            }

            if (is_array($file)) {
                $described[$key] = $this->files($file);
            }
        }

        return $described;
    }
}
