<?php

declare(strict_types=1);

namespace App\I18n;

use Twig\Environment;
use Twig\TwigFunction;
use WeakMap;

final class TwigTranslation
{
    /** @var WeakMap<Environment, bool>|null */
    private static ?WeakMap $registered = null;

    private function __construct()
    {
    }

    public static function register(Environment $environment, Translator $translator): void
    {
        self::$registered ??= new WeakMap();
        if (isset(self::$registered[$environment])) {
            return;
        }

        $environment->addFunction(new TwigFunction(
            't',
            $translator->trans(...)
        ));
        $environment->addFunction(new TwigFunction(
            'current_locale',
            $translator->locale(...)
        ));
        $environment->addFunction(new TwigFunction(
            'supported_locales',
            $translator->supportedLocales(...)
        ));
        self::$registered[$environment] = true;
    }
}
