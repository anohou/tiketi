<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket {{ $ticket->ticket_number }}</title>
    <style>
        @page {
            margin: 0;
            size: 80mm 200mm;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 10px;
            font-size: 12px;
            line-height: 1.2;
            color: #000;
        }
        
        .ticket {
            width: 100%;
            max-width: 80mm;
            margin: 0 auto;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .company-info {
            flex: 1;
        }
        
        .company-logo {
            width: 40px;
            height: 40px;
            /* Placeholder for logo */
            /* background: #ccc; */ 
            margin-bottom: 5px;
        }

        .company-name {
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 2px;
        }
        
        .company-details {
            font-size: 10px;
        }
        
        .qr-code {
            width: 60px;
            height: 60px;
        }
        
        .ticket-number-box {
            border: 2px solid #000;
            padding: 5px;
            text-align: center;
            margin-bottom: 5px;
            width: 65%; /* Adjust width as needed */
        }
        
        .ticket-label {
            font-weight: bold;
            font-size: 10px;
            text-decoration: underline;
            margin-bottom: 5px;
        }
        
        .ticket-value {
            font-size: 20px;
            font-weight: bold;
        }

        .cc-box {
            border: 2px solid #000;
            padding: 2px 5px;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .route-box {
            border: 2px solid #000;
            padding: 5px;
            margin-bottom: 5px;
        }
        
        .route-name {
            font-weight: bold;
            font-size: 14px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .info-grid {
            display: flex;
            border: 2px solid #000;
            margin-bottom: 5px;
        }
        
        .info-cell {
            padding: 5px;
            flex: 1;
            text-align: center;
            font-weight: bold;
            font-size: 14px;
        }
        
        .info-cell:first-child {
            border-right: 2px solid #000;
            flex: 2; /* Date/Time takes more space */
        }
        
        .price-seat-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 10px;
            margin-bottom: 15px;
            font-weight: bold;
            font-size: 14px;
        }
        
        .seat-circle {
            border: 2px solid #000;
            border-radius: 15px; /* Rounded rectangle/oval */
            padding: 5px 15px;
            margin: 0 10px;
            font-size: 16px;
        }

        .footer {
            font-size: 9px;
            margin-top: 20px;
        }
        
        .footer-note {
            margin-bottom: 10px;
        }
        
        .disclaimer {
            font-size: 8px;
            text-align: justify;
        }

        .vertical-date {
            position: absolute;
            left: 5px;
            bottom: 100px;
            transform: rotate(-90deg);
            transform-origin: left bottom;
            font-size: 10px;
        }
        
        /* Utility for alignment */
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        
    </style>
</head>
<body>
    <div class="ticket">
        
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <!-- Logo placeholder or image if available -->
                <!-- <img src="..." class="company-logo" alt="Logo"> -->
                <div class="company-name">TSR CI</div> <!-- Using TSR CI as per model, or dynamic -->
                <div class="company-details">
                    Tel: (225) 0747471177<br>
                    Service Bagages: 0787298685
                </div>
            </div>
            <div class="qr-code">
                 {!! $qrCode !!}
            </div>
        </div>

        <!-- Ticket Number -->
        <div style="display: flex; justify-content: space-between; align-items: flex-end;">
            <div class="ticket-number-box">
                <div class="ticket-label">N° TICKET DE VOYAGE</div>
                <div class="ticket-value">{{ $ticket->ticket_number }}</div>
            </div>
             <!-- QR Code is actually here in the image, to the right of the ticket number box -->
        </div>

        <!-- CC -->
        <div class="cc-box">
            CC
        </div>

        <!-- Route & Details Container -->
        <div class="route-box">
            <div class="route-name">
                {{ strtoupper($ticket->fromStation->name) }}-{{ strtoupper($ticket->toStation->name) }}
            </div>
            
            <div class="info-grid">
                <div class="info-cell">
                    {{ $ticket->created_at->format('d/m/Y H:i') }}
                </div>
                <div class="info-cell">
                    A
                </div>
            </div>

            <div class="price-seat-row">
                <div>Prix: {{ $ticket->amount }}</div>
                <div style="display: flex; align-items: center;">
                    Siège: <div class="seat-circle">{{ $ticket->seat_number }}</div>
                </div>
                <div>ALLER</div>
            </div>
            
            <div style="text-align: center; font-weight: bold; font-size: 16px; margin-bottom: 5px; text-transform: uppercase;">
                Zone d'embarquement : {{ $ticket->boarding_group ?? '1' }}
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="footer-note">
                NB: DELAI DE VALIDITE DU TICKET 24H<br>
                TICKET NON REMBOURSABLE
            </div>
            
            <div class="disclaimer">
                1. La perte des bagages transportés par TSR doit faire l'objet d'une déclaration aux agences de la société contre récépissé de déclaration immédiatement à destination du voyageur sous peine d'échéance.<br>
                2. Les objets de valeur doivent faire l'objet d'une déclaration en sus de l'enregistrement avec pièces justificatives avant le départ.
            </div>
        </div>

        <!-- Vertical Date (approximate position) -->
        <div class="vertical-date">
            {{ $ticket->created_at->format('d/m/Y H:i') }}
        </div>

    </div>
    <script>
        window.onload = function() {
            window.print();
            // Auto-close window after print dialog is dismissed
            window.onafterprint = function() {
                window.close();
            };
        }
    </script>
</body>
</html>

