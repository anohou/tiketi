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
        'okohi_url',
        'settings',
    ];

    protected $casts = [
        'phone_numbers' => 'array',
        'footer_messages' => 'array',
        'print_qr_code' => 'boolean',
        'settings' => 'array',
    ];

    public static function getSettings(): self
    {
        return static::firstOrCreate(
            ['id' => 1],
            [
                'company_name' => 'TSR CI',
                'phone_numbers' => ['+225 XX XX XX XX XX', '+225 XX XX XX XX XX'],
                'footer_messages' => ['Valable pour ce voyage', 'Non remboursable'],
                'baggage_policy_message' => "La perte des bagages transportes doit faire l'objet d'une declaration aux agences de la societe.",
                'print_qr_code' => true,
                'okohi_url' => null,
            ]
        );
    }

    public function hasOkohiIntegration(): bool
    {
        return filled($this->okohi_url);
    }

    public function okohiScanUrl(Ticket $ticket): string
    {
        return str_replace(
            ['{ticket_id}', '{amount}', '{timestamp}'],
            [$ticket->ticket_number, (int) $ticket->price, $ticket->created_at?->timestamp ?? now()->timestamp],
            $this->okohi_url
        );
    }
}
