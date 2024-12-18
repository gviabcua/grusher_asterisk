<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitc713da2a8112d5d1dcd2c891b2df1cdc
{
    public static $prefixLengthsPsr4 = array (
        'W' => 
        array (
            'Workerman\\' => 10,
        ),
        'A' => 
        array (
            'AMIListener\\' => 12,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Workerman\\' => 
        array (
            0 => __DIR__ . '/..' . '/workerman/workerman',
        ),
        'AMIListener\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitc713da2a8112d5d1dcd2c891b2df1cdc::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitc713da2a8112d5d1dcd2c891b2df1cdc::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitc713da2a8112d5d1dcd2c891b2df1cdc::$classMap;

        }, null, ClassLoader::class);
    }
}
