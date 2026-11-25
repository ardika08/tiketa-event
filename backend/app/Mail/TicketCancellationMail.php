<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class TicketCancellationMail extends Mailable
{
    public function __construct(private readonly array $notice) {}

    public function build(): self
    {
        return $this->subject('Pembatalan tiket '.$this->notice['kode_order'].' — Nontix')
            ->view('emails.ticket-cancellation', ['notice' => $this->notice]);
    }
}
