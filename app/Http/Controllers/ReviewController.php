<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->organization;

        if (!$org) {
            return response()->json(['message' => 'Организация не настроена.'], 404);
        }

        if ($org->parse_status !== 'done') {
            return response()->json(['message' => 'Отзывы ещё не загружены.'], 404);
        }

        $perPage = 50;
        $page = max(1, (int) $request->query('page', 1));

        $reviews = $org->reviews()
            ->select(['id', 'author_name', 'author_avatar', 'rating', 'text', 'reviewed_at'])
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $reviews->items(),
            'total' => $reviews->total(),
            'per_page' => $reviews->perPage(),
            'current_page' => $reviews->currentPage(),
            'last_page' => $reviews->lastPage(),
        ]);
    }
}
