<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trips\CrewTripAccessPolicy;
use App\Http\Controllers\Controller;
use App\Models\CrewMessage;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CrewMessageController extends Controller
{
    public function index(Request $request, Trip $trip)
    {
        $this->assertCrewVehicleAccess($request, $trip);

        $messages = CrewMessage::with('crewMember')
            ->where('trip_id', $trip->id)
            ->latest()
            ->get()
            ->map(fn (CrewMessage $message) => $this->messagePayload($message));

        return response()->json([
            'messages' => $messages,
        ]);
    }

    public function store(Request $request, Trip $trip)
    {
        $this->assertCrewVehicleAccess($request, $trip);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:text,voice'],
            'body' => ['nullable', 'string', 'max:4000'],
            'audio' => ['nullable', 'file', 'max:15360'],
            'metadata' => ['nullable', 'array'],
        ]);

        $crewMember = $request->user();
        $audioPath = null;

        if ($validated['type'] === 'voice') {
            if (! $request->hasFile('audio')) {
                return response()->json([
                    'message' => 'Le message vocal nécessite un fichier audio.',
                ], 422);
            }

            $audio = $request->file('audio');
            $audioPath = $audio->storeAs(
                'crew-voice-notes/'.$trip->id,
                Str::uuid().'.'.$audio->getClientOriginalExtension(),
                'local'
            );
        }

        $message = CrewMessage::create([
            'trip_id' => $trip->id,
            'crew_member_id' => $crewMember->id,
            'type' => $validated['type'],
            'body' => $validated['body'] ?? null,
            'audio_path' => $audioPath,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json([
            'message' => 'Message publié.',
            'data' => $this->messagePayload($message->load('crewMember')),
        ], 201);
    }

    public function destroy(Request $request, Trip $trip, CrewMessage $message)
    {
        $this->assertCrewVehicleAccess($request, $trip);
        abort_unless($message->trip_id === $trip->id, 404);

        $crewMember = $request->user();
        abort_unless($message->crew_member_id === $crewMember->id, 403, 'Vous ne pouvez supprimer que vos propres messages.');

        if ($message->audio_path) {
            Storage::disk('local')->delete($message->audio_path);
        }

        $message->delete();

        return response()->json([
            'message' => 'Message supprimé.',
        ]);
    }

    public function audio(Request $request, Trip $trip, CrewMessage $message)
    {
        $this->assertCrewVehicleAccess($request, $trip);
        abort_unless($message->trip_id === $trip->id, 404);
        abort_unless($message->audio_path && Storage::disk('local')->exists($message->audio_path), 404);

        return Storage::disk('local')->download($message->audio_path);
    }

    private function messagePayload(CrewMessage $message): array
    {
        return [
            'id' => $message->id,
            'trip_id' => $message->trip_id,
            'crew_member' => $message->crewMember ? [
                'id' => $message->crewMember->id,
                'name' => $message->crewMember->name,
                'role' => $message->crewMember->role,
            ] : null,
            'type' => $message->type,
            'body' => $message->body,
            'audio_path' => $message->audio_path,
            'metadata' => $message->metadata,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function assertCrewVehicleAccess(Request $request, Trip $trip): void
    {
        abort_unless(
            app(CrewTripAccessPolicy::class)->canAccess($request->user(), $trip),
            403,
            'Ce voyage ne correspond pas à vos affectations.',
        );
    }
}
