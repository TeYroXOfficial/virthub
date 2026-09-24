<?php

namespace App\Http\Controllers\Web;

use App\Domain\Access\Permissions;
use App\Domain\Access\UserManager;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Administracja → Użytkownicy. */
class UsersController extends Controller
{
    public function __construct(private readonly UserManager $users) {}

    public function index(Request $request): View
    {
        $users = User::query()
            ->withCount('servers')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($q) => $q->where('email', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->string('status')->value() === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->orderByRaw("CASE role WHEN 'admin' THEN 0 WHEN 'support' THEN 1 ELSE 2 END")
            ->orderBy('email')
            ->paginate(30)
            ->withQueryString();

        return view('panel.admin.users.index', [
            'users' => $users,
            'manager' => $this->users,
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new User(['role' => User::ROLE_CUSTOMER]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->users->rules($request->user()));
        ['user' => $user, 'password' => $password] = $this->users->create($request->user(), $data);

        $redirect = redirect()->route('panel.admin.users.edit', $user)
            ->with('status', "Utworzono konto {$user->email}.");

        return $password !== null ? $redirect->with('generated_password', $password) : $redirect;
    }

    public function edit(Request $request, User $user): View
    {
        abort_unless($this->users->canManage($request->user(), $user), 403, 'Konta personelu może zmieniać tylko administrator.');

        return $this->form($request, $user);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate($this->users->rules($request->user(), $user));
        $this->users->update($request->user(), $user, $data);

        return back()->with('status', 'Zapisano zmiany konta.');
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $this->users->suspend($request->user(), $user);

        return back()->with('status', "Konto {$user->email} zostało zablokowane — użytkownik zostanie wylogowany.");
    }

    public function unsuspend(Request $request, User $user): RedirectResponse
    {
        $this->users->unsuspend($request->user(), $user);

        return back()->with('status', "Konto {$user->email} jest znowu aktywne.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $password = $this->users->resetPassword($request->user(), $user);

        return back()
            ->with('status', 'Ustawiono nowe hasło. Przekaż je użytkownikowi — nie zobaczysz go ponownie.')
            ->with('generated_password', $password);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->users->delete($request->user(), $user);

        return redirect()->route('panel.admin.users')->with('status', "Usunięto konto {$user->email}.");
    }

    private function form(Request $request, User $user): View
    {
        return view('panel.admin.users.form', [
            'user' => $user,
            'roles' => $this->users->assignableRoles($request->user()),
            'packages' => VpsPackage::query()->orderBy('vcpu')->get(),
            'defaults' => [
                User::ROLE_CUSTOMER => Permissions::defaultsFor(User::ROLE_CUSTOMER),
                User::ROLE_SUPPORT => Permissions::defaultsFor(User::ROLE_SUPPORT),
                User::ROLE_ADMIN => Permissions::defaultsFor(User::ROLE_ADMIN),
            ],
            'globalLimit' => (int) config('virthub.limits.servers_per_customer'),
        ]);
    }
}
