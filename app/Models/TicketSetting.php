<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketSetting extends Model
{
    protected $fillable = [
        'company_name',
        'phone_numbers',
        'cc_label',
        'footer_messages',
        'baggage_policy_message',
        'qr_code_base_url',
        'print_qr_code',
        'okohi_enabled',
        'okohi_host',
        'okohi_company_id',
        'okohi_loyalty_type',
        'okohi_integration_key',
        'settings',
    ];

    protected $casts = [
        'phone_numbers' => 'array',
        'footer_messages' => 'array',
        'print_qr_code' => 'boolean',
        'settings' => 'array',
    ];

    /**
     * Get the ticket settings (singleton pattern)
     */
    public static function getSettings()
    {
        return static::firstOrCreate(
            ['id' => 1],
            [
                'company_name' => 'TSR CI',
                'phone_numbers' => ['+225 XX XX XX XX XX', '+225 XX XX XX XX XX'],
                'cc_label' => null,
                'footer_messages' => ['Valable pour ce voyage', 'Non remboursable'],
                'baggage_policy_message' => "La perte des bagages transportes doit faire l'objet d'une declaration aux agences de la societe.",
                'qr_code_base_url' => null,
                'print_qr_code' => true,
            ]
        );
    }

    private function isJson($string): bool
    {
        if (! is_string($string)) {
            return false;
        }

        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    public function getOkohiSettings(): array
    {
        $value = $this->attributes['qr_code_base_url'] ?? null;
        if (blank($value) || ! $this->isJson($value)) {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function updateOkohiSetting(string $key, $value): void
    {
        $settings = $this->getOkohiSettings();
        $settings[$key] = $value;
        $this->attributes['qr_code_base_url'] = json_encode($settings);
    }

    public function getOkohiEnabledAttribute(): bool
    {
        return (bool) ($this->getOkohiSettings()['okohi_enabled'] ?? false);
    }

    public function setOkohiEnabledAttribute(bool $value): void
    {
        $this->updateOkohiSetting('okohi_enabled', $value);
    }

    public function getOkohiHostAttribute(): ?string
    {
        return $this->getOkohiSettings()['okohi_host'] ?? null;
    }

    public function setOkohiHostAttribute(?string $value): void
    {
        $this->updateOkohiSetting('okohi_host', $value);
    }

    public function getOkohiCompanyIdAttribute(): ?string
    {
        return $this->getOkohiSettings()['okohi_company_id'] ?? null;
    }

    public function setOkohiCompanyIdAttribute(?string $value): void
    {
        $this->updateOkohiSetting('okohi_company_id', $value);
    }

    public function getOkohiLoyaltyTypeAttribute(): string
    {
        return $this->getOkohiSettings()['okohi_loyalty_type'] ?? 'points';
    }

    public function setOkohiLoyaltyTypeAttribute(?string $value): void
    {
        $this->updateOkohiSetting('okohi_loyalty_type', $value ?? 'points');
    }

    public function getOkohiIntegrationKeyAttribute(): ?string
    {
        return $this->getOkohiSettings()['okohi_integration_key'] ?? null;
    }

    public function setOkohiIntegrationKeyAttribute(?string $value): void
    {
        $this->updateOkohiSetting('okohi_integration_key', $value);
    }

    public function getQrCodeBaseUrlAttribute($value): ?string
    {
        if ($this->isJson($value)) {
            return null;
        }

        return $value;
    }

    public function hasOkohiIntegration(): bool
    {
        return $this->okohi_enabled
            && filled($this->okohi_host)
            && filled($this->okohi_company_id)
            && filled($this->okohi_loyalty_type)
            && filled($this->okohi_integration_key);
    }

    public function okohiScanUrl(Ticket $ticket): ?string
    {
        if (! $this->hasOkohiIntegration()) {
            return null;
        }

        return implode('/', [
            rtrim((string) $this->okohi_host, '/'),
            'api',
            'v1',
            'scan',
            rawurlencode((string) $this->okohi_company_id),
            rawurlencode((string) $this->okohi_loyalty_type),
            rawurlencode((string) $this->okohi_integration_key),
            rawurlencode((string) $ticket->ticket_number),
            (int) $ticket->price,
            $ticket->created_at?->timestamp ?? now()->timestamp,
        ]);
    }
}
