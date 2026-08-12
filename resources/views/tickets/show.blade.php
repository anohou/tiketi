<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket {{ $ticket->ticket_number }}</title>
    @include('tickets.partials.compact-ticket-styles')
    <style>
        body {
            padding-top: 22mm;
            background: #f8fafc;
        }

        .ticket-actions {
            position: fixed;
            z-index: 10;
            top: 0;
            left: 0;
            right: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08);
        }

        .ticket-actions strong {
            overflow: hidden;
            color: #0f172a;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ticket-actions button {
            flex: 0 0 auto;
            cursor: pointer;
            border: 0;
            border-radius: 10px;
            padding: 9px 14px;
            background: #059669;
            color: #fff;
            font-weight: 700;
        }

        @media print {
            body {
                padding-top: 7mm;
                background: #fff;
            }

            .ticket-actions {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="ticket-actions">
        <strong>Ticket {{ $ticket->ticket_number }}</strong>
        <button type="button" onclick="window.print()">Imprimer</button>
    </div>

    @include('tickets.partials.compact-ticket', [
        'ticket' => $ticket,
        'qrCode' => $qrCode,
        'settings' => $settings,
    ])
</body>
</html>
