<?php

namespace App\Http\Controllers;

use App\Mail\ContactInquiry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    /**
     * Adresse de réception des demandes de démonstration.
     * Peut être surchargée via la variable d'environnement MAIL_CONTACT_TO.
     */
    public const CONTACT_TO = 'contact@anohou.dev';

    /**
     * Envoie la demande de contact issue du formulaire public de présentation.
     */
    public function send(Request $request)
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            Mail::to(env('MAIL_CONTACT_TO', self::CONTACT_TO))
                ->send(new ContactInquiry(
                    company: $data['company'],
                    email: $data['email'],
                    phone: $data['phone'] ?? null,
                    content: $data['message'] ?? null,
                ));
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['mail' => "L'envoi du message a échoué. Réessayez ou écrivez-nous directement à ".env('MAIL_CONTACT_TO', self::CONTACT_TO).'.']);
        }

        return back();
    }
}
