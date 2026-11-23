<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cafe\FavouriteRequest;
use App\Models\Cafe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CafeFavouriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $favourites = $request->user()
            ->favourites()
            ->whereHas('cafe', fn ($query) => $query->where('status', 'approved'))
            ->with('cafe')
            ->latest()
            ->get();

        return response()->json(['favourites' => $favourites]);
    }

    public function store(FavouriteRequest $request): JsonResponse
    {
        $cafe = Cafe::approved()->find($request->validated('cafe_id'));

        if (! $cafe) {
            return response()->json(['message' => 'This café is not available for favouriting.'], 404);
        }

        $favourite = $request->user()->favourites()->firstOrCreate(['cafe_id' => $cafe->id]);

        return response()->json([
            'message' => 'Café added to favourites.',
            'favourite' => $favourite->setRelation('cafe', $cafe),
        ], $favourite->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $cafeId): JsonResponse
    {
        $deleted = $request->user()->favourites()->where('cafe_id', $cafeId)->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Favourite café not found.'], 404);
        }

        return response()->json(['message' => 'Café removed from favourites.']);
    }
}
