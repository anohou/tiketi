@php
    $companyName = $settings->company_name ?? 'TSR CI';
    $phoneNumbers = $settings->phone_numbers ?? ['(225) 0747471177', '0787298685'];
    $ccLabel = trim((string) ($settings->cc_label ?? ''));
    $footerMessages = $settings->footer_messages ?? ['Valable pour ce voyage', 'Non remboursable'];
    $baggagePolicyMessage = $settings->baggage_policy_message ?? "La perte des bagages transportes doit faire l'objet d'une declaration aux agences de la societe.";
@endphp

<div class="ticket">
    <div class="top-row">
        <div class="company-block">
            <div class="company-name">
                <span class="company-logo-line">{{ $companyName }}</span>
            </div>
            <div class="company-details">
                @foreach($phoneNumbers as $index => $phone)
                    @if($phone)
                        {{ $index === 0 ? 'Tel' : 'Service Bagages' }}: {{ $phone }}<br>
                    @endif
                @endforeach
            </div>
        </div>

        <div class="qr-block">
            @if(! empty($qrCode))
                <div class="qr-code">{!! $qrCode !!}</div>
            @endif
        </div>
    </div>

    <div class="ticket-number-box">
        <div class="ticket-label">N° TICKET DE VOYAGE</div>
        <div class="ticket-value">{{ $ticket->ticket_number }}</div>
    </div>

    @if($ccLabel !== '')
        <div class="cc-box">{{ $ccLabel }}</div>
    @endif

    <div class="journey-box">
        <div class="destination-panel">
            <div class="destination-label">Destination passager</div>
            <div class="destination-name">{{ strtoupper($ticket->toStation->name) }}</div>
        </div>

        <div class="route-lines">
            <div><strong>Depart:</strong> {{ $ticket->fromStation->name }}</div>
            <div><strong>Arrivee:</strong> {{ $ticket->toStation->name }}</div>
        </div>

        <div class="info-grid">
            <div class="info-cell">{{ $ticket->created_at->format('d/m/Y H:i') }}</div>
            <div class="info-cell">A</div>
        </div>

        <div class="summary-row">
            <div class="summary-cell">Prix: {{ number_format($ticket->price, 0, ',', ' ') }}</div>
            <div class="summary-cell center">Siege: <span class="seat-pill">{{ $ticket->seat_number }}</span></div>
            <div class="summary-cell right">ALLER</div>
        </div>

        <div class="zone-line">Zone d'embarquement : {{ $ticket->boarding_group ?? '1' }}</div>
    </div>

    <div class="footer">
        <div class="footer-note">
            @foreach($footerMessages as $message)
                @if($message)
                    {{ $message }}<br>
                @endif
            @endforeach
        </div>
        <div class="disclaimer">
            1. {{ $baggagePolicyMessage }}<br>
            2. Les objets de valeur doivent faire l'objet d'une declaration en sus de l'enregistrement avec pieces justificatives avant le depart.
        </div>
        <div class="timestamp">{{ $ticket->created_at->format('d/m/Y H:i:s') }}</div>
    </div>
</div>
