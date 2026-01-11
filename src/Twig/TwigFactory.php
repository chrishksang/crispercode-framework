<?php

declare(strict_types=1);

namespace CrisperCode\Twig;

use CrisperCode\Config\FrameworkConfig;
use Slim\Views\Twig;
use Twig\Extra\Intl\IntlExtension;

class TwigFactory
{
    public static function create(FrameworkConfig $config): Twig
    {
        $cache = false;
        if ($config->isProduction()) {
            $cache = $config->getCachePath();
        }

        $twig = Twig::create($config->getTemplatesPath(), ['cache' => $cache]);
        $twig->addExtension(new IntlExtension());
        $twig->addExtension(new DateExtension());
        $twig->addExtension(new AssetVersionExtension(
            $config->getStaticPath(),
            $config->getVendorPath()
        ));

        $twig->getEnvironment()->addGlobal('app_environment', $config->getEnvironment());

        return $twig;
    }
}
