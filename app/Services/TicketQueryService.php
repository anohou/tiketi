<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;

class TicketQueryService
{
    public function getFilteredTicketsQuery(Request $request, User $user)
    {
        $query = Ticket::query()
            ->with(['trip.route', 'trip.vehicle.vehicleType', 'seller', 'fromStation', 'toStation'])
            ->orderBy('created_at', 'desc');

        $tripId = $request->get('trip_id');
        $hasExplicitStartDate = $request->filled('start_date');
        $hasExplicitEndDate = $request->filled('end_date');

        // Date range filters
        if ($hasExplicitStartDate) {
            $query->whereDate('created_at', '>=', $request->get('start_date'));
        }

        if ($hasExplicitEndDate) {
            $query->whereDate('created_at', '<=', $request->get('end_date'));
        }

        // When exporting a specific trip, keep the full ticket history unless a date range was explicitly requested.
        if (! $tripId && ! $hasExplicitStartDate && ! $hasExplicitEndDate) {
            $query->whereDate('created_at', today());
        }

        // Trip filter
        if ($tripId) {
            $query->where('trip_id', $tripId);
        }

        // Seller restriction
        if ($user->role === 'seller') {
            $query->where('seller_id', $user->id);
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        } else {
            $query->where('status', '!=', 'cancelled');
        }

        return $query;
    }
}
