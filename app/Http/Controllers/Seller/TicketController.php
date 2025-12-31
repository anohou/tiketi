<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TicketController extends Controller
{
    public function index()
    {
        $tickets = Ticket::with(['trip.route', 'trip.vehicle', 'fromStation', 'toStation'])
            ->where('seller_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('Seller/Tickets/Index', [
            'tickets' => $tickets
        ]);
    }
}
