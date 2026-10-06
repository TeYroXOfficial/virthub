<?php

namespace App\Support;

use Illuminate\Translation\FileLoader;

/**
 * Loader tłumaczeń z nadpisaniami administratora (storage/app/lang): pliki
 * JSON i grupy (validation.php…) z tego katalogu wygrywają z lang/ — także
 * dla wbudowanych języków — i przetrwają aktualizację panelu.
 */
class TranslationLoader extends FileLoader
{
    /** @var array<string, string> język dodany przez administratora → język bazowy (np. de → en) */
    private array $bases = [];

    public function __construct($files, array $paths, private readonly string $overridePath)
    {
        parent::__construct($files, $paths);
    }

    /** @param  array<string, string>  $bases */
    public function setBases(array $bases): void
    {
        $this->bases = $bases;
    }

    protected function loadJsonPaths($locale)
    {
        // Nieprzetłumaczone teksty nowego języka — z języka bazowego, nie polskie źródło.
        $base = $this->bases[$locale] ?? null;
        $output = $base !== null && $base !== $locale ? $this->loadJsonPaths($base) : [];
        $output = array_merge($output, parent::loadJsonPaths($locale));
        $file = $this->overridePath."/{$locale}.json";
        if ($this->files->exists($file)) {
            $decoded = json_decode($this->files->get($file), true);
            if (is_array($decoded)) {
                $output = array_merge($output, array_filter($decoded, fn ($v) => is_string($v) && $v !== ''));
            }
        }

        return $output;
    }

    protected function loadPaths(array $paths, $locale, $group)
    {
        $base = $this->bases[$locale] ?? null;
        $output = $base !== null && $base !== $locale ? $this->loadPaths($paths, $base, $group) : [];

        return array_replace_recursive($output, parent::loadPaths([...$paths, $this->overridePath], $locale, $group));
    }

    public function overridePath(): string
    {
        return $this->overridePath;
    }
}
