<?php

namespace App\Http\Controllers\Web;

use App\Domain\Access\Impersonation;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function __construct(private readonly Impersonation $impersonation) {}

    public function start(Request $request, User $user): RedirectResponse
    {
        try {
            $this->impersonation->start($request, $request->user(), $user, url()->previous());
        } catch (\DomainException $e) {
            return back()->withErrors(['impersonate' => $e->getMessage()]);
        }

        // Pasek na górze strony mówi, na czyim koncie jesteś — bez osobnego komunikatu.
        return redirect()->route('panel.dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        abort_unless(Impersonation::active(), 404);
        $returnTo = $this->impersonation->stop($request);

        return $returnTo ? redirect()->to($returnTo)->with('status', __('Jesteś z powrotem na swoim koncie.')) : redirect()->route('login');
    }
}
