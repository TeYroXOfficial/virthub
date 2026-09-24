<?php

namespace App\Domain\Access;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Zakładanie i edycja kont oraz ich uprawnień.
 *
 * Zasady, których nie da się obejść z formularza:
 *  - konta personelu i administratorów zmienia tylko administrator — support
 *    z uprawnieniem „Użytkownicy" obsługuje wyłącznie klientów;
 *  - nikt nie zablokuje, nie usunie ani nie zdegraduje samego siebie;
 *  - zawsze zostaje co najmniej jeden aktywny administrator.
 */
class UserManager
{
    /** @return list<string> */
    public function assignableRoles(User $actor): array
    {
        return $actor->isAdmin()
            ? [User::ROLE_CUSTOMER, User::ROLE_SUPPORT, User::ROLE_ADMIN]
            : [User::ROLE_CUSTOMER];
    }

    public function canManage(User $actor, User $target): bool
    {
        return $actor->isAdmin() || $target->role === User::ROLE_CUSTOMER;
    }

    /** @return array<string, mixed> */
    public function rules(User $actor, ?User $target = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($target?->id)],
            'role' => ['required', Rule::in($this->assignableRoles($actor))],
            'password' => ['nullable', 'string', 'min:10', 'max:200'],
            'custom_permissions' => ['sometimes', 'boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
            'max_servers' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'restrict_packages' => ['sometimes', 'boolean'],
            'allowed_package_ids' => ['array'],
            'allowed_package_ids.*' => ['integer', Rule::exists(VpsPackage::class, 'id')],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{user: User, password: ?string} hasło, jeśli zostało wygenerowane
     */
    public function create(User $actor, array $data): array
    {
        $this->assertRoleAllowed($actor, $data['role']);

        $password = $data['password'] ?? null;
        $generated = $password === null || $password === '' ? $this->generatePassword() : null;

        $user = DB::transaction(function () use ($data, $generated, $password) {
            $user = new User([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $generated ?? $password,
                'role' => $data['role'],
            ]);
            $user->forceFill($this->accessAttributes($data))->save();

            return $user;
        });

        AuditLog::record('user.created', $user, ['role' => $user->role, 'email' => $user->email], $actor);

        return ['user' => $user, 'password' => $generated];
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, User $target, array $data): User
    {
        $this->assertCanManage($actor, $target);
        $this->assertRoleAllowed($actor, $data['role']);

        if ($actor->is($target) && $data['role'] !== $target->role) {
            throw ValidationException::withMessages(['role' => 'Nie możesz zmienić roli własnego konta.']);
        }

        if ($target->isAdmin() && $data['role'] !== User::ROLE_ADMIN) {
            $this->assertNotLastAdmin($target);
        }

        $before = $target->only(['role', 'permissions', 'max_servers', 'allowed_package_ids']);

        $target->fill([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'role' => $data['role'],
        ]);
        if (! empty($data['password'])) {
            $target->password = $data['password'];
        }
        $target->forceFill($this->accessAttributes($data))->save();

        AuditLog::record('user.updated', $target, [
            'before' => $before,
            'after' => $target->only(['role', 'permissions', 'max_servers', 'allowed_package_ids']),
        ], $actor);

        return $target;
    }

    public function suspend(User $actor, User $target): void
    {
        $this->assertCanManage($actor, $target);

        if ($actor->is($target)) {
            throw ValidationException::withMessages(['user' => 'Nie możesz zablokować własnego konta.']);
        }

        if ($target->isAdmin()) {
            $this->assertNotLastAdmin($target);
        }

        $target->forceFill(['suspended_at' => now()])->save();
        // Tokeny API przestają działać od razu; otwarte sesje kończy
        // middleware not-suspended przy następnym żądaniu.
        $target->tokens()->delete();

        AuditLog::record('user.suspended', $target, [], $actor);
    }

    public function unsuspend(User $actor, User $target): void
    {
        $this->assertCanManage($actor, $target);

        $target->forceFill(['suspended_at' => null])->save();
        AuditLog::record('user.unsuspended', $target, [], $actor);
    }

    /** @return string nowe hasło — do pokazania raz */
    public function resetPassword(User $actor, User $target): string
    {
        $this->assertCanManage($actor, $target);

        $password = $this->generatePassword();
        $target->forceFill(['password' => $password])->save();
        $target->tokens()->delete();

        AuditLog::record('user.password_reset', $target, [], $actor);

        return $password;
    }

    public function delete(User $actor, User $target): void
    {
        $this->assertCanManage($actor, $target);

        if ($actor->is($target)) {
            throw ValidationException::withMessages(['user' => 'Nie możesz usunąć własnego konta.']);
        }

        if ($target->servers()->exists()) {
            throw ValidationException::withMessages([
                'user' => "Konto {$target->email} ma maszyny. Usuń je albo przenieś na inne konto, zanim usuniesz użytkownika.",
            ]);
        }

        if ($target->isAdmin()) {
            $this->assertNotLastAdmin($target);
        }

        AuditLog::record('user.deleted', $target, ['email' => $target->email], $actor);
        $target->tokens()->delete();
        $target->delete();
    }

    // --- pomocnicze ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function accessAttributes(array $data): array
    {
        $role = $data['role'];

        return [
            // Bez „własnych uprawnień" konto idzie za domyślnymi swojej roli.
            'permissions' => ! empty($data['custom_permissions']) && $role !== User::ROLE_ADMIN
                ? Permissions::sanitize($role, $data['permissions'] ?? [])
                : null,
            'max_servers' => isset($data['max_servers']) && $data['max_servers'] !== '' ? (int) $data['max_servers'] : null,
            'allowed_package_ids' => ! empty($data['restrict_packages'])
                ? array_values(array_map('intval', $data['allowed_package_ids'] ?? []))
                : null,
        ];
    }

    private function assertCanManage(User $actor, User $target): void
    {
        if (! $this->canManage($actor, $target)) {
            throw new AuthorizationException('Konta personelu może zmieniać tylko administrator.');
        }
    }

    private function assertRoleAllowed(User $actor, string $role): void
    {
        if (! in_array($role, $this->assignableRoles($actor), true)) {
            throw new AuthorizationException('Nie możesz nadać tej roli.');
        }
    }

    private function assertNotLastAdmin(User $target): void
    {
        $others = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->whereNull('suspended_at')
            ->whereKeyNot($target->id)
            ->exists();

        if (! $others) {
            throw ValidationException::withMessages([
                'user' => 'To ostatni aktywny administrator — panel zostałby bez nikogo, kto nim zarządza.',
            ]);
        }
    }

    private function generatePassword(): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < 16; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
