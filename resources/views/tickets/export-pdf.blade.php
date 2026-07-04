<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport Tickets</title>
    <style>
        @page { margin: 10mm; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8px;
            color: #1a1a1a;
        }
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #16a34a;
            padding-bottom: 10px;
        }
        .header h1 {
            font-size: 16px;
            color: #16a34a;
            margin: 0 0 5px 0;
        }
        .header .meta {
            font-size: 9px;
            color: #666;
        }
        .summary {
            margin-bottom: 12px;
            padding: 8px;
            background: #f0fdf4;
            border-radius: 4px;
            font-size: 9px;
        }
        .summary span { margin-right: 20px; }
        .summary strong { color: #16a34a; }
        .group-row td {
            background: #dcfce7;
            color: #166534;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 7px;
            letter-spacing: 0.3px;
            border-bottom: 1px solid #bbf7d0;
            border-top: 1px solid #bbf7d0;
            padding: 5px 6px;
        }
        .subtotal-row td {
            background: #f8fafc;
            font-weight: bold;
            border-bottom: 1px solid #e5e7eb;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #16a34a;
            color: white;
            padding: 5px 4px;
            text-align: left;
            font-size: 7px;
            text-transform: uppercase;
        }
        td {
            padding: 4px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 7px;
            vertical-align: top;
        }
        tr:nth-child(even) { background: #f9fafb; }
        .total-row {
            font-weight: bold;
            background: #f0fdf4;
            border-top: 2px solid #16a34a;
        }
        .footer {
            margin-top: 15px;
            padding-top: 8px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 7px;
            color: #9ca3af;
        }
        .status-valide { color: #16a34a; font-weight: bold; }
        .status-annule { color: #dc2626; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ config('app.name') }} — Rapport Tickets</h1>
        <div class="meta">
            @if(!empty($periodLabel))
                Periode : {{ $periodLabel }}
            @else
                Periode : {{ $startDate }} -> {{ $endDate }}
            @endif
            &nbsp;|&nbsp; Genere le {{ $generatedAt }}
            @if(!empty($trip))
                &nbsp;|&nbsp; Code trajet : {{ $trip->code ?? '-' }}
            @endif
        </div>
    </div>

    <div class="summary">
        <span><strong>Total tickets :</strong> {{ $tickets->count() }}</span>
        <span><strong>Total ventes :</strong> {{ number_format($totalAmount, 0, ',', ' ') }} FCFA</span>
        <span><strong>Prix moyen :</strong> {{ $tickets->count() > 0 ? number_format($totalAmount / $tickets->count(), 0, ',', ' ') : 0 }} FCFA</span>
        @if(isset($groupedTickets) && $groupedTickets->count() > 0)
            <span><strong>Gares :</strong> {{ $groupedTickets->count() }}</span>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>N Ticket</th>
                <th>Date vente</th>
                <th>Depart</th>
                <th>Arrivee</th>
                <th>Place</th>
                <th>Zone</th>
                <th>Vendeur</th>
                <th>Passager</th>
                <th>Prix (FCFA)</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            @forelse($groupedTickets as $group)
                <tr class="group-row">
                    <td colspan="10">
                        Gare de départ : {{ $group['station_name'] }} - {{ $group['count'] }} ticket(s) - {{ number_format($group['amount'], 0, ',', ' ') }} FCFA
                    </td>
                </tr>
                @foreach($group['tickets'] as $ticket)
                    <tr>
                        <td>{{ $ticket->ticket_number }}</td>
                        <td>{{ $ticket->created_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $ticket->fromStation?->name ?? '-' }}</td>
                        <td>{{ $ticket->toStation?->name ?? '-' }}</td>
                        <td>{{ $ticket->seat_number ?? '-' }}</td>
                        <td>{{ $ticket->boarding_group ?? '-' }}</td>
                        <td>{{ $ticket->seller?->name ?? '-' }}</td>
                        <td>{{ $ticket->passenger_name ?? 'Anonyme' }}</td>
                        <td style="text-align:right">{{ number_format($ticket->price, 0, ',', ' ') }}</td>
                        <td class="{{ $ticket->status === 'cancelled' ? 'status-annule' : 'status-valide' }}">
                            {{ $ticket->status === 'cancelled' ? 'Annule' : 'Valide' }}
                        </td>
                    </tr>
                @endforeach
                <tr class="subtotal-row">
                    <td colspan="8" style="text-align:right; padding-right:10px;">Sous-total {{ $group['station_name'] }}</td>
                    <td style="text-align:right">{{ number_format($group['amount'], 0, ',', ' ') }}</td>
                    <td></td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" style="text-align:center; padding:12px;">Aucun ticket vendu</td>
                </tr>
            @endforelse
            <tr class="total-row">
                <td colspan="8" style="text-align:right; padding-right:10px;">TOTAL</td>
                <td style="text-align:right">{{ number_format($totalAmount, 0, ',', ' ') }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        {{ config('app.name') }} — Rapport genere automatiquement le {{ $generatedAt }}
    </div>
</body>
</html>
