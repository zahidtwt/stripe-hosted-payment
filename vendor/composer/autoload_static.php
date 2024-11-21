<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit18fcb712fdd583eea49265ff7e90c42f
{
    public static $files = array (
        '256558b1ddf2fa4366ea7d7602798dd1' => __DIR__ . '/..' . '/yahnis-elsts/plugin-update-checker/load-v5p5.php',
    );

    public static $prefixLengthsPsr4 = array (
        'S' => 
        array (
            'Stripe\\' => 7,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Stripe\\' => 
        array (
            0 => __DIR__ . '/..' . '/stripe/stripe-php/lib',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit18fcb712fdd583eea49265ff7e90c42f::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit18fcb712fdd583eea49265ff7e90c42f::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit18fcb712fdd583eea49265ff7e90c42f::$classMap;

        }, null, ClassLoader::class);
    }
}