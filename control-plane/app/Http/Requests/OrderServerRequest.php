<?php

namespace App\Http\Requests;

use App\Models\OsTemplate;
use App\Models\VpsPackage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Illuminate\Validation\Rule;

class OrderServerRequest extends FormRequest
{
    /**
     * Puste pole klucza z formularza przychodzi jako null (middleware
     * ConvertEmptyStringsToNull) — to „bez klucza", a nie błędny klucz.
     */
    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('ssh_keys'))) {
            return;
        }

        $this->merge([
            'ssh_keys' => array_values(array_filter(
                array_map(fn ($key) => is_string($key) ? trim($key) : $key, $this->input('ssh_keys')),
                fn ($key) => $key !== null && $key !== '',
            )),
        ]);
    }

    public function rules(): array
    {
        return [
            'package' => ['required', Rule::exists(VpsPackage::class, 'slug')->where('is_active', true)],
            'template' => ['required', Rule::exists(OsTemplate::class, 'id')->where('is_active', true)],
            // Nazwa hosta trafia do cloud-init i do konfiguracji gościa —
            // dopuszczamy wyłącznie poprawną składnię FQDN.
            'hostname' => [
                'required',
                'string',
                'max:253',
                'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i',
            ],
            'label' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'integer'],
            'ssh_keys' => ['array', 'max:10'],
            'ssh_keys.*' => ['string', 'max:1000', 'regex:/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp[0-9]+)\s+[A-Za-z0-9+\/=]+/'],
        ];
    }

    /**
     * Limity konta: liczba maszyn i dozwolone pakiety. Tu, a nie w
     * kontrolerze, żeby panel i API sprawdzały dokładnie to samo.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $user = $this->user();

            if ($user === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            // Personel bez własnego limitu zamawia bez ograniczeń (np. maszyny testowe).
            if (! ($user->isStaff() && $user->max_servers === null)) {
                $limit = $user->serverLimit();
                if ($user->servers()->count() >= $limit) {
                    $validator->errors()->add('package', "Osiągnięto limit {$limit} maszyn na koncie. "
                        .'Napisz do nas, jeśli potrzebujesz go zwiększyć.');

                    return;
                }
            }

            $template = OsTemplate::with('group')->find($this->integer('template'));
            if ($template?->group !== null && ! $template->group->is_active) {
                $validator->errors()->add('template', "System {$template->group->name} nie jest teraz dostępny.");

                return;
            }

            $location = $this->location();
            if ($this->filled('location') && $location === null) {
                $validator->errors()->add('location', 'Wybrana lokalizacja nie jest dostępna.');

                return;
            }

            $package = VpsPackage::where('slug', $this->string('package'))->first();
            if ($package !== null && ! $user->mayOrderPackage($package)) {
                $validator->errors()->add('package', "Pakiet {$package->name} nie jest dostępny dla Twojego konta.");
            }
        }];
    }

    public function messages(): array
    {
        return [
            'hostname.regex' => 'Nazwa hosta musi być poprawną nazwą domenową, np. vps1.mojadomena.pl.',
            'ssh_keys.*.regex' => 'Klucz SSH musi być w formacie OpenSSH (ssh-ed25519 AAAA... lub ssh-rsa AAAA...).',
            'package.exists' => 'Wybrany pakiet nie istnieje albo nie jest już dostępny.',
            'template.exists' => 'Wybrany system operacyjny nie jest dostępny.',
        ];
    }

    public function package(): VpsPackage
    {
        return VpsPackage::where('slug', $this->string('package'))->firstOrFail();
    }

    public function template(): OsTemplate
    {
        return OsTemplate::findOrFail($this->integer('template'));
    }

    /** Lokalizacja wybrana przez klienta — tylko grupa oznaczona jako widoczna. */
    public function location(): ?\App\Models\HypervisorGroup
    {
        if (! $this->filled('location')) {
            return null;
        }

        return \App\Models\HypervisorGroup::query()
            ->where('is_public', true)
            ->where('accepts_new_servers', true)
            ->find($this->integer('location'));
    }

    /** @return list<string> */
    public function sshKeys(): array
    {
        return array_values(array_filter($this->input('ssh_keys', [])));
    }
}
