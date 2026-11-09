<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

$layer = static fn (string $name, string $pattern): Layer => Layer::withName($name)
    ->collectors(DirectoryConfig::create('src/' . $pattern));

$loggedException = $layer('LoggedException', 'LoggedException/.*');
$entry = $layer('Entry', 'Entry/.*');
$reading = $layer('Reading', 'Reading/.*');
$format = $layer('Format', 'Format/[^/]+[.]php$');
$lineFormat = $layer('Format\Line', 'Format/Line/.*');
$jsonFormat = $layer('Format\Json', 'Format/Json/.*');
$facade = $layer('Facade', '[^/]+[.]php$');

$layers = [$loggedException, $entry, $reading, $format, $lineFormat, $jsonFormat, $facade];
$rules = [
    [$loggedException],
    [$entry, $loggedException],
    [$reading],
    [$format, $entry, $reading],
    [$lineFormat, $format, $entry, $loggedException, $reading],
    [$jsonFormat, $format, $entry, $loggedException, $reading],
    [$facade, ...array_filter($layers, static fn (Layer $candidate): bool => $candidate !== $facade)],
];

return static function (DeptracConfig $config) use ($layers, $rules): void {
    $config
        ->paths('src')
        ->cacheFile('var/deptrac.cache')
        ->layers(...$layers)
        ->rulesets(...array_map(
            static fn (array $rule): Ruleset => Ruleset::forLayer(array_shift($rule))->accesses(...$rule),
            $rules,
        ));
};
