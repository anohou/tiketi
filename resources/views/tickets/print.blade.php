<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket {{ $ticket->ticket_number }}</title>
    @include('tickets.partials.compact-ticket-styles')
</head>
<body>
    @include('tickets.partials.compact-ticket', [
        'ticket' => $ticket,
        'qrCode' => $qrCode,
        'settings' => $settings,
    ])

    <script>
        window.onload = function() {
            window.print();
            window.onafterprint = function() {
                window.close();
            };
        };
    </script>
</body>
</html>
