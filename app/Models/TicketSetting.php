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
        'print_qr_code',
        'okohi_integration_url',
        'okohi_integration_key',
        'settings',
    ];

    protected $casts = [
        'phone_numbers' => 'array',
        'footer_messages' => 'array',
        'print_qr_code' => 'boolean',
        'settings' => 'array',
    ];

    protected $appends = [
        'baggage_policy_message_2',
    ];

    public function getBaggagePolicyMessage2Attribute(): ?string
    {
        return data_get($this->settings, 'baggage_policy_message_2')
            ?? "Les objets de valeur doivent faire l'objet d'une declaration en sus de l'enregistrement avec pieces justificatives avant le depart.";
    }

    public static function getSettings(): self
    {
        return static::query()->orderBy('id')->first() ?? static::create(
            [
                'company_name' => 'TEST TRANSPORT',
                'phone_numbers' => ['+225 XX XX XX XX XX', '+225 XX XX XX XX XX'],
                'footer_messages' => ['Valable pour ce voyage', 'Non remboursable'],
                'baggage_policy_message' => "La perte des bagages transportes doit faire l'objet d'une declaration aux agences de la societe.",
                'print_qr_code' => true,
                'okohi_integration_url' => null,
                'okohi_integration_key' => null,
                'settings' => [
                    'baggage_policy_message_2' => "Les objets de valeur doivent faire l'objet d'une declaration en sus de l'enregistrement avec pieces justificatives avant le depart.",
                ],
            ]
        );
    }

    public function hasOkohiIntegration(): bool
    {
        return filled($this->okohi_integration_url)
            && filled($this->okohi_integration_key);
    }

    public function allowsCrewSales(): bool
    {
        return (bool) data_get($this->settings, 'allow_crew_sales', false);
    }

    public function okohiScanUrl(Ticket $ticket): string
    {
        return str_replace(
            ['{ticket_id}', '{amount}', '{timestamp}'],
            [$ticket->ticket_number, (int) $ticket->price, $ticket->created_at?->timestamp ?? now()->timestamp],
            $this->okohi_integration_url
        );
    }
}
