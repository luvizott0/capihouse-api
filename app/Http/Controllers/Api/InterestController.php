<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InterestResource;
use App\Models\Interest;
use Illuminate\Http\Request;

class InterestController extends Controller
{
    public function index()
    {
        return InterestResource::collection(Interest::orderBy('name')->get());
    }

    public function sync(Request $request)
    {
        $request->validate([
            'interests' => ['required', 'array'],
            'interests.*' => ['required', 'string', 'max:50'],
        ]);

        $interestIds = [];
        foreach ($request->input('interests') as $interestName) {
            $interest = Interest::firstOrCreate(['name' => strtolower($interestName)]);
            $interestIds[] = $interest->id;
        }

        auth()->user()->interests()->sync($interestIds);

        return InterestResource::collection(auth()->user()->interests);
    }
}
