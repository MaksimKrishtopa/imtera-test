<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\YandexMapsParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function __construct(private YandexMapsParser $parser)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $org = $request->user()->organization;

        if (!$org) {
            return response()->json(null);
        }

        return response()->json($this->formatOrganization($org));
    }

    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'regex:#yandex\.(ru|com|by|kz|uz)#i'],
        ], [
            'url.regex' => 'Ссылка должна быть на Яндекс.Карты.',
        ]);

        $user = $request->user();
        $org = $user->organization;

        if ($org) {
            $org->update([
                'url' => $validated['url'],
                'parse_status' => 'pending',
                'parse_error' => null,
                'parsed_at' => null,
                'yandex_id' => null,
                'name' => null,
                'rating' => null,
                'reviews_count' => null,
                'ratings_count' => null,
            ]);
            $org->reviews()->delete();
        } else {
            $org = $user->organizations()->create([
                'url' => $validated['url'],
                'parse_status' => 'pending',
            ]);
        }

        return response()->json($this->formatOrganization($org), 201);
    }

    public function parse(Request $request): JsonResponse
    {
        $org = $request->user()->organization;

        if (!$org) {
            return response()->json(['message' => 'Организация не настроена.'], 404);
        }

        if ($org->parse_status === 'processing') {
            return response()->json(['message' => 'Парсинг уже выполняется.'], 409);
        }

        try {
            $this->parser->parse($org);
            $org->refresh();
            return response()->json($this->formatOrganization($org));
        } catch (\Throwable $e) {
            $org->refresh();
            return response()->json([
                'message' => $e->getMessage(),
                'organization' => $this->formatOrganization($org),
            ], 422);
        }
    }

    private function formatOrganization(Organization $org): array
    {
        return [
            'id' => $org->id,
            'url' => $org->url,
            'yandex_id' => $org->yandex_id,
            'name' => $org->name,
            'rating' => $org->rating,
            'reviews_count' => $org->reviews_count,
            'ratings_count' => $org->ratings_count,
            'parse_status' => $org->parse_status,
            'parse_error' => $org->parse_error,
            'parsed_at' => $org->parsed_at?->toISOString(),
        ];
    }
}
