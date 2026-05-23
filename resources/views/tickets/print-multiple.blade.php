<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tickets - {{ count($tickets) }} tickets</title>
    @include('tickets.partials.compact-ticket-styles')
</head>
<body>
    @foreach($tickets as $ticket)
        @include('tickets.partials.compact-ticket', [
            'ticket' => $ticket,
            'qrCode' => $qrCodes[$ticket->id] ?? null,
            'settings' => $settings,
        ])
    @endforeach
</body>
</html>
