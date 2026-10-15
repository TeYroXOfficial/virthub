<?php

namespace App\Domain\Security;

/** Odrzucona odpowiedź klucza WebAuthn — komunikat nadaje się do pokazania użytkownikowi. */
class WebAuthnException extends \RuntimeException {}
