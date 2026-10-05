<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Gotowa odpowiedź personelu wstawiana do zgłoszenia jednym kliknięciem. */
class TicketCannedResponse extends Model
{
    protected $fillable = ['title', 'body'];
}
