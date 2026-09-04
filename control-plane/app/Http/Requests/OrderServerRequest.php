<?php

namespace App\Http\Requests;

use App\Models\OsTemplate;
use App\Models\VpsPackage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderServerRequest extends FormRequest
{
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
            'ssh_keys' => ['array', 'max:10'],
            'ssh_keys.*' => ['string', 'max:1000', 'regex:/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp[0-9]+)\s+[A-Za-z0-9+\/=]+/'],
        ];
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

    /** @return list<string> */
    public function sshKeys(): array
    {
        return array_values(array_filter($this->input('ssh_keys', [])));
    }
}
