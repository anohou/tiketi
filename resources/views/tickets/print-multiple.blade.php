<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tickets - {{ count($tickets) }} tickets</title>
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
            line-height: 1.4;
        }
        
        .ticket {
            background: #fff;
            padding: 8px;
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        
        .header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 1px solid #000;
            padding-bottom: 8px;
        }
        
        .logo {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        
        .sysge {
            color: #059669;
        }
        
        .trans {
            color: #ea580c;
        }
        
        .contact-info {
            font-size: 9px;
            color: #666;
        }
        
        .ticket-number-section {
            flex: 1;
            text-align: center;
        }
        
        .ticket-number-box {
            border: 2px solid #000;
            padding: 5px 10px;
            margin: 5px 0;
            text-align: center;
        }
        
        .ticket-number-label {
            font-size: 9px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        
        .ticket-number-value {
            font-size: 18px;
            font-weight: bold;
        }
        
        .qr-section {
            flex: 1;
            text-align: right;
        }
        
        .qr-code {
            width: 60px;
            height: 60px;
        }
        
        .route-section {
            margin: 10px 0;
        }
        
        .route-box {
            border: 2px solid #000;
            padding: 8px;
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            margin: 5px 0;
        }
        
        .details-section {
            display: flex;
            justify-content: space-between;
            margin: 10px 0;
        }
        
        .detail-box {
            border: 1px solid #000;
            padding: 5px;
            text-align: center;
            min-width: 60px;
        }
        
        .detail-label {
            font-size: 8px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        
        .detail-value {
            font-size: 12px;
            font-weight: bold;
        }
        
        .price-seat-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 10px 0;
        }
        
        .price {
            font-size: 14px;
            font-weight: bold;
        }
        
        .seat {
            border: 2px solid #000;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 12px;
        }
        
        .direction {
            text-align: center;
            font-weight: bold;
            margin: 5px 0;
        }
        
        .footer {
            margin-top: 15px;
            font-size: 8px;
            line-height: 1.2;
        }
        
        .footer-title {
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .footer-text {
            margin: 2px 0;
        }
        
        .timestamp {
            position: absolute;
            left: 5px;
            top: 50%;
            transform: rotate(-90deg) translateX(50%);
            font-size: 8px;
            color: #666;
        }
        

        
        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
    @foreach($tickets as $index => $ticket)
        @if($index > 0)
            <div class="page-break"></div>
        @endif
        
        <div class="ticket">
            <!-- Header with Logo, Ticket Number, and QR -->
            <div class="header">
                <div class="logo-section">
                    <div class="logo">
                        <span class="sysge">SysGe</span><span class="trans">Trans</span>
                    </div>
                    <div class="contact-info">
                        Tel: (225) 0701234567<br>
                        Service: 0702345678
                    </div>
                </div>
                
                <div class="ticket-number-section">
                    <div class="ticket-number-box">
                        <div class="ticket-number-label">N° TICKET DE VOYAGE</div>
                        <div class="ticket-number-value">{{ $ticket->ticket_number }}</div>
                    </div>
                </div>
                
                <div class="qr-section">
                    {!! $qrCodes[$ticket->id] !!}
                </div>
            </div>

            <!-- Route Information -->
            <div class="route-section">
                <div class="route-box">
                    {{ $ticket->fromStation->name }}-{{ $ticket->toStation->name }}
                </div>
            </div>

            <!-- Details Section -->
            <div class="details-section">
                <div class="detail-box">
                    <div class="detail-label">CC</div>
                    <div class="detail-value">-</div>
                </div>
                <div class="detail-box">
                    <div class="detail-label">DATE</div>
                    <div class="detail-value">{{ $ticket->created_at->format('d/m/Y') }}</div>
                </div>
                <div class="detail-box">
                    <div class="detail-label">HEURE</div>
                    <div class="detail-value">{{ $ticket->created_at->format('H:i') }}</div>
                </div>
                <div class="detail-box">
                    <div class="detail-label">CLASSE</div>
                    <div class="detail-value">A</div>
                </div>
            </div>

            <!-- Price, Seat, and Direction -->
            <div class="price-seat-section">
                <div class="price">Prix: {{ number_format($ticket->amount, 0, ',', ' ') }}</div>
                <div class="seat">Siège: {{ $ticket->seat_number }}</div>
                <div class="direction">ALLER</div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <div class="footer-title">NB DELAI DE VALIDITE DU TICKET 24H</div>
                <div class="footer-title">TICKET NON REMBOURSABLE</div>
                
                <div class="footer-text">
                    1. La perte des bagages transportés par SysGeTrans doit faire l'objet d'une déclaration aux agences de la société contre récépissé de déclaration immédiatement à destination du voyageur sous peine d'échéance.
                </div>
                
                <div class="footer-text">
                    2. Les objets de valeur doivent faire l'objet d'une déclaration en sus de l'enregistrement avec pièces justificatives avant le départ.
                </div>
            </div>

            <!-- Timestamp -->
            <div class="timestamp">
                {{ $ticket->created_at->format('d/m/Y H:i') }}
            </div>
        </div>
    @endforeach
</body>
</html>
