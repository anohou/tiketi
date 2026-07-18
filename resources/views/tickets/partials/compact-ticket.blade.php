@php
    $companyName = $settings->company_name ?? 'TEST TRANSPORT';
    $phoneNumbers = $settings->phone_numbers ?? ['(225) 0747471177', '0787298685'];
    $ccLabel = trim((string) ($settings->cc_label ?? ''));
    $footerMessages = $settings->footer_messages ?? ['Valable pour ce voyage', 'Non remboursable'];
    $baggagePolicyMessage = $settings->baggage_policy_message ?? "La perte des bagages transportes doit faire l'objet d'une declaration aux agences de la societe.";
    $tenantLogoUrl = tenant('logo_url');
@endphp

<div class="ticket">
    <div class="top-row">
        <div class="company-block">
            <div class="company-name">
                <span class="company-logo-line">{{ $companyName }}</span>
            </div>
            <div class="company-details">
                @foreach($phoneNumbers as $phone)
                    @if($phone)
                        {{ $phone }}<br>
                    @endif
                @endforeach
            </div>
        </div>

        <div class="logo-block">
            @if($tenantLogoUrl)
                <img class="tenant-logo" src="{{ $tenantLogoUrl }}" alt="{{ $companyName }} logo">
            @endif
        </div>
    </div>

    <div class="ticket-number-box">
        <div class="ticket-label">N° TICKET</div>
        <div class="ticket-value">{{ $ticket->ticket_number }}</div>
    </div>

    @if($ccLabel !== '')
        <div class="cc-box">{{ $ccLabel }}</div>
    @endif

    <div class="journey-box">
        <div class="route-lines">
            <div><strong>Depart:</strong> {{ $ticket->fromStation->name }}</div>
            @if($ticket->finalDestinationStation)
                <div><strong>Correspondance:</strong> {{ $ticket->transferStation?->name ?? $ticket->toStation->name }}</div>
                <div><strong>Destination finale:</strong> {{ $ticket->finalDestinationStation->name }}</div>
                <div><strong>Suite:</strong> {{ $ticket->transferStation?->name ?? $ticket->toStation->name }} → {{ $ticket->finalDestinationStation->name }} (voyage attribue sur place)</div>
                <div><strong>Aucun nouveau paiement. Conserver ce ticket et ce QR.</strong></div>
            @endif
        </div>

        <div class="destination-panel">
            <div class="destination-label">Destination</div>
            <div class="destination-name">{{ strtoupper($ticket->finalDestinationStation?->name ?? $ticket->toStation->name) }}</div>
        </div>

        <div class="info-grid">
            <div class="info-cell date-cell">{{ $ticket->created_at->format('d/m/Y H:i') }}</div>
            <div class="info-cell info-price-cell">
                <span class="info-label">Prix</span>
                {{ number_format($ticket->price, 0, ',', ' ') }}
            </div>
        </div>

        <div class="summary-row">
            <div class="summary-cell center seat-block">
                <span class="seat-label">Siege</span>
                <span class="seat-pill">{{ $ticket->seat_number }}</span>
            </div>
            @if(! empty($qrCode))
                <div class="summary-cell qr-summary"><div class="qr-code">{!! $qrCode !!}</div></div>
            @endif
        </div>
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
