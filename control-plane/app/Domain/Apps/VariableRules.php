<?php

namespace App\Domain\Apps;

use App\Models\AppEgg;
use Illuminate\Support\Facades\Validator;

/**
 * Walidacja zmiennych aplikacji regułami z eggu.
 *
 * Eggi Pterodactyla zapisują reguły w składni walidatora Laravela. Egg może
 * pochodzić z internetu, więc przepuszczamy tylko reguły, które niczego nie
 * dotykają poza samą wartością — bez `exists`, `unique` i podobnych.
 */
class VariableRules
{
    private const ALLOWED = [
        'required', 'nullable', 'sometimes', 'string', 'integer', 'numeric', 'boolean', 'alpha', 'alpha_num',
        'alpha_dash', 'url', 'ip', 'ipv4', 'ipv6', 'in', 'not_in', 'regex', 'not_regex', 'min', 'max',
        'between', 'size', 'digits', 'digits_between', 'starts_with', 'ends_with', 'uuid', 'json', 'lowercase', 'uppercase',
    ];

    /** @return list<string> */
    public static function rulesFor(array $variable): array
    {
        $raw = (string) ($variable['rules'] ?? 'nullable|string');
        // Regex może zawierać „|" — rozcinamy tylko poza wyrażeniem regularnym.
        $parts = preg_split('/\|(?=(?:[a-z_]+)(?::|$|\|))/', $raw) ?: [];
        $rules = [];
        foreach ($parts as $rule) {
            $name = strtolower(explode(':', $rule, 2)[0]);
            if (in_array($name, self::ALLOWED, true)) {
                $rules[] = $rule;
            }
        }
        $rules[] = 'max:10000';

        return $rules;
    }

    /**
     * Sprawdza i zwraca nowe wartości zmiennych, które klient może edytować.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function validate(AppEgg $egg, array $input, bool $asStaff = false): array
    {
        $rules = [];
        $attributes = [];
        foreach ($egg->variableList() as $var) {
            $env = $var['env_variable'];
            if (! $asStaff && (! ($var['user_editable'] ?? true) || ! ($var['user_viewable'] ?? true))) {
                continue;
            }
            if (! array_key_exists($env, $input)) {
                continue;
            }
            $rules[$env] = self::rulesFor($var);
            $attributes[$env] = $egg->text($var['name'] ?? $env);
        }

        $data = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', array_intersect_key($input, $rules));
        $validated = Validator::make($data, $rules, [], $attributes)->validate();

        return array_map(fn ($v) => (string) ($v ?? ''), $validated);
    }
}
