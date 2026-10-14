<?php

/*
 * Tłumaczenia modułów billingowych VirtHub (pliki lang/en.php, lang/pl.php).
 * Kopiowane do każdego modułu przez integrations/build.php.
 */

namespace VirtHub\Billing;

if (!class_exists(__NAMESPACE__ . '\\Lang', false)) {
    class Lang
    {
        /** @var array<string, array<string, string>> */
        private static $cache = [];

        /** „polish”, „pl_pl”, „pl-PL”, „pl” → pl; wszystko inne → en. */
        public static function normalize($locale)
        {
            $locale = strtolower((string) $locale);

            return (strpos($locale, 'pl') === 0 || $locale === 'polish') ? 'pl' : 'en';
        }

        /** @return array<string, string> */
        public static function strings($dir, $locale)
        {
            $code = self::normalize($locale);
            $key = $dir . '|' . $code;
            if (!isset(self::$cache[$key])) {
                $en = is_file($dir . '/en.php') ? (array) require $dir . '/en.php' : [];
                $own = $code !== 'en' && is_file($dir . '/' . $code . '.php') ? (array) require $dir . '/' . $code . '.php' : [];
                self::$cache[$key] = array_merge($en, $own);
            }

            return self::$cache[$key];
        }

        public static function get($dir, $locale, $key, array $vars = [])
        {
            $strings = self::strings($dir, $locale);
            $text = isset($strings[$key]) ? $strings[$key] : $key;
            foreach ($vars as $name => $value) {
                $text = str_replace(':' . $name, (string) $value, $text);
            }

            return $text;
        }
    }
}
